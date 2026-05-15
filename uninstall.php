<?php
/**
 * Lean SEO — uninstall handler.
 *
 * Triggered when the user deletes the plugin from the WordPress admin.
 * Removes ALL post meta added by this plugin to keep wp_postmeta clean.
 *
 * @package LeanSEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// All meta keys this plugin manages — keep in sync with lean_seo_register_meta().
$meta_keys = array(
	'_lean_seo_title',
	'_lean_seo_description',
	'_lean_seo_canonical',
	'_lean_seo_og_image',
	'_lean_seo_og_type',
	'_lean_seo_article_type',
	'_lean_seo_noindex',
	'_lean_seo_nofollow',
);

// Single bulk DELETE — covers every post type at once.
$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
$sql          = "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})";
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( $sql, $meta_keys ) );

// Plugin-wide options.
delete_option( 'lean_seo_schema_map' );
