<?php
/**
 * Lean SEO — Rank Math Pro → lean-seo migration script.
 *
 * Run via WP-CLI from the server root:
 *
 *   # Dry-run (default — reports only, writes nothing):
 *   wp eval-file migration/migrate-from-rank-math.php
 *
 *   # Apply (writes lean_seo_* meta, never overwrites existing values):
 *   LEANSEO_APPLY=1 wp eval-file migration/migrate-from-rank-math.php
 *
 * Note: the `-- --apply` form is NOT reliable across WP-CLI versions (WP-CLI 2.12+
 * intercepts `--apply` as an unknown flag before passing it to the script).
 * Use the LEANSEO_APPLY=1 env var instead — it works across all WP-CLI versions.
 *
 * Prerequisites:
 *   - lean-seo plugin active (meta keys registered)
 *   - Rank Math plugin still installed (meta still in DB)
 *   - Run as admin user or with --allow-root
 *
 * Safety contract:
 *   - Idempotent: re-running produces the same result.
 *   - Non-destructive on destination: NEVER overwrites a lean_seo_* key that
 *     already has a non-empty value. Rank Math data is only read, never touched.
 *   - Redirects: Rank Math redirects live in wp_rank_math_redirections (not
 *     postmeta). This script does NOT migrate them. Use lean-redirects for that.
 *
 * Meta key mapping:
 *   rank_math_title              → _lean_seo_title
 *   rank_math_description        → _lean_seo_description
 *   rank_math_canonical_url      → _lean_seo_canonical
 *   rank_math_robots             → _lean_seo_noindex + _lean_seo_nofollow
 *   rank_math_facebook_image     → _lean_seo_og_image  (if no og_image set)
 *   rank_math_focus_keyword      → (skipped — no lean-seo equivalent)
 *   rank_math_twitter_*          → (skipped — lean-seo derives from og/title)
 *
 * @package LeanSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run via: wp eval-file migration/migrate-from-rank-math.php [-- --apply]\n";
	exit( 1 );
}

// ── Parse flags ───────────────────────────────────────────────────────────────
// Primary: LEANSEO_APPLY=1 env var (reliable across all WP-CLI versions).
// Fallback: --apply in argv (may be intercepted by WP-CLI 2.12+ — use env var instead).
$apply = getenv( 'LEANSEO_APPLY' ) === '1'
	|| in_array( '--apply', $GLOBALS['argv'] ?? array(), true );
$mode  = $apply ? 'APPLY' : 'DRY-RUN';

WP_CLI::log( "" );
WP_CLI::log( "=== Lean SEO: Rank Math migration ({$mode}) ===" );
WP_CLI::log( "" );

// ── Meta key definitions ──────────────────────────────────────────────────────
$map = array(
	'rank_math_title'          => '_lean_seo_title',
	'rank_math_description'    => '_lean_seo_description',
	'rank_math_canonical_url'  => '_lean_seo_canonical',
	'rank_math_facebook_image' => '_lean_seo_og_image',
	// robots handled separately below — it's a serialized array
	// focus_keyword, twitter_* — skipped intentionally
);

// ── Audit: count posts with each Rank Math meta ───────────────────────────────
global $wpdb;

WP_CLI::log( "--- Rank Math meta presence ---" );
$rm_keys_to_audit = array_merge(
	array_keys( $map ),
	array( 'rank_math_robots', 'rank_math_focus_keyword' )
);
foreach ( $rm_keys_to_audit as $rm_key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$count = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
		$rm_key
	) );
	WP_CLI::log( sprintf( "  %-40s %d posts", $rm_key, (int) $count ) );
}

// Redirections table check.
$redirections_table = $wpdb->prefix . 'rank_math_redirections';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$redir_count = $wpdb->get_var( "SHOW TABLES LIKE '{$redirections_table}'" ) === $redirections_table
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$redirections_table}" )
	: 0;
WP_CLI::log( "" );
WP_CLI::log( "  NOTICE: {$redir_count} redirect rule(s) found in {$redirections_table}." );
WP_CLI::log( "  Redirects are NOT migrated by this script." );
WP_CLI::log( "  Use lean-redirects (github.com/ctala/lean-redirects) and import separately." );
WP_CLI::log( "" );

// ── Get all post IDs that have any Rank Math meta ─────────────────────────────
$rm_all_keys = array_merge( array_keys( $map ), array( 'rank_math_robots' ) );
$in_placeholders = implode( ',', array_fill( 0, count( $rm_all_keys ), '%s' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$post_ids = $wpdb->get_col( $wpdb->prepare(
	"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$in_placeholders})",
	$rm_all_keys
) );

WP_CLI::log( sprintf( "Posts with Rank Math meta: %d", count( $post_ids ) ) );
WP_CLI::log( "" );

if ( empty( $post_ids ) ) {
	WP_CLI::success( "Nothing to migrate." );
	return;
}

// ── Stats counters ────────────────────────────────────────────────────────────
$stats = array();
foreach ( array_merge( array_values( $map ), array( '_lean_seo_noindex', '_lean_seo_nofollow' ) ) as $k ) {
	$stats[ $k ] = array( 'migrated' => 0, 'skipped_existing' => 0, 'skipped_empty_source' => 0 );
}

// ── Iterate posts ─────────────────────────────────────────────────────────────
$progress = WP_CLI\Utils\make_progress_bar( 'Processing posts', count( $post_ids ) );

foreach ( $post_ids as $post_id ) {
	$post_id = (int) $post_id;

	// --- Simple 1-to-1 text fields ---
	foreach ( $map as $rm_key => $lean_key ) {
		$source_val = get_post_meta( $post_id, $rm_key, true );

		if ( empty( $source_val ) ) {
			$stats[ $lean_key ]['skipped_empty_source']++;
			continue;
		}

		$dest_val = get_post_meta( $post_id, $lean_key, true );
		if ( ! empty( $dest_val ) ) {
			$stats[ $lean_key ]['skipped_existing']++;
			continue;
		}

		if ( $apply ) {
			update_post_meta( $post_id, $lean_key, $source_val );
		}
		$stats[ $lean_key ]['migrated']++;
	}

	// --- robots → noindex + nofollow ---
	$rm_robots_raw = get_post_meta( $post_id, 'rank_math_robots', true );
	if ( ! empty( $rm_robots_raw ) ) {
		// Rank Math stores robots as a serialized PHP array or a CSV string depending on version.
		if ( is_serialized( $rm_robots_raw ) ) {
			$rm_robots = maybe_unserialize( $rm_robots_raw );
		} elseif ( is_array( $rm_robots_raw ) ) {
			$rm_robots = $rm_robots_raw;
		} else {
			// Fallback: comma-separated.
			$rm_robots = array_map( 'trim', explode( ',', $rm_robots_raw ) );
		}
		$rm_robots = array_map( 'strtolower', (array) $rm_robots );

		// noindex.
		if ( in_array( 'noindex', $rm_robots, true ) ) {
			$dest = get_post_meta( $post_id, '_lean_seo_noindex', true );
			if ( empty( $dest ) ) {
				if ( $apply ) {
					update_post_meta( $post_id, '_lean_seo_noindex', true );
				}
				$stats['_lean_seo_noindex']['migrated']++;
			} else {
				$stats['_lean_seo_noindex']['skipped_existing']++;
			}
		}

		// nofollow.
		if ( in_array( 'nofollow', $rm_robots, true ) ) {
			$dest = get_post_meta( $post_id, '_lean_seo_nofollow', true );
			if ( empty( $dest ) ) {
				if ( $apply ) {
					update_post_meta( $post_id, '_lean_seo_nofollow', true );
				}
				$stats['_lean_seo_nofollow']['migrated']++;
			} else {
				$stats['_lean_seo_nofollow']['skipped_existing']++;
			}
		}
	}

	$progress->tick();
}

$progress->finish();
WP_CLI::log( "" );

// ── Report ────────────────────────────────────────────────────────────────────
WP_CLI::log( "--- Results ({$mode}) ---" );
WP_CLI::log( sprintf( "  %-35s %10s %20s %25s", 'lean-seo key', 'migrated', 'skipped (existing)', 'skipped (empty src)' ) );
foreach ( $stats as $key => $s ) {
	WP_CLI::log( sprintf( "  %-35s %10d %20d %25d", $key, $s['migrated'], $s['skipped_existing'], $s['skipped_empty_source'] ) );
}
WP_CLI::log( "" );

if ( $apply ) {
	WP_CLI::success( "Migration applied. Verify a sample of posts before deactivating Rank Math." );
	WP_CLI::log( "  Next steps:" );
	WP_CLI::log( "  1. curl a sample of 10-20 URLs, verify <title>, canonical, og:image in HTML." );
	WP_CLI::log( "  2. Check Rich Results Test on 5 URLs for JSON-LD." );
	WP_CLI::log( "  3. Wait 48h monitoring GSC for ranking signals." );
	WP_CLI::log( "  4. Once stable, deactivate Rank Math (don't uninstall yet — keep 2 weeks)." );
	WP_CLI::log( "  5. Migrate redirects separately via lean-redirects REST API or WP admin." );
} else {
	WP_CLI::log( "DRY-RUN complete — no data was written." );
	WP_CLI::log( "Re-run with 'LEANSEO_APPLY=1 wp eval-file ...' to execute the migration." );
}
WP_CLI::log( "" );
