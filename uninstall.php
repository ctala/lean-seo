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
	'_lean_seo_faq',    // v1.3.0
	'_lean_seo_howto',  // v1.3.0
);

// Single bulk DELETE — covers every post type at once.
$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
$sql          = "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})";
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( $sql, $meta_keys ) );

// Plugin-wide options — v1.1.0+
delete_option( 'lean_seo_schema_map' );
// v1.2.0+
delete_option( 'lean_seo_same_as' );
delete_option( 'lean_seo_org_same_as' ); // v1.3.1
delete_option( 'lean_seo_llmstxt_enabled' );
delete_option( 'lean_seo_llmstxt' );
delete_option( 'lean_seo_indexnow_key' );
delete_transient( 'lean_seo_llmstxt' );
// v1.3.0+
delete_option( 'lean_seo_ai_crawlers' );
delete_option( 'lean_seo_llmsfull_enabled' );
delete_option( 'lean_seo_llmsfull' );
delete_option( 'lean_seo_image_sitemap_enabled' );
delete_transient( 'lean_seo_image_sitemap' );
delete_transient( 'lean_seo_llmsfull' );
delete_option( 'lean_seo_rank_math_fallback' );
// v1.4.0 — Organization enrichment.
delete_option( 'lean_seo_org_type' );
delete_option( 'lean_seo_org_logo' );
delete_option( 'lean_seo_org_description' );
delete_option( 'lean_seo_org_founding_date' );
delete_option( 'lean_seo_org_founder_name' );
delete_option( 'lean_seo_org_founder_sameas' );
delete_option( 'lean_seo_org_contact_email' );
