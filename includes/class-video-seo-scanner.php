<?php
/**
 * Keeps the video index current: scans each post when it's saved, runs the
 * one-time scan of existing content in the background, and fetches video
 * details through WP-Cron.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanning and background jobs.
 */
final class Video_SEO_Scanner {

	/**
	 * Post meta: scan summary {sig, words, before, count}. Only posts that
	 * have (or had) videos carry it.
	 */
	const SCAN_META = '_vseo_scan';

	/**
	 * Post meta set on pages whose video likely isn't the main content.
	 */
	const FLAG_META = '_vseo_flag';

	/**
	 * Option holding the full scan's progress.
	 */
	const STATE_OPTION = 'video_seo_scan_state';

	/**
	 * Posts per scan batch.
	 */
	const BATCH = 50;

	/**
	 * Cron hooks.
	 */
	const HOOK_SCAN  = 'video_seo_scan_batch';
	const HOOK_FETCH = 'video_seo_fetch';
	const HOOK_DAILY = 'video_seo_daily';

	/**
	 * Posts saved during this request, scanned once at shutdown.
	 *
	 * @var array<int,bool>
	 */
	private static $queue = array();

	/**
	 * Every cron hook the plugin uses.
	 *
	 * @return string[]
	 */
	public static function cron_hooks() {
		return array( self::HOOK_SCAN, self::HOOK_FETCH, self::HOOK_DAILY );
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'queue_post' ), 20 );
		add_action( 'untrashed_post', array( __CLASS__, 'queue_post' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'trashed_post', array( __CLASS__, 'forget' ) );
		add_action( 'deleted_post', array( __CLASS__, 'forget' ) );
		add_action( 'shutdown', array( __CLASS__, 'process_queue' ), 1 );

		add_action( self::HOOK_SCAN, array( __CLASS__, 'cron_scan' ) );
		add_action( self::HOOK_FETCH, array( __CLASS__, 'cron_fetch' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'cron_daily' ) );

		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY );
		}
	}

	// Saving ---------------------------------------------------------------------------

	/**
	 * Remember a saved post, to scan at the end of the request (after the
	 * editor and page builders have saved their meta too).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function queue_post( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id > 0 && ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ) ) {
			self::$queue[ $post_id ] = true;
		}
	}

	/**
	 * A page builder saved its layout: scan that post again.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	public static function meta_changed( $meta_id, $post_id, $meta_key ) {
		if ( in_array( $meta_key, Video_SEO_Detector::builder_meta_keys(), true ) ) {
			self::queue_post( $post_id );
		}
	}

	/**
	 * Scan the posts saved during this request.
	 *
	 * @return void
	 */
	public static function process_queue() {
		$queue       = array_keys( self::$queue );
		self::$queue = array();

		foreach ( $queue as $post_id ) {
			self::scan_post( $post_id );
		}
	}

	/**
	 * Forget a deleted or trashed post's videos.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function forget( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! get_post_meta( $post_id, self::SCAN_META, true ) && ! Video_SEO_DB::post_video_ids( $post_id ) ) {
			return;
		}

		Video_SEO_DB::delete_post( $post_id );
		delete_post_meta( $post_id, self::SCAN_META );
		delete_post_meta( $post_id, self::FLAG_META );
		unset( self::$queue[ $post_id ] );
		Video_SEO_Sitemap::bump();
	}

	// Scanning ---------------------------------------------------------------------------

	/**
	 * Whether a post's content or builder data mentions a video at all. A cheap
	 * check that lets pages without videos skip the database entirely.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function maybe_has_videos( WP_Post $post ) {
		$pattern = '/youtu|vimeo|\.(?:' . Video_SEO_Detector::FILE_TYPES . ')\b/i';

		if ( preg_match( $pattern, $post->post_content ) ) {
			return true;
		}

		foreach ( Video_SEO_Detector::builder_meta_keys() as $meta_key ) {
			$value = get_post_meta( $post->ID, $meta_key, true );

			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}

			if ( is_string( $value ) && '' !== $value && preg_match( $pattern, $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fingerprint of what a scan saw. Changes when the post is edited or the
	 * detector improves.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function signature( WP_Post $post ) {
		return md5( $post->post_modified_gmt . '|' . Video_SEO_Detector::VERSION . '|' . strlen( $post->post_content ) );
	}

	/**
	 * Scan a post now if it was never scanned or changed since (for pages
	 * viewed before the background scan reached them).
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function ensure_scanned( WP_Post $post ) {
		$scan = get_post_meta( $post->ID, self::SCAN_META, true );

		if ( ! is_array( $scan ) || ! isset( $scan['sig'] ) || self::signature( $post ) !== $scan['sig'] ) {
			self::scan_post( $post->ID );
		}
	}

	/**
	 * Scan one post and update its videos.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Scan summary, or null when the post isn't scanned.
	 */
	public static function scan_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! self::is_scannable( $post ) ) {
			if ( $post ) {
				self::forget( $post->ID );
			}
			return null;
		}

		$had    = is_array( get_post_meta( $post->ID, self::SCAN_META, true ) );
		$result = self::maybe_has_videos( $post ) ? Video_SEO_Detector::scan_post( $post ) : array(
			'videos' => array(),
			'words'  => 0,
			'before' => -1,
		);

		// Nothing here now, nothing before: no database work at all.
		if ( ! $result['videos'] && ! $had ) {
			return array(
				'count' => 0,
			);
		}

		$ids = array();

		foreach ( $result['videos'] as $ref ) {
			$id = Video_SEO_DB::ensure( $ref );

			if ( $id ) {
				$ids[] = $id;
			}
		}

		$changed = Video_SEO_DB::set_post_videos( $post->ID, $ids );

		if ( ! $ids ) {
			delete_post_meta( $post->ID, self::SCAN_META );
			delete_post_meta( $post->ID, self::FLAG_META );
		} else {
			$summary = array(
				'sig'    => self::signature( $post ),
				'words'  => (int) $result['words'],
				'before' => (int) $result['before'],
				'count'  => count( $ids ),
			);

			update_post_meta( $post->ID, self::SCAN_META, $summary );

			$check = Video_SEO_Video::focus_check( $summary );

			if ( $check['flag'] ) {
				update_post_meta( $post->ID, self::FLAG_META, 1 );
			} else {
				delete_post_meta( $post->ID, self::FLAG_META );
			}

			self::schedule_fetch();
		}

		if ( $changed || 'publish' === $post->post_status ) {
			Video_SEO_Sitemap::bump();
		}

		return array(
			'count' => count( $ids ),
		);
	}

	/**
	 * Whether a post is one the plugin looks at.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_scannable( WP_Post $post ) {
		return in_array( $post->post_type, Video_SEO_Plugin::instance()->post_types(), true )
			&& in_array( $post->post_status, self::statuses(), true );
	}

	/**
	 * Post statuses that are scanned.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'publish', 'future', 'private', 'draft', 'pending' );
	}

	// Full scan ----------------------------------------------------------------------------

	/**
	 * Current full-scan progress.
	 *
	 * @return array{status:string,last_id:int,done:int,total:int,started:int,finished:int}
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'status'   => 'idle',
				'last_id'  => 0,
				'done'     => 0,
				'total'    => 0,
				'started'  => 0,
				'finished' => 0,
			)
		);
	}

	/**
	 * Start scanning all content from the beginning.
	 *
	 * @return void
	 */
	public static function start_full_scan() {
		update_option(
			self::STATE_OPTION,
			array(
				'status'   => 'running',
				'last_id'  => 0,
				'done'     => 0,
				'total'    => self::count_posts(),
				'started'  => time(),
				'finished' => 0,
			),
			false
		);

		wp_clear_scheduled_hook( self::HOOK_SCAN );
		wp_schedule_single_event( time(), self::HOOK_SCAN );
	}

	/**
	 * Scan the next batch of posts.
	 *
	 * @param float $budget Seconds to spend.
	 * @return array Progress.
	 */
	public static function run_batch( $budget = 10.0 ) {
		global $wpdb;

		$state = self::get_state();

		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		// One runner at a time (cron and an open report page can overlap).
		if ( get_transient( 'video_seo_scan_lock' ) ) {
			return $state;
		}

		set_transient( 'video_seo_scan_lock', 1, 60 );

		$start = microtime( true );
		$types = Video_SEO_Plugin::instance()->post_types();

		while ( microtime( true ) - $start < $budget ) {
			$ids = array();

			if ( $types ) {
				$type_marks   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
				$status_marks = implode( ',', array_fill( 0, count( self::statuses() ), '%s' ) );
				$ids          = array_map(
					'intval',
					(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Batched ID walk.
						$wpdb->prepare(
							"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$type_marks}) AND post_status IN ({$status_marks}) ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built above.
							array_merge( array( (int) $state['last_id'] ), $types, self::statuses(), array( self::BATCH ) )
						)
					)
				);
			}

			if ( ! $ids ) {
				$state['status']   = 'done';
				$state['finished'] = time();
				break;
			}

			foreach ( $ids as $id ) {
				self::scan_post( $id );
				$state['last_id'] = $id;
				++$state['done'];

				if ( microtime( true ) - $start >= $budget ) {
					break;
				}
			}

			// Keep the object cache from growing over a long scan.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		$state['total'] = max( $state['total'], $state['done'] );
		update_option( self::STATE_OPTION, $state, false );
		delete_transient( 'video_seo_scan_lock' );

		if ( 'running' === $state['status'] && ! wp_next_scheduled( self::HOOK_SCAN ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK_SCAN );
		}

		if ( 'done' === $state['status'] ) {
			Video_SEO_Sitemap::bump();
			self::schedule_fetch();
		}

		return $state;
	}

	/**
	 * How many posts a full scan covers.
	 *
	 * @return int
	 */
	private static function count_posts() {
		global $wpdb;

		$types = Video_SEO_Plugin::instance()->post_types();

		if ( ! $types ) {
			return 0;
		}

		$type_marks   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_marks = implode( ',', array_fill( 0, count( self::statuses() ), '%s' ) );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Count for a progress bar.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_marks}) AND post_status IN ({$status_marks})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built above.
				array_merge( $types, self::statuses() )
			)
		);
	}

	// Cron ------------------------------------------------------------------------------------

	/**
	 * Cron: scan a batch.
	 *
	 * @return void
	 */
	public static function cron_scan() {
		self::run_batch( 20.0 );
	}

	/**
	 * Fetch details soon, unless that's already scheduled.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public static function schedule_fetch( $delay = 0 ) {
		if ( ! wp_next_scheduled( self::HOOK_FETCH ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::HOOK_FETCH );
		}
	}

	/**
	 * Cron: fetch details, then come back while any are due.
	 *
	 * @return void
	 */
	public static function cron_fetch() {
		Video_SEO_Fetcher::process( 20 );

		if ( Video_SEO_DB::count_due() > 0 ) {
			self::schedule_fetch( 30 );
			return;
		}

		$next = Video_SEO_DB::next_retry();

		if ( $next ) {
			self::schedule_fetch( max( 60, $next - time() ) );
		}
	}

	/**
	 * Cron: daily upkeep. Refresh details older than 30 days (titles and view
	 * counts change), upgrade oEmbed details once an API key works, and drop
	 * videos no page uses any more.
	 *
	 * @return void
	 */
	public static function cron_daily() {
		Video_SEO_DB::mark_for_refresh( array(), '', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );

		$api    = get_option( Video_SEO_Fetcher::API_STATUS_OPTION, array() );
		$api_ok = ! is_array( $api ) || ! isset( $api['ok'] ) || $api['ok'] || ( isset( $api['code'] ) && 'quota' === $api['code'] );

		if ( '' !== Video_SEO_Fetcher::youtube_key() && $api_ok ) {
			Video_SEO_DB::mark_oembed_youtube();
		}

		Video_SEO_DB::delete_orphans();
		self::schedule_fetch();
	}
}
