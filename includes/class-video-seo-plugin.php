<?php
/**
 * Main plugin orchestrator: owns the settings and boots every part.
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Video_SEO_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'video_seo_settings';

	/**
	 * Lightweight embed modes.
	 */
	const FACADE_MODES = array( 'off', 'except_main', 'all' );

	/**
	 * Single shared instance.
	 *
	 * @var Video_SEO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings cache.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Video_SEO_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up. Runs after the instance is stored, so the parts can
	 * read settings while they hook in.
	 *
	 * @return void
	 */
	private function boot() {
		Video_SEO_DB::maybe_install();
		Video_SEO_Scanner::init();
		Video_SEO_Sitemap::init();
		Video_SEO_Schema::init();
		Video_SEO_Frontend::init();
		Video_SEO_Editor::init();

		if ( is_admin() ) {
			new Video_SEO_Admin( $this );
		}

		// A setting's first save fires add_option_*, later ones update_option_*.
		add_action( 'add_option_' . self::OPTION, array( $this, 'settings_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, array( $this, 'settings_changed' ), 10, 2 );
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'youtube_key'       => '',
			'post_types_all'    => 1,
			'post_types'        => array(),
			'schema'            => 1,
			'key_moments'       => 1,
			'featured_fallback' => 1,
			'sitemap'           => 1,
			'robots'            => 1,
			'facade'            => 'off',
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Post types that can hold videos: public ones, without attachments.
	 *
	 * @return array<string,string> Labels by post type.
	 */
	public static function available_post_types() {
		$out = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type => $object ) {
			if ( 'attachment' === $type ) {
				continue;
			}

			$out[ $type ] = $object->labels->name;
		}

		return $out;
	}

	/**
	 * Post types the plugin scans.
	 *
	 * @return string[]
	 */
	public function post_types() {
		$available = array_keys( self::available_post_types() );

		if ( $this->get_setting( 'post_types_all' ) ) {
			$types = $available;
		} else {
			$types = array_values( array_intersect( (array) $this->get_setting( 'post_types' ), $available ) );
		}

		/**
		 * Filters the post types scanned for videos.
		 *
		 * @param string[] $types Post types.
		 */
		return array_values( array_unique( (array) apply_filters( 'video_seo_post_types', $types ) ) );
	}

	/**
	 * Settings saved for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 * @return void
	 */
	public function settings_added( $option, $value ) {
		$this->settings_changed( self::defaults(), $value );
	}

	/**
	 * React to saved settings: rescan when the content types change, fetch
	 * YouTube details again when a key is added.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 * @return void
	 */
	public function settings_changed( $old, $new ) {
		$this->settings = null;

		$old = wp_parse_args( is_array( $old ) ? $old : array(), self::defaults() );
		$new = wp_parse_args( is_array( $new ) ? $new : array(), self::defaults() );

		$types_before = $old['post_types_all'] ? 'all' : implode( ',', (array) $old['post_types'] );
		$types_after  = $new['post_types_all'] ? 'all' : implode( ',', (array) $new['post_types'] );

		if ( $types_before !== $types_after ) {
			Video_SEO_Scanner::start_full_scan();
		}

		if ( $old['youtube_key'] !== $new['youtube_key'] ) {
			delete_option( Video_SEO_Fetcher::API_STATUS_OPTION );
			Video_SEO_DB::mark_for_refresh( array(), 'youtube' );
			Video_SEO_Scanner::schedule_fetch();
		}

		if ( $old['key_moments'] !== $new['key_moments'] || $old['sitemap'] !== $new['sitemap'] || $old['featured_fallback'] !== $new['featured_fallback'] ) {
			Video_SEO_Sitemap::bump();
		}
	}
}
