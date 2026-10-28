<?php
/**
 * The video sitemap at /video-sitemap.xml (Google's video sitemap format).
 *
 * Lists every published, indexable page with videos. It's rebuilt when
 * videos are added, removed or updated, listed in robots.txt and added to
 * Yoast SEO's, Rank Math's, All in One SEO's and SEOPress's sitemap index.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Video sitemap.
 */
final class Video_SEO_Sitemap {

	/**
	 * Query var: "main" for /video-sitemap.xml, a page number for
	 * /video-sitemap-2.xml.
	 */
	const QUERY_VAR = 'vseo_sitemap';

	/**
	 * Option and value that say the rewrite rules include the sitemap.
	 */
	const REWRITE_OPTION  = 'video_seo_rewrite_version';
	const REWRITE_VERSION = '1';

	/**
	 * Option holding the time of the last change (cache key and lastmod).
	 */
	const CHANGED_OPTION = 'video_seo_sitemap_changed';

	/**
	 * Pages per sitemap file. Past this, /video-sitemap.xml becomes an index.
	 */
	const PER_FILE = 1000;

	/**
	 * Whether bump() already ran on this request.
	 *
	 * @var bool
	 */
	private static $bumped = false;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_trailing_slash' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 20, 2 );

		add_filter( 'wpseo_sitemap_index', array( __CLASS__, 'index_xml' ) );
		add_filter( 'rank_math/sitemap/index', array( __CLASS__, 'index_xml' ) );
		add_filter( 'seopress_sitemaps_xml_index_item', array( __CLASS__, 'index_xml' ) );
		add_filter( 'aioseo_sitemap_indexes', array( __CLASS__, 'aioseo_index' ) );
	}

	/**
	 * Whether the sitemap is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Video_SEO_Plugin::instance()->get_setting( 'sitemap' );
	}

	/**
	 * The sitemap's address.
	 *
	 * @param int $file File number (0 = the main sitemap).
	 * @return string
	 */
	public static function url( $file = 0 ) {
		$file = (int) $file;

		if ( get_option( 'permalink_structure' ) ) {
			return home_url( $file ? '/video-sitemap-' . $file . '.xml' : '/video-sitemap.xml' );
		}

		return add_query_arg( self::QUERY_VAR, $file ? $file : 'main', home_url( '/' ) );
	}

	/**
	 * Rewrite rules for the sitemap's addresses.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^video-sitemap\.xml$', 'index.php?' . self::QUERY_VAR . '=main', 'top' );
		add_rewrite_rule( '^video-sitemap-([0-9]+)\.xml$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Flush rewrite rules once after an update that changed them (updates
	 * from GitHub don't run the activation hook), or when the saved rules lack
	 * the sitemap (rebuilt while the plugin wasn't loaded). The second case is
	 * tried at most hourly, in case something else strips the rule.
	 *
	 * @return void
	 */
	public static function maybe_flush() {
		if ( self::REWRITE_VERSION !== get_option( self::REWRITE_OPTION ) ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, true );
			return;
		}

		$rules = get_option( 'rewrite_rules' );

		if ( is_array( $rules ) && $rules && ! isset( $rules['^video-sitemap\.xml$'] ) && ! get_transient( 'video_seo_reflush' ) ) {
			set_transient( 'video_seo_reflush', 1, HOUR_IN_SECONDS );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Don't let WordPress add a trailing slash to the sitemap's address.
	 *
	 * @param string|false $redirect Redirect URL.
	 * @return string|false
	 */
	public static function no_trailing_slash( $redirect ) {
		return get_query_var( self::QUERY_VAR ) ? false : $redirect;
	}

	/**
	 * Mark the sitemap as changed. Cheap: one option write per request at most.
	 *
	 * @return void
	 */
	public static function bump() {
		if ( self::$bumped ) {
			return;
		}

		self::$bumped = true;
		update_option( self::CHANGED_OPTION, time(), true );
	}

	/**
	 * When the sitemap last changed.
	 *
	 * @return int Unix time.
	 */
	public static function changed() {
		$time = (int) get_option( self::CHANGED_OPTION, 0 );

		return $time ? $time : time();
	}

	/**
	 * Post types the sitemap lists (scanned ones with public pages).
	 *
	 * @return string[]
	 */
	public static function post_types() {
		return array_values( array_filter( Video_SEO_Plugin::instance()->post_types(), 'is_post_type_viewable' ) );
	}

	/**
	 * Serve the sitemap.
	 *
	 * @return void
	 */
	public static function serve() {
		$which = get_query_var( self::QUERY_VAR );

		if ( '' === $which || null === $which ) {
			return;
		}

		$xml = self::enabled() ? self::xml( (string) $which ) : null;

		if ( null === $xml ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow', true );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built with every value escaped.
		exit;
	}

	/**
	 * A sitemap file's XML, cached until the next change.
	 *
	 * @param string $which "main" or a file number.
	 * @return string|null Null when there's no such file.
	 */
	public static function xml( $which ) {
		$which = 'main' === $which ? 'main' : (string) absint( $which );
		$cache = get_transient( 'video_seo_sitemap_' . $which );
		$stamp = self::changed() . '|' . get_option( 'permalink_structure' ) . '|' . home_url();

		if ( is_array( $cache ) && isset( $cache['stamp'], $cache['xml'] ) && $cache['stamp'] === $stamp ) {
			return $cache['xml'];
		}

		$types = self::post_types();
		$files = max( 1, (int) ceil( Video_SEO_DB::sitemap_post_count( $types ) / self::PER_FILE ) );

		if ( 'main' === $which ) {
			$xml = $files > 1 ? self::index( $files ) : self::urlset( $types, 1 );
		} else {
			$file = (int) $which;

			if ( $file < 1 || $file > $files ) {
				return null;
			}

			$xml = self::urlset( $types, $file );
		}

		set_transient(
			'video_seo_sitemap_' . $which,
			array(
				'stamp' => $stamp,
				'xml'   => $xml,
			),
			12 * HOUR_IN_SECONDS
		);

		return $xml;
	}

	/**
	 * A sitemap index pointing at the numbered files.
	 *
	 * @param int $files Number of files.
	 * @return string
	 */
	private static function index( $files ) {
		$lastmod = gmdate( 'c', self::changed() );
		$xml     = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		for ( $i = 1; $i <= $files; $i++ ) {
			$xml .= "\t<sitemap><loc>" . self::esc( self::url( $i ) ) . '</loc><lastmod>' . $lastmod . "</lastmod></sitemap>\n";
		}

		return $xml . "</sitemapindex>\n";
	}

	/**
	 * Entries for the sitemap: pages and their listed videos.
	 *
	 * @param string[] $types Post types.
	 * @param int      $file  File number.
	 * @param int      $limit Most pages (0 = a whole file).
	 * @return array[] {post, videos[]}
	 */
	public static function entries( array $types, $file = 1, $limit = 0 ) {
		$ids = Video_SEO_DB::sitemap_posts( $types, $limit ? $limit : self::PER_FILE, ( max( 1, (int) $file ) - 1 ) * self::PER_FILE );

		if ( ! $ids ) {
			return array();
		}

		_prime_post_caches( $ids, false, true );

		$noindex = self::noindex_ids( $ids );
		$out     = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post || isset( $noindex[ $id ] ) ) {
				continue;
			}

			/**
			 * Filters whether a page is left out of the video sitemap.
			 *
			 * @param bool    $exclude Whether to leave it out.
			 * @param WP_Post $post    Page.
			 */
			if ( apply_filters( 'video_seo_sitemap_exclude_post', false, $post ) ) {
				continue;
			}

			$videos = array_values( array_filter( Video_SEO_Video::for_post( $post ), array( 'Video_SEO_Video', 'is_listed' ) ) );

			if ( $videos ) {
				$out[] = array(
					'post'   => $post,
					'videos' => $videos,
				);
			}
		}

		return $out;
	}

	/**
	 * A sitemap file with pages and their videos.
	 *
	 * @param string[] $types Post types.
	 * @param int      $file  File number.
	 * @return string
	 */
	private static function urlset( array $types, $file ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<!-- ' . self::esc( sprintf( 'Video sitemap by Video SEO %s, https://www.contentpowered.com/wordpress-plugins/video-seo/', VIDEO_SEO_VERSION ) ) . " -->\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

		foreach ( self::entries( $types, $file ) as $entry ) {
			$xml .= "\t<url>\n\t\t<loc>" . self::esc( get_permalink( $entry['post'] ) ) . "</loc>\n";

			foreach ( $entry['videos'] as $video ) {
				$xml .= self::video_xml( $video );
			}

			$xml .= "\t</url>\n";
		}

		return $xml . "</urlset>\n";
	}

	/**
	 * One <video:video> entry.
	 *
	 * @param array $video Built video.
	 * @return string
	 */
	private static function video_xml( array $video ) {
		$tags = array(
			'thumbnail_loc' => $video['thumbnail'],
			'title'         => Video_SEO_Fetcher::cut( $video['title'], 100 ),
			'description'   => Video_SEO_Fetcher::cut( $video['description'], 2048 ),
		);

		if ( '' !== $video['content_url'] ) {
			$tags['content_loc'] = $video['content_url'];
		} else {
			$tags['player_loc'] = $video['embed_url'];
		}

		// Google accepts 1 second to 8 hours.
		if ( $video['duration'] >= 1 && $video['duration'] <= 28800 ) {
			$tags['duration'] = (string) $video['duration'];
		}

		$tags['publication_date'] = Video_SEO_Video::iso_date( $video['upload_date'] );

		$xml = "\t\t<video:video>\n";

		foreach ( $tags as $tag => $value ) {
			$xml .= "\t\t\t<video:{$tag}>" . self::esc( $value ) . "</video:{$tag}>\n";
		}

		return $xml . "\t\t</video:video>\n";
	}

	/**
	 * Escape text for XML, dropping characters XML 1.0 can't hold.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function esc( $text ) {
		$text = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $text );

		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Posts an SEO plugin marks noindex (Yoast SEO, Rank Math, SEOPress, All
	 * in One SEO).
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int,bool>
	 */
	public static function noindex_ids( array $ids ) {
		global $wpdb;

		$out = array();

		foreach ( $ids as $id ) {
			$robots = get_post_meta( $id, 'rank_math_robots', true );

			if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true )
				|| ( is_array( $robots ) && in_array( 'noindex', $robots, true ) )
				|| 'yes' === get_post_meta( $id, '_seopress_robots_index', true ) ) {
				$out[ $id ] = true;
			}
		}

		if ( function_exists( 'aioseo' ) && $ids ) {
			$table = $wpdb->prefix . 'aioseo_posts';

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Another plugin's table, checked once per build.
				$rows = $wpdb->get_col( "SELECT post_id FROM {$table} WHERE robots_default = 0 AND robots_noindex = 1 AND post_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Integers only.

				foreach ( (array) $rows as $id ) {
					$out[ (int) $id ] = true;
				}
			}
		}

		return $out;
	}

	/**
	 * Add the sitemap to robots.txt.
	 *
	 * @param string $output robots.txt.
	 * @param bool   $public Whether the site allows search engines.
	 * @return string
	 */
	public static function robots( $output, $public ) {
		if ( ! $public || ! self::enabled() || ! Video_SEO_Plugin::instance()->get_setting( 'robots' ) ) {
			return $output;
		}

		$line = 'Sitemap: ' . self::url();

		if ( false !== strpos( (string) $output, $line ) ) {
			return $output;
		}

		return rtrim( (string) $output ) . "\n\n" . $line . "\n";
	}

	/**
	 * Whether to list the sitemap in SEO plugins' indexes.
	 *
	 * @return bool
	 */
	private static function listable() {
		return self::enabled() && Video_SEO_DB::sitemap_post_count( self::post_types() ) > 0;
	}

	/**
	 * Add the sitemap to Yoast SEO's, Rank Math's or SEOPress's index.
	 *
	 * @param string $xml Index entries so far.
	 * @return string
	 */
	public static function index_xml( $xml ) {
		if ( ! self::listable() ) {
			return $xml;
		}

		return $xml . "\n<sitemap>\n\t<loc>" . self::esc( self::url() ) . "</loc>\n\t<lastmod>" . gmdate( 'c', self::changed() ) . "</lastmod>\n</sitemap>\n";
	}

	/**
	 * Add the sitemap to All in One SEO's index.
	 *
	 * @param array $indexes Index entries.
	 * @return array
	 */
	public static function aioseo_index( $indexes ) {
		if ( ! is_array( $indexes ) || ! self::listable() ) {
			return $indexes;
		}

		$indexes[] = array(
			'loc'     => self::url(),
			'lastmod' => gmdate( 'Y-m-d H:i:s', self::changed() ),
			'count'   => Video_SEO_DB::sitemap_post_count( self::post_types() ),
		);

		return $indexes;
	}
}
