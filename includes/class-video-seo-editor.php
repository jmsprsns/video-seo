<?php
/**
 * The editor's Video SEO panel (block editor sidebar, or a box in the
 * classic editor): the videos on the page, their details and schema status,
 * the main-content check, and per-page changes to title, description,
 * thumbnail and transcript.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor panel and its REST routes.
 */
final class Video_SEO_Editor {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'video-seo/v1';

	/**
	 * Longest content accepted for a check (bytes).
	 */
	const MAX_CONTENT = 2097152;

	/**
	 * Videos fetched right away per check; the rest arrive in the background.
	 */
	const FETCH_NOW = 3;

	/**
	 * New videos a user without manage_options can look up per hour.
	 */
	const HOURLY_LOOKUPS = 60;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'classic_box' ), 10, 2 );
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/videos',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_videos' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'id'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'content' => array(
						'type' => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/changes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_changes' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'id'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'key'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'changes' => array(
						'type' => 'object',
					),
					'reset'   => array(
						'type' => 'boolean',
					),
					'content' => array(
						'type' => 'string',
					),
				),
			)
		);
	}

	/**
	 * Only people who can edit the post, for post types the plugin scans.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function can_edit( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You can\'t edit this post.', 'video-seo' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! in_array( $post->post_type, Video_SEO_Plugin::instance()->post_types(), true ) ) {
			return new WP_Error( 'video_seo_type', __( 'Video SEO doesn\'t scan this type of content.', 'video-seo' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * The videos in a post (or in the editor's unsaved content).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_videos( WP_REST_Request $request ) {
		$post    = get_post( (int) $request['id'] );
		$content = $request->get_param( 'content' );

		if ( is_string( $content ) && strlen( $content ) > self::MAX_CONTENT ) {
			return new WP_Error( 'video_seo_size', __( 'This content is too long to check.', 'video-seo' ), array( 'status' => 413 ) );
		}

		return rest_ensure_response( self::panel_data( $post, is_string( $content ) ? $content : null ) );
	}

	/**
	 * Save or reset one video's changes on this page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_changes( WP_REST_Request $request ) {
		$post    = get_post( (int) $request['id'] );
		$key     = (string) $request->get_param( 'key' );
		$content = $request->get_param( 'content' );

		if ( ! Video_SEO_Detector::is_key( $key ) ) {
			return new WP_Error( 'video_seo_key', __( 'Unknown video.', 'video-seo' ), array( 'status' => 400 ) );
		}

		if ( is_string( $content ) && strlen( $content ) > self::MAX_CONTENT ) {
			return new WP_Error( 'video_seo_size', __( 'This content is too long to check.', 'video-seo' ), array( 'status' => 413 ) );
		}

		$all     = Video_SEO_Video::overrides( $post->ID );
		$changes = $request->get_param( 'changes' );
		$clean   = $request->get_param( 'reset' ) || ! is_array( $changes ) ? array() : Video_SEO_Video::sanitize_override( $changes );

		if ( $clean ) {
			if ( ! isset( $all[ $key ] ) && count( $all ) >= Video_SEO_Video::MAX_OVERRIDES ) {
				return new WP_Error( 'video_seo_full', __( 'This page already has changes for too many videos.', 'video-seo' ), array( 'status' => 400 ) );
			}

			$all[ $key ] = $clean;
		} else {
			unset( $all[ $key ] );
		}

		if ( $all ) {
			// Post meta is unslashed on save: slash it so transcripts keep their backslashes.
			update_post_meta( $post->ID, Video_SEO_Video::OVERRIDES_META, wp_slash( $all ) );
		} else {
			delete_post_meta( $post->ID, Video_SEO_Video::OVERRIDES_META );
		}

		Video_SEO_Sitemap::bump();

		return rest_ensure_response( self::panel_data( $post, is_string( $content ) ? $content : null ) );
	}

	/**
	 * Everything the panel shows.
	 *
	 * @param WP_Post     $post    Post.
	 * @param string|null $content Unsaved content to check instead of the saved one.
	 * @return array
	 */
	public static function panel_data( WP_Post $post, $content = null ) {
		$scan  = Video_SEO_Detector::scan_post( $post, $content );
		$refs  = $scan['videos'];
		$keys  = wp_list_pluck( $refs, 'key' );
		$rows  = Video_SEO_DB::get_by_keys( $keys );
		$fresh = array();

		// Videos added in the editor and not saved yet: look them up.
		foreach ( $refs as $ref ) {
			if ( isset( $rows[ $ref['key'] ] ) ) {
				continue;
			}

			if ( ! self::allow_lookup() ) {
				break;
			}

			$id = Video_SEO_DB::ensure( $ref );

			if ( $id ) {
				$fresh[] = $ref['key'];
			}
		}

		if ( $fresh ) {
			$rows = Video_SEO_DB::get_by_keys( $keys );
		}

		// Fetch a few videos' details now, so the panel has something to show.
		$waiting = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['fetched_at'] ) && in_array( $row['status'], array( 'pending', 'error' ), true ) ) {
				$waiting[] = $row;
			}
		}

		if ( $waiting ) {
			Video_SEO_Fetcher::fetch_rows( array_slice( $waiting, 0, self::FETCH_NOW ), microtime( true ) + 8 );
			$rows = Video_SEO_DB::get_by_keys( $keys );
			Video_SEO_Scanner::schedule_fetch();
		}

		$overrides = Video_SEO_Video::overrides( $post->ID );
		$videos    = array();

		foreach ( $refs as $index => $ref ) {
			if ( ! isset( $rows[ $ref['key'] ] ) ) {
				continue;
			}

			$videos[] = self::panel_video( Video_SEO_Video::build( $rows[ $ref['key'] ], $post, $index, $overrides ), $rows[ $ref['key'] ] );
		}

		$check = Video_SEO_Video::focus_check(
			array(
				'words'  => $scan['words'],
				'before' => $scan['before'],
				'count'  => count( $refs ),
			)
		);

		$plugin = Video_SEO_Plugin::instance();

		return array(
			'videos'    => $videos,
			'check'     => $check,
			'found'     => count( $refs ),
			'apiKey'    => '' !== Video_SEO_Fetcher::youtube_key(),
			'schemaOn'  => (bool) $plugin->get_setting( 'schema' ),
			'published' => 'publish' === $post->post_status,
		);
	}

	/**
	 * One video for the panel.
	 *
	 * @param array $video Built video.
	 * @param array $row   Video row.
	 * @return array
	 */
	private static function panel_video( array $video, array $row ) {
		$labels = array(
			'ready'        => __( 'Ready for Google', 'video-seo' ),
			'incomplete'   => __( 'Ready, details missing', 'video-seo' ),
			'pending'      => __( 'Getting details…', 'video-seo' ),
			'error'        => __( 'Couldn\'t get details', 'video-seo' ),
			'unavailable'  => __( 'Unavailable', 'video-seo' ),
			'no_thumbnail' => __( 'Needs a thumbnail', 'video-seo' ),
			'excluded'     => __( 'Left out', 'video-seo' ),
		);

		$change      = $video['changes'];
		$thumb_id    = ! empty( $change['thumbnail_id'] ) ? (int) $change['thumbnail_id'] : 0;
		$thumb_small = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
		$clips       = array();

		foreach ( $video['clips'] as $clip ) {
			$clips[] = array(
				'time' => Video_SEO_Video::clock( $clip['start'] ),
				'name' => $clip['name'],
			);
		}

		return array(
			'key'           => $video['key'],
			'provider'      => $video['provider'],
			'providerLabel' => Video_SEO_Video::provider_label( $video['provider'] ),
			'title'         => $video['title'],
			'description'   => $video['description'],
			'thumbnail'     => $video['thumbnail'],
			'duration'      => $video['duration'] ? Video_SEO_Video::clock( $video['duration'] ) : '',
			'uploaded'      => ! empty( $row['upload_date'] ) ? mysql2date( get_option( 'date_format' ), get_date_from_gmt( $row['upload_date'] ) ) : '',
			'channel'       => $video['channel'],
			'status'        => $video['status'],
			'statusLabel'   => isset( $labels[ $video['status'] ] ) ? $labels[ $video['status'] ] : $video['status'],
			'missing'       => array_map( array( 'Video_SEO_Video', 'missing_label' ), $video['missing'] ),
			'error'         => $video['error'],
			'url'           => $video['watch_url'],
			'clips'         => $clips,
			'videoTitle'    => (string) $row['title'],
			'videoText'     => (string) $row['description'],
			'changes'       => array(
				'title'           => isset( $change['title'] ) ? (string) $change['title'] : '',
				'description'     => isset( $change['description'] ) ? (string) $change['description'] : '',
				'thumbnail_id'    => $thumb_id,
				'thumbnail_url'   => $thumb_small,
				'transcript'      => isset( $change['transcript'] ) ? (string) $change['transcript'] : '',
				'show_transcript' => ! empty( $change['show_transcript'] ),
				'exclude'         => ! empty( $change['exclude'] ),
			),
			'edited'        => ! empty( $change ),
		);
	}

	/**
	 * Whether this user may look up another new video (editors and authors
	 * get a generous hourly allowance; admins have no limit).
	 *
	 * @return bool
	 */
	private static function allow_lookup() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$key   = 'video_seo_lookups_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= self::HOURLY_LOOKUPS ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	// Assets ---------------------------------------------------------------------------------

	/**
	 * Data the panel script needs.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private static function script_data( WP_Post $post ) {
		return array(
			'postId'      => (int) $post->ID,
			'path'        => '/' . self::REST_NAMESPACE . '/posts/' . (int) $post->ID,
			'settingsUrl' => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=' . Video_SEO_Admin::PAGE . '&tab=settings' ) : '',
		);
	}

	/**
	 * Whether the panel belongs on this edit screen.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	private static function wants_panel( $post ) {
		return $post instanceof WP_Post
			&& in_array( $post->post_type, Video_SEO_Plugin::instance()->post_types(), true )
			&& current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Register the shared script and styles.
	 *
	 * @param bool $classic Whether this is the classic editor.
	 * @return void
	 */
	private static function enqueue( $classic ) {
		$deps = array( 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' );

		if ( ! $classic ) {
			$deps[] = 'wp-plugins';
			$deps[] = wp_script_is( 'wp-editor', 'registered' ) ? 'wp-editor' : 'wp-edit-post';
			$deps[] = 'wp-edit-post';
		}

		wp_enqueue_media();
		wp_enqueue_style( 'video-seo-editor', VIDEO_SEO_URL . 'assets/editor.css', array( 'wp-components' ), VIDEO_SEO_VERSION );
		wp_enqueue_script( 'video-seo-editor', VIDEO_SEO_URL . 'assets/editor.js', array_values( array_unique( $deps ) ), VIDEO_SEO_VERSION, true );
		wp_set_script_translations( 'video-seo-editor', 'video-seo' );
	}

	/**
	 * Block editor: the sidebar panel.
	 *
	 * @return void
	 */
	public static function block_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();

		if ( ! $screen || 'post' !== $screen->base || ! self::wants_panel( $post ) ) {
			return;
		}

		self::enqueue( false );
		wp_add_inline_script( 'video-seo-editor', 'window.videoSeoEditor = ' . wp_json_encode( array_merge( self::script_data( $post ), array( 'classic' => false ) ) ) . ';', 'before' );
	}

	/**
	 * Classic editor: a box beside the content.
	 *
	 * @param string       $post_type Post type.
	 * @param WP_Post|null $post      Post.
	 * @return void
	 */
	public static function classic_box( $post_type, $post = null ) {
		if ( ! self::wants_panel( $post ) ) {
			return;
		}

		// The block editor has the sidebar panel instead.
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}

		add_meta_box( 'video-seo', __( 'Video SEO', 'video-seo' ), array( __CLASS__, 'render_classic_box' ), null, 'side', 'default' );

		self::enqueue( true );
		wp_add_inline_script( 'video-seo-editor', 'window.videoSeoEditor = ' . wp_json_encode( array_merge( self::script_data( $post ), array( 'classic' => true ) ) ) . ';', 'before' );
	}

	/**
	 * The classic editor box's container (the script fills it).
	 *
	 * @return void
	 */
	public static function render_classic_box() {
		echo '<div id="video-seo-classic" class="vseo-panel vseo-panel--classic"><p class="vseo-muted">' . esc_html__( 'Loading videos…', 'video-seo' ) . '</p></div>';
	}
}
