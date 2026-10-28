<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * @package Video_SEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove one site's data: tables, options, post meta, transients, cron.
 *
 * @return void
 */
function video_seo_uninstall_site() {
	global $wpdb;

	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vseo_videos' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table name.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vseo_post_videos' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table name.

	foreach ( array( 'video_seo_settings', 'video_seo_db_version', 'video_seo_scan_state', 'video_seo_api_status', 'video_seo_sitemap_changed', 'video_seo_rewrite_version' ) as $option ) {
		delete_option( $option );
	}

	foreach ( array( '_vseo_scan', '_vseo_flag', '_vseo_overrides' ) as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	delete_metadata( 'user', 0, 'video_seo_per_page', '', true );

	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_video\\_seo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_video\\_seo\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup.

	foreach ( array( 'video_seo_scan_batch', 'video_seo_fetch', 'video_seo_daily' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// Plugin Update Checker's cached update state.
	delete_site_option( 'external_updates-video-seo' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $video_seo_site ) {
		switch_to_blog( $video_seo_site );
		video_seo_uninstall_site();
		restore_current_blog();
	}
} else {
	video_seo_uninstall_site();
}

// The sitemap's rewrite rule goes with the rebuilt rules.
delete_option( 'rewrite_rules' );
