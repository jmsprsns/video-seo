<?php
/**
 * Database tables: one row per video, and which posts each video is on.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Table install and queries.
 */
final class Video_SEO_DB {

	/**
	 * Schema version. Bump when the tables change.
	 */
	const VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'video_seo_db_version';

	/**
	 * Most posts listed per video in the report.
	 */
	const POSTS_PER_VIDEO = 3;

	/**
	 * Videos table name.
	 *
	 * @return string
	 */
	public static function videos() {
		global $wpdb;

		return $wpdb->prefix . 'vseo_videos';
	}

	/**
	 * Post-to-video table name.
	 *
	 * @return string
	 */
	public static function links() {
		global $wpdb;

		return $wpdb->prefix . 'vseo_post_videos';
	}

	/**
	 * Create or update the tables when the stored version is behind.
	 *
	 * Runs on every load (one cached option read), so sites that update
	 * through GitHub, or multisite sites, get the tables without re-activating.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Create the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$videos  = self::videos();
		$links   = self::links();

		dbDelta(
			"CREATE TABLE {$videos} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				video_key varchar(191) NOT NULL,
				provider varchar(20) NOT NULL DEFAULT '',
				provider_id varchar(64) NOT NULL DEFAULT '',
				url text NOT NULL,
				title text NULL,
				description mediumtext NULL,
				thumbnail text NULL,
				duration int(10) unsigned NOT NULL DEFAULT 0,
				upload_date datetime NULL,
				width int(10) unsigned NOT NULL DEFAULT 0,
				height int(10) unsigned NOT NULL DEFAULT 0,
				views bigint(20) unsigned NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				state varchar(20) NOT NULL DEFAULT 'pending',
				missing varchar(100) NOT NULL DEFAULT '',
				source varchar(20) NOT NULL DEFAULT '',
				error varchar(255) NOT NULL DEFAULT '',
				extra longtext NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_try datetime NULL,
				fetched_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY video_key (video_key),
				KEY status (status),
				KEY state (state),
				KEY provider (provider)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$links} (
				post_id bigint(20) unsigned NOT NULL,
				video_id bigint(20) unsigned NOT NULL,
				position smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (post_id,video_id),
				KEY video_id (video_id)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Drop the tables (uninstall only).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::videos() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table name.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::links() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table name.
		delete_option( self::VERSION_OPTION );
	}

	// Videos ----------------------------------------------------------------------

	/**
	 * Turn a raw row into a typed array.
	 *
	 * @param array|object|null $row Database row.
	 * @return array|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}

		$row = (array) $row;

		foreach ( array( 'id', 'duration', 'width', 'height', 'attempts' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}

		$row['views'] = null === $row['views'] ? null : (int) $row['views'];
		$extra        = is_string( $row['extra'] ) ? json_decode( $row['extra'], true ) : null;
		$row['extra'] = is_array( $extra ) ? $extra : array();

		return $row;
	}

	/**
	 * A video by its key.
	 *
	 * @param string $key Video key.
	 * @return array|null
	 */
	public static function get_by_key( $key ) {
		global $wpdb;

		$table = self::videos();

		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE video_key = %s", $key ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table name; live data.
	}

	/**
	 * Videos by their keys, keyed by video key.
	 *
	 * @param string[] $keys Video keys.
	 * @return array<string,array>
	 */
	public static function get_by_keys( array $keys ) {
		global $wpdb;

		$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );

		if ( ! $keys ) {
			return array();
		}

		$table = self::videos();
		$marks = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE video_key IN ({$marks})", $keys ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Placeholders built above.
		$out   = array();

		foreach ( (array) $rows as $row ) {
			$row                      = self::hydrate( $row );
			$out[ $row['video_key'] ] = $row;
		}

		return $out;
	}

	/**
	 * Videos by ID.
	 *
	 * @param int[] $ids IDs.
	 * @return array<int,array>
	 */
	public static function get_by_ids( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		$table = self::videos();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Integers only.
		$out   = array();

		foreach ( (array) $rows as $row ) {
			$row               = self::hydrate( $row );
			$out[ $row['id'] ] = $row;
		}

		return $out;
	}

	/**
	 * Make sure a row exists for a detected video. New rows wait for their
	 * details to be fetched.
	 *
	 * @param array $ref Detected video ({@see Video_SEO_Detector::find()}).
	 * @return int Row ID, or 0 on failure.
	 */
	public static function ensure( array $ref ) {
		global $wpdb;

		$existing = self::get_by_key( $ref['key'] );

		if ( $existing ) {
			// Learn what an earlier sighting didn't have (a Vimeo private hash, a poster).
			$extra   = $existing['extra'];
			$changed = false;

			foreach ( array( 'hash', 'poster', 'attachment_id' ) as $field ) {
				if ( ! empty( $ref[ $field ] ) && empty( $extra[ $field ] ) ) {
					$extra[ $field ] = $ref[ $field ];
					$changed         = true;
				}
			}

			// What was learned can change the details (a private video's hash
			// lets Vimeo answer; a poster gives a file video its thumbnail).
			if ( $changed ) {
				self::update(
					$existing['id'],
					array(
						'extra'    => $extra,
						'status'   => 'pending',
						'attempts' => 0,
						'next_try' => null,
					)
				);
			}

			return $existing['id'];
		}

		$extra = array();

		foreach ( array( 'hash', 'poster', 'attachment_id' ) as $field ) {
			if ( ! empty( $ref[ $field ] ) ) {
				$extra[ $field ] = $ref[ $field ];
			}
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			self::videos(),
			array(
				'video_key'   => $ref['key'],
				'provider'    => $ref['provider'],
				'provider_id' => $ref['id'],
				'url'         => $ref['url'],
				'status'      => 'pending',
				'state'       => 'pending',
				'extra'       => wp_json_encode( $extra ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			return (int) $wpdb->insert_id;
		}

		// Another request added it a moment ago.
		$row = self::get_by_key( $ref['key'] );

		return $row ? $row['id'] : 0;
	}

	/**
	 * Update a video row.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Columns to change. "extra" may be an array.
	 * @return void
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;

		if ( isset( $fields['extra'] ) && is_array( $fields['extra'] ) ) {
			$fields['extra'] = wp_json_encode( $fields['extra'] );
		}

		$allowed = array( 'url', 'title', 'description', 'thumbnail', 'duration', 'upload_date', 'width', 'height', 'views', 'status', 'state', 'missing', 'source', 'error', 'extra', 'attempts', 'next_try', 'fetched_at' );
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );

		if ( ! $fields ) {
			return;
		}

		$wpdb->update( self::videos(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Videos waiting for details, oldest first.
	 *
	 * @param string $provider Provider.
	 * @param int    $limit    Most rows.
	 * @return array[]
	 */
	public static function due( $provider, $limit ) {
		global $wpdb;

		$table = self::videos();
		$now   = current_time( 'mysql', true );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table, live data.
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE provider = %s AND status IN ('pending','error') AND ( next_try IS NULL OR next_try <= %s ) ORDER BY attempts ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
				$provider,
				$now,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Whether any video is waiting for details now.
	 *
	 * @return int How many.
	 */
	public static function count_due() {
		global $wpdb;

		$table = self::videos();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','error') AND ( next_try IS NULL OR next_try <= %s )", current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table name.
	}

	/**
	 * When the next failed video is due for another try.
	 *
	 * @return int Unix time, or 0 when nothing is waiting.
	 */
	public static function next_retry() {
		global $wpdb;

		$table = self::videos();
		$next  = $wpdb->get_var( "SELECT MIN(next_try) FROM {$table} WHERE status = 'error' AND next_try IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table name.

		return $next ? (int) strtotime( $next . ' UTC' ) : 0;
	}

	/**
	 * Mark YouTube videos that only have oEmbed details (no API key at the
	 * time) for a fetch through the API.
	 *
	 * @return int Rows marked.
	 */
	public static function mark_oembed_youtube() {
		global $wpdb;

		$table = self::videos();

		return (int) $wpdb->query( "UPDATE {$table} SET status = 'pending', attempts = 0, next_try = NULL WHERE provider = 'youtube' AND source = 'oembed' AND status = 'ok'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table name.
	}

	/**
	 * Mark videos for a fresh fetch.
	 *
	 * @param int[]  $ids      IDs, or empty for every video of $provider.
	 * @param string $provider Provider filter when $ids is empty.
	 * @param string $older    Only rows fetched before this GMT date ('' = all).
	 * @return int Rows marked.
	 */
	public static function mark_for_refresh( array $ids = array(), $provider = '', $older = '' ) {
		global $wpdb;

		$table = self::videos();
		$ids   = array_filter( array_map( 'absint', $ids ) );
		$where = array( "status NOT IN ('pending')" );
		$args  = array();

		if ( $ids ) {
			$where[] = 'id IN (' . implode( ',', $ids ) . ')';
		} elseif ( '' !== $provider ) {
			$where[] = 'provider = %s';
			$args[]  = $provider;
		}

		if ( '' !== $older ) {
			$where[] = 'fetched_at < %s';
			$args[]  = $older;
		}

		$sql = "UPDATE {$table} SET status = 'pending', attempts = 0, next_try = NULL WHERE " . implode( ' AND ', $where );

		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built from fixed parts above.
		}

		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above when it has values.
	}

	/**
	 * Delete videos no post uses any more (after a grace day, so a video
	 * moved between posts keeps its details).
	 *
	 * @return int Rows deleted.
	 */
	public static function delete_orphans() {
		global $wpdb;

		$videos = self::videos();
		$links  = self::links();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE v FROM {$videos} v LEFT JOIN {$links} l ON l.video_id = v.id WHERE l.video_id IS NULL AND v.created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table names.
	}

	// Links -------------------------------------------------------------------------

	/**
	 * Replace the list of videos on a post.
	 *
	 * @param int   $post_id   Post ID.
	 * @param int[] $video_ids Video IDs in page order.
	 * @return bool Whether the list changed.
	 */
	public static function set_post_videos( $post_id, array $video_ids ) {
		global $wpdb;

		$post_id   = (int) $post_id;
		$video_ids = array_values( array_unique( array_filter( array_map( 'absint', $video_ids ) ) ) );
		$before    = self::post_video_ids( $post_id );

		if ( $before === $video_ids ) {
			return false;
		}

		$links = self::links();
		$wpdb->delete( $links, array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		if ( $video_ids ) {
			$values = array();

			foreach ( $video_ids as $position => $video_id ) {
				$values[] = $wpdb->prepare( '(%d,%d,%d)', $post_id, $video_id, $position );
			}

			$wpdb->query( "INSERT IGNORE INTO {$links} (post_id, video_id, position) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Each row prepared above.
		}

		return true;
	}

	/**
	 * Video IDs on a post, in page order.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function post_video_ids( $post_id ) {
		global $wpdb;

		$links = self::links();

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT video_id FROM {$links} WHERE post_id = %d ORDER BY position ASC", $post_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table name.
	}

	/**
	 * Videos on a post, in page order.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public static function post_videos( $post_id ) {
		global $wpdb;

		$videos = self::videos();
		$links  = self::links();
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT v.* FROM {$links} l INNER JOIN {$videos} v ON v.id = l.video_id WHERE l.post_id = %d ORDER BY l.position ASC", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table names.

		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Forget a post's videos.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete_post( $post_id ) {
		global $wpdb;

		$wpdb->delete( self::links(), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Posts a set of videos are on (published ones first), a few per video.
	 *
	 * @param int[] $video_ids Video IDs.
	 * @return array<int,array{posts:int[],total:int}>
	 */
	public static function posts_for_videos( array $video_ids ) {
		global $wpdb;

		$video_ids = array_values( array_unique( array_filter( array_map( 'absint', $video_ids ) ) ) );
		$out       = array();

		if ( ! $video_ids ) {
			return $out;
		}

		$links = self::links();
		$rows  = $wpdb->get_results( "SELECT l.video_id, l.post_id FROM {$links} l INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id WHERE l.video_id IN (" . implode( ',', $video_ids ) . ") ORDER BY l.video_id, p.post_status = 'publish' DESC, p.post_date DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Integers only.

		foreach ( (array) $rows as $row ) {
			$video = (int) $row['video_id'];

			if ( ! isset( $out[ $video ] ) ) {
				$out[ $video ] = array(
					'posts' => array(),
					'total' => 0,
				);
			}

			++$out[ $video ]['total'];

			if ( count( $out[ $video ]['posts'] ) < self::POSTS_PER_VIDEO ) {
				$out[ $video ]['posts'][] = (int) $row['post_id'];
			}
		}

		return $out;
	}

	// Report --------------------------------------------------------------------------

	/**
	 * WHERE clause for the report's filters.
	 *
	 * @param array $filters view, provider, search.
	 * @return string SQL (already prepared), starting with " WHERE".
	 */
	private static function report_where( array $filters ) {
		global $wpdb;

		$links = self::links();
		$where = array( "EXISTS (SELECT 1 FROM {$links} l WHERE l.video_id = v.id)" );
		$view  = isset( $filters['view'] ) ? $filters['view'] : '';

		switch ( $view ) {
			case 'ready':
				$where[] = "v.state = 'ready'";
				break;
			case 'incomplete':
				$where[] = "v.state = 'incomplete'";
				break;
			case 'attention':
				$where[] = "v.state IN ('unavailable','error')";
				break;
			case 'pending':
				$where[] = "v.state = 'pending'";
				break;
		}

		if ( ! empty( $filters['provider'] ) ) {
			$where[] = $wpdb->prepare( 'v.provider = %s', $filters['provider'] );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( '( v.title LIKE %s OR v.provider_id LIKE %s OR v.url LIKE %s )', $like, $like, $like );
		}

		return ' WHERE ' . implode( ' AND ', $where );
	}

	/**
	 * Videos for the report.
	 *
	 * @param array  $filters  view, provider, search.
	 * @param string $orderby  title|duration|upload_date|posts.
	 * @param string $order    asc|desc.
	 * @param int    $per_page Rows per page.
	 * @param int    $page     Page number.
	 * @return array[]
	 */
	public static function report( array $filters, $orderby, $order, $per_page, $page ) {
		global $wpdb;

		$videos  = self::videos();
		$links   = self::links();
		$columns = array(
			'title'       => 'v.title',
			'duration'    => 'v.duration',
			'upload_date' => 'v.upload_date',
			'posts'       => 'post_count',
			'id'          => 'v.id',
		);
		$by      = isset( $columns[ $orderby ] ) ? $columns[ $orderby ] : 'v.id';
		$dir     = 'asc' === $order ? 'ASC' : 'DESC';
		$offset  = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		$sql = "SELECT v.*, (SELECT COUNT(*) FROM {$links} c WHERE c.video_id = v.id) AS post_count FROM {$videos} v" . self::report_where( $filters ) . " ORDER BY {$by} {$dir}, v.id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, (int) $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Built from fixed parts and prepared values.

		$out = array();

		foreach ( (array) $rows as $row ) {
			$count             = (int) $row['post_count'];
			$row               = self::hydrate( $row );
			$row['post_count'] = $count;
			$out[]             = $row;
		}

		return $out;
	}

	/**
	 * How many videos match the report's filters.
	 *
	 * @param array $filters view, provider, search.
	 * @return int
	 */
	public static function report_count( array $filters ) {
		global $wpdb;

		$videos = self::videos();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$videos} v" . self::report_where( $filters ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Built from fixed parts and prepared values.
	}

	/**
	 * Counts for the report's stats and views.
	 *
	 * @return array<string,int>
	 */
	public static function stats() {
		global $wpdb;

		$videos = self::videos();
		$links  = self::links();
		$counts = array(
			'total'       => 0,
			'ready'       => 0,
			'incomplete'  => 0,
			'attention'   => 0,
			'pending'     => 0,
			'youtube'     => 0,
			'vimeo'       => 0,
			'file'        => 0,
			'posts'       => 0,
			'flagged'     => 0,
			'with_issues' => 0,
		);

		$rows = $wpdb->get_results( "SELECT v.state, v.provider, COUNT(*) AS n FROM {$videos} v WHERE EXISTS (SELECT 1 FROM {$links} l WHERE l.video_id = v.id) GROUP BY v.state, v.provider", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table names.

		foreach ( (array) $rows as $row ) {
			$n                 = (int) $row['n'];
			$counts['total']  += $n;
			$state             = in_array( $row['state'], array( 'unavailable', 'error' ), true ) ? 'attention' : $row['state'];
			$counts[ $state ]  = ( isset( $counts[ $state ] ) ? $counts[ $state ] : 0 ) + $n;

			if ( isset( $counts[ $row['provider'] ] ) ) {
				$counts[ $row['provider'] ] += $n;
			}
		}

		$counts['posts']   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT l.post_id) FROM {$links} l INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id WHERE p.post_status = 'publish'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table names.
		$counts['flagged'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT l.post_id) FROM {$links} l INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id INNER JOIN {$wpdb->postmeta} m ON m.post_id = l.post_id AND m.meta_key = %s WHERE p.post_status = 'publish'", Video_SEO_Scanner::FLAG_META ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Fixed table names.

		return $counts;
	}

	// Pages ----------------------------------------------------------------------------

	/**
	 * Posts that have videos, for the Pages tab.
	 *
	 * @param array $filters  view (all|flagged), search.
	 * @param int   $per_page Rows per page.
	 * @param int   $page     Page number.
	 * @return array{rows:array[],total:int}
	 */
	public static function pages( array $filters, $per_page, $page ) {
		global $wpdb;

		$links  = self::links();
		$join   = '';
		$where  = array( "p.post_status IN ('publish','future','private','draft','pending')" );
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		if ( isset( $filters['view'] ) && 'flagged' === $filters['view'] ) {
			$join = $wpdb->prepare( " INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s", Video_SEO_Scanner::FLAG_META );
		}

		if ( ! empty( $filters['search'] ) ) {
			$where[] = $wpdb->prepare( 'p.post_title LIKE %s', '%' . $wpdb->esc_like( $filters['search'] ) . '%' );
		}

		$from  = "FROM {$wpdb->posts} p INNER JOIN (SELECT post_id, COUNT(*) AS videos FROM {$links} GROUP BY post_id) l ON l.post_id = p.ID{$join} WHERE " . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$from}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Built from fixed parts and prepared values.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, l.videos {$from} ORDER BY p.post_status = 'publish' DESC, p.post_date DESC LIMIT %d OFFSET %d", (int) $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Built from fixed parts and prepared values.

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Published posts with videos, for the sitemap, in ID order.
	 *
	 * @param string[] $post_types Post types.
	 * @param int      $limit      Most posts.
	 * @param int      $offset     Offset.
	 * @return int[]
	 */
	public static function sitemap_posts( array $post_types, $limit, $offset ) {
		global $wpdb;

		if ( ! $post_types ) {
			return array();
		}

		$links = self::links();
		$marks = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args  = array_merge( $post_types, array( (int) $limit, (int) $offset ) );

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$links} l ON l.post_id = p.ID WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ({$marks}) ORDER BY p.ID ASC LIMIT %d OFFSET %d", $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Placeholders built above.
	}

	/**
	 * How many published posts with videos the sitemap could list.
	 *
	 * @param string[] $post_types Post types.
	 * @return int
	 */
	public static function sitemap_post_count( array $post_types ) {
		global $wpdb;

		if ( ! $post_types ) {
			return 0;
		}

		$links = self::links();
		$marks = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$links} l ON l.post_id = p.ID WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ({$marks})", $post_types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- Placeholders built above.
	}
}
