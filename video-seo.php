<?php
/**
 * Plugin Name:       Video SEO
 * Plugin URI:        https://www.contentpowered.com/wordpress-plugins/video-seo/
 * Description:       Gets your videos into Google's video results. Finds every YouTube, Vimeo and self-hosted video, adds VideoObject schema with key moments, and builds a video sitemap, with no setup per post.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       video-seo
 * Update URI:        https://github.com/jmsprsns/video-seo
 *
 * @package Video_SEO
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$video_seo_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'VIDEO_SEO_VERSION', $video_seo_headers['version'] );
define( 'VIDEO_SEO_REPO', $video_seo_headers['repo'] );
define( 'VIDEO_SEO_FILE', __FILE__ );
define( 'VIDEO_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIDEO_SEO_URL', plugin_dir_url( __FILE__ ) );

unset( $video_seo_headers );

require_once VIDEO_SEO_DIR . 'includes/class-video-seo-icons.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-updater.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-menu.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-feedback.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-db.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-detector.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-fetcher.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-video.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-scanner.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-schema.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-sitemap.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-frontend.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-editor.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-admin.php';
require_once VIDEO_SEO_DIR . 'includes/class-video-seo-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Video_SEO_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Video_SEO_Plugin
 */
function video_seo_plugin() {
	return Video_SEO_Plugin::instance();
}
add_action( 'plugins_loaded', 'video_seo_plugin' );

/**
 * Set up on activation: tables, the sitemap address and the first scan.
 *
 * @return void
 */
function video_seo_activate() {
	Video_SEO_DB::install();
	Video_SEO_Sitemap::add_rewrite_rules();
	flush_rewrite_rules( false );
	update_option( Video_SEO_Sitemap::REWRITE_OPTION, Video_SEO_Sitemap::REWRITE_VERSION, false );
	Video_SEO_Scanner::start_full_scan();
}
register_activation_hook( VIDEO_SEO_FILE, 'video_seo_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings and data stay put (uninstall.php removes them). Background jobs
 * stop, and the sitemap address is dropped from the rewrite rules.
 *
 * @return void
 */
function video_seo_deactivate() {
	foreach ( Video_SEO_Scanner::cron_hooks() as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// Rebuilt on the next request without this plugin's sitemap rule.
	delete_option( 'rewrite_rules' );
	delete_option( Video_SEO_Sitemap::REWRITE_OPTION );
}
register_deactivation_hook( VIDEO_SEO_FILE, 'video_seo_deactivate' );
