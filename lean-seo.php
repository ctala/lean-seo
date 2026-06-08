<?php
/**
 * Plugin Name: Lean SEO
 * Plugin URI:  https://github.com/ctala/lean-seo
 * Description: SEO core for WordPress. Canonical, OG, JSON-LD @graph, breadcrumbs, FAQ/HowTo schema, AI crawlers, image sitemap, llms.txt/llms-full.txt, IndexNow. Zero JS. No bloat.
 * Version:     1.5.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author:      Cristian Tala
 * Author URI:  https://cristiantala.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lean-seo
 *
 * @package LeanSEO
 *
 * Lean SEO — WordPress Plugin
 * Copyright (C) 2026 Cristian Tala (https://cristiantala.com)
 *
 * Original author: Cristian Tala — https://github.com/ctala/lean-seo
 * Forks and derivative works must retain this attribution.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEAN_SEO_VERSION', '1.5.0' );
define( 'LEAN_SEO_DB_VERSION', '1' ); // Bump when rewrite rules change — triggers auto-flush on upgrade.
define( 'LEAN_SEO_NS', '_lean_seo_' );

/*
 * SEO LENGTH GUIDELINES (Google + OG/Twitter 2026 best practices)
 *
 * These are the recommended character ranges. The plugin shows live counters
 * in the admin meta box, colored by status:
 *   - good (green)  → within optimal range
 *   - warn (orange) → outside optimal but within hard limit
 *   - over (red)    → exceeds hard limit (likely truncated in SERP / social)
 *
 * Filterable via `lean_seo_length_guidelines`.
 */
define( 'LEAN_SEO_TITLE_OPTIMAL_MIN', 30 );
define( 'LEAN_SEO_TITLE_OPTIMAL_MAX', 60 );
define( 'LEAN_SEO_TITLE_HARD_MAX', 70 );
define( 'LEAN_SEO_DESC_OPTIMAL_MIN', 120 );
define( 'LEAN_SEO_DESC_OPTIMAL_MAX', 155 );
define( 'LEAN_SEO_DESC_HARD_MAX', 160 );

/* ═══════════════════════════════════════════════════════════════════════════
   REWRITE RULES — virtual routes via WP rewrite, not raw template_redirect
   ───────────────────────────────────────────────────────────────────────────
   WHY THIS EXISTS (v1.4.2):
   LiteSpeed (and some Nginx/Apache configs) serve files with known extensions
   (.txt, .xml) directly from the filesystem and return 404 before PHP/WP runs
   when the file does not exist on disk.  A query string bypasses this because
   the server knows a static file can't have query args.  WP core solves this
   for wp-sitemap.xml by registering rewrite rules that map the URL to a WP
   query var — the server sees a query-var URL (or passes it to WordPress via
   the main `index.php` try_files rule) and WP handles it.  We do the same.

   The query var `lean_seo_route` is added to WP's recognised vars so it
   survives the query parser.  Handlers check the var first; the legacy path
   check is kept as a fallback for environments that don't need the rewrite.

   FLUSHING (critical):
   add_rewrite_rule() calls are cheap but the compiled rewrite table stored in
   the DB must be regenerated after the rules are added.  This happens on:
     a) Plugin activation   → register_activation_hook fires flush_rewrite_rules()
     b) Plugin deactivation → same, to remove our rules from the table
     c) Upgrade detection   → lean_seo_maybe_flush_rewrites() compares
        lean_seo_db_version against LEAN_SEO_DB_VERSION and flushes if they
        differ.  This covers re-uploads / auto-updates where the activation
        hook does NOT re-fire.
     d) Manual fallback     → Settings → Permalinks → Save (WP always flushes there)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Register rewrite rules for Lean SEO virtual routes.
 * Called on `init` before WP compiles the rewrite table for the request.
 *
 * @return void
 */
function lean_seo_register_rewrite_rules() {
	// Fixed routes.
	add_rewrite_rule( '^llms\.txt$', 'index.php?lean_seo_route=llmstxt', 'top' );
	add_rewrite_rule( '^llms-full\.txt$', 'index.php?lean_seo_route=llmsfull', 'top' );
	add_rewrite_rule( '^sitemap-images\.xml$', 'index.php?lean_seo_route=imagesitemap', 'top' );

	// IndexNow key file: /{32-128 hex chars}.txt at root.
	// Pattern is broad enough to match any hex key without knowing the value at rule-registration time.
	add_rewrite_rule( '^([0-9a-fA-F]{32,128})\.txt$', 'index.php?lean_seo_route=indexnow_key&lean_seo_indexnow_key_slug=$matches[1]', 'top' );
}
add_action( 'init', 'lean_seo_register_rewrite_rules', 1 ); // priority 1 — before WP processes the request

/**
 * Expose Lean SEO query vars so WP does not strip them during parse_request.
 *
 * @param array $vars Existing public query vars.
 * @return array
 */
function lean_seo_register_query_vars( $vars ) {
	$vars[] = 'lean_seo_route';
	$vars[] = 'lean_seo_indexnow_key_slug';
	return $vars;
}
add_filter( 'query_vars', 'lean_seo_register_query_vars' );

/**
 * Activation: flush rewrite rules so our new rules take effect immediately.
 *
 * @return void
 */
function lean_seo_activate() {
	lean_seo_register_rewrite_rules();
	flush_rewrite_rules();
	update_option( 'lean_seo_db_version', LEAN_SEO_DB_VERSION );
}
register_activation_hook( __FILE__, 'lean_seo_activate' );

/**
 * Deactivation: flush rewrite rules so our patterns are removed from the table.
 *
 * @return void
 */
function lean_seo_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'lean_seo_deactivate' );

/**
 * Upgrade detection: if the stored db_version differs from the current constant,
 * re-flush rewrite rules.  Covers re-upload / auto-update paths where the
 * activation hook does not fire again.
 *
 * @return void
 */
function lean_seo_maybe_flush_rewrites() {
	if ( get_option( 'lean_seo_db_version' ) !== LEAN_SEO_DB_VERSION ) {
		lean_seo_register_rewrite_rules();
		flush_rewrite_rules();
		update_option( 'lean_seo_db_version', LEAN_SEO_DB_VERSION );
	}
}
add_action( 'init', 'lean_seo_maybe_flush_rewrites', 99 ); // after register_rewrite_rules (priority 1)

/* ═══════════════════════════════════════════════════════════════════════════
   META REGISTRATION — REST-exposable post meta
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'init', 'lean_seo_register_meta', 20 );

/**
 * Register SEO meta keys on every public post type.
 *
 * @return void
 */
function lean_seo_register_meta() {
	$keys = array(
		'title'        => 'string', // Custom <title> override
		'description'  => 'string', // Meta description
		'canonical'    => 'string', // Canonical URL override
		'og_image'     => 'string', // OG image URL override
		'og_type'      => 'string', // article|website|profile|video (override auto)
		'article_type' => 'string', // schema.org type: Article|NewsArticle|BlogPosting|TechArticle
		'noindex'      => 'boolean',
		'nofollow'     => 'boolean',
		'faq'          => 'string', // JSON: [{q:'',a:''},...] — FAQPage schema opt-in
		'howto'        => 'string', // JSON: {name:'',desc:'',steps:[{name:'',text:'',img:''},...]} — HowTo schema opt-in
	);

	$post_types = get_post_types( array( 'public' => true ), 'names' );
	$post_types = apply_filters( 'lean_seo_post_types', $post_types );

	foreach ( $post_types as $post_type ) {
		foreach ( $keys as $key => $type ) {
			register_post_meta( $post_type, LEAN_SEO_NS . $key, array(
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => $type,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			) );
		}
	}
}

/**
 * Accessor for lean_seo meta with sensible defaults.
 * When a Rank Math fallback is enabled (lean_seo_rank_math_fallback option = '1'),
 * reads Rank Math postmeta as a transparent fallback for unmigrated posts.
 * This lets lean-seo coexist safely until the migration script is run.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Suffix (without LEAN_SEO_NS prefix).
 * @return string|bool
 */
function lean_seo_get( $post_id, $key ) {
	$v = get_post_meta( $post_id, LEAN_SEO_NS . $key, true );
	if ( is_bool( $v ) ) {
		return $v;
	}
	if ( in_array( $key, array( 'noindex', 'nofollow' ), true ) ) {
		// Boolean fields: check value then maybe fall back to Rank Math robots.
		if ( $v ) {
			return true;
		}
		if ( get_option( 'lean_seo_rank_math_fallback', '0' ) === '1' ) {
			$rm_robots_raw = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( $rm_robots_raw ) {
				$rm = is_serialized( $rm_robots_raw ) ? maybe_unserialize( $rm_robots_raw ) : array_map( 'trim', explode( ',', $rm_robots_raw ) );
				if ( in_array( $key, (array) $rm, true ) ) {
					return true;
				}
			}
		}
		return false;
	}
	$v = is_string( $v ) ? trim( $v ) : '';
	if ( $v ) {
		return $v;
	}
	// Rank Math fallback for string fields (only when no lean-seo value exists).
	if ( get_option( 'lean_seo_rank_math_fallback', '0' ) === '1' ) {
		$rm_map = array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'canonical'   => 'rank_math_canonical_url',
			'og_image'    => 'rank_math_facebook_image',
		);
		if ( isset( $rm_map[ $key ] ) ) {
			$fallback = get_post_meta( $post_id, $rm_map[ $key ], true );
			return is_string( $fallback ) ? trim( $fallback ) : '';
		}
	}
	return '';
}

/* ═══════════════════════════════════════════════════════════════════════════
   TITLE — custom title override + theme separator awareness
   ═══════════════════════════════════════════════════════════════════════════ */

add_filter( 'pre_get_document_title', 'lean_seo_filter_title', 20 );

/**
 * Override document title when custom meta is set on singular views.
 *
 * @param string $title Default title.
 * @return string
 */
function lean_seo_filter_title( $title ) {
	if ( ! is_singular() ) {
		return $title;
	}
	$custom = lean_seo_get( get_queried_object_id(), 'title' );
	return $custom ? $custom : $title;
}

/* ═══════════════════════════════════════════════════════════════════════════
   CANONICAL — handle singular, paginated, archives, search, 404 correctly
   ═══════════════════════════════════════════════════════════════════════════ */

// Disable WP-native canonical and OG so we don't duplicate.
remove_action( 'wp_head', 'rel_canonical' );

add_filter( 'get_canonical_url', 'lean_seo_filter_canonical', 10, 2 );

/**
 * Override WP-native get_canonical_url when our meta is set.
 *
 * @param string  $canonical_url WP-computed canonical.
 * @param WP_Post $post          Post being filtered.
 * @return string
 */
function lean_seo_filter_canonical( $canonical_url, $post ) {
	$custom = lean_seo_get( $post->ID, 'canonical' );
	return $custom ? $custom : $canonical_url;
}

/**
 * Compute the canonical URL for the current request.
 * Handles: singular, paginated /page/N/, home/archives, search, 404.
 *
 * @return string Empty string if no canonical should be emitted.
 */
function lean_seo_current_canonical() {
	if ( is_singular() ) {
		$post_id = get_queried_object_id();
		$custom  = lean_seo_get( $post_id, 'canonical' );
		if ( $custom ) {
			return $custom;
		}
		return get_permalink( $post_id );
	}

	if ( is_search() || is_404() ) {
		return ''; // omit canonical
	}

	if ( is_home() || is_front_page() ) {
		$paged = (int) get_query_var( 'paged' );
		return $paged > 1 ? get_pagenum_link( $paged ) : home_url( '/' );
	}

	if ( is_archive() ) {
		$paged = (int) get_query_var( 'paged' );
		if ( $paged > 1 ) {
			return get_pagenum_link( $paged );
		}
		// term/author/post-type-archive base URL
		$obj = get_queried_object();
		if ( $obj && isset( $obj->term_id ) ) {
			return get_term_link( $obj );
		}
		if ( is_post_type_archive() ) {
			return get_post_type_archive_link( get_post_type() );
		}
		if ( is_author() ) {
			return get_author_posts_url( get_queried_object_id() );
		}
	}

	return '';
}

/* ═══════════════════════════════════════════════════════════════════════════
   ROBOTS — automatic noindex for thin contexts + advanced directives
   ═══════════════════════════════════════════════════════════════════════════ */

add_filter( 'wp_robots', 'lean_seo_filter_robots', 20 );

/**
 * Inject advanced robots directives + auto noindex for thin/duplicate contexts.
 *
 * @param array $directives Existing directives from WP.
 * @return array
 */
function lean_seo_filter_robots( $directives ) {
	// Advanced directives — default on. Override via filter.
	$advanced = apply_filters( 'lean_seo_robots_directives', array(
		'max-snippet'        => '-1',
		'max-image-preview'  => 'large',
		'max-video-preview'  => '-1',
	) );
	foreach ( $advanced as $k => $v ) {
		$directives[ $k ] = $v;
	}

	// Per-post noindex/nofollow override.
	if ( is_singular() ) {
		$pid = get_queried_object_id();
		if ( lean_seo_get( $pid, 'noindex' ) ) {
			$directives['noindex'] = true;
			unset( $directives['index'] );
		}
		if ( lean_seo_get( $pid, 'nofollow' ) ) {
			$directives['nofollow'] = true;
			unset( $directives['follow'] );
		}
	}

	// Auto noindex: search, author archives, attachments, replytocom.
	if ( is_search() || is_404() ) {
		$directives['noindex']  = true;
		$directives['nofollow'] = true;
		unset( $directives['index'], $directives['follow'] );
	}
	if ( is_attachment() ) {
		$directives['noindex'] = true;
		unset( $directives['index'] );
	}
	if ( isset( $_GET['replytocom'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$directives['noindex'] = true;
		unset( $directives['index'] );
	}

	return $directives;
}

// NOTE: 301 redirect from attachment pages to the file is OUT OF SCOPE for lean-seo.
// Use lean-redirects with a pattern rule. lean-seo only emits `noindex` for attachments
// in `lean_seo_filter_robots()` above — covers the SEO side without duplicating logic
// that already lives in another lean plugin.

/* ═══════════════════════════════════════════════════════════════════════════
   FRONTEND — emit canonical + meta + OG + Twitter + JSON-LD in wp_head
   ═══════════════════════════════════════════════════════════════════════════ */

// Remove WP-native OG (rare but happens via theme/plugins).
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
add_action( 'wp_head', 'wp_oembed_add_discovery_links' ); // re-add (was just to ensure order)

add_action( 'wp_head', 'lean_seo_emit', 1 );

/**
 * Emit all head tags. One pass, no per-section actions.
 *
 * @return void
 */
function lean_seo_emit() {
	$canonical   = lean_seo_current_canonical();
	$url         = $canonical ? $canonical : ( function_exists( 'home_url' ) ? home_url( add_query_arg( null, null ) ) : '' );
	$is_singular = is_singular();
	$post_id     = $is_singular ? get_queried_object_id() : 0;

	// ── Description ──────────────────────────────────────────────────────
	$description = $is_singular ? lean_seo_get( $post_id, 'description' ) : '';
	if ( ! $description && $is_singular ) {
		$excerpt = wp_strip_all_tags( get_the_excerpt( $post_id ) );
		if ( ! $excerpt ) {
			$excerpt = wp_strip_all_tags( get_post_field( 'post_content', $post_id ) );
		}
		$description = lean_seo_trim( $excerpt, 160 );
	}
	if ( ! $description ) {
		$description = get_bloginfo( 'description' );
	}

	// ── Title (already overridden by filter; just retrieve) ──────────────
	$title = wp_get_document_title();

	// ── OG image with fallback chain: meta → featured → first img → default
	$og_image = $is_singular ? lean_seo_get( $post_id, 'og_image' ) : '';
	if ( ! $og_image && $is_singular ) {
		$tid = get_post_thumbnail_id( $post_id );
		if ( $tid ) {
			$src = wp_get_attachment_image_src( $tid, 'large' );
			if ( $src ) {
				$og_image = $src[0];
			}
		}
	}
	if ( ! $og_image && $is_singular ) {
		$og_image = lean_seo_first_content_image( $post_id );
	}
	if ( ! $og_image ) {
		$og_image = apply_filters( 'lean_seo_default_og_image', '' );
	}

	// ── og:type dinámico ─────────────────────────────────────────────────
	$og_type = $is_singular ? lean_seo_get( $post_id, 'og_type' ) : '';
	if ( ! $og_type ) {
		if ( is_singular( array( 'post' ) ) ) {
			$og_type = 'article';
		} elseif ( is_author() ) {
			$og_type = 'profile';
		} elseif ( $is_singular ) {
			$og_type = 'article'; // CPT default
		} else {
			$og_type = 'website';
		}
	}

	echo "\n<!-- Lean SEO " . esc_html( LEAN_SEO_VERSION ) . " -->\n";

	if ( $canonical ) {
		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
	}

	if ( $description ) {
		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}

	// Open Graph.
	echo '<meta property="og:locale" content="' . esc_attr( get_locale() ) . '" />' . "\n";
	echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '" />' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
	if ( $description ) {
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
	}
	if ( $url ) {
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
	}
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";
	if ( $og_image ) {
		echo '<meta property="og:image" content="' . esc_url( $og_image ) . '" />' . "\n";
	}
	if ( 'article' === $og_type && $is_singular ) {
		$published = get_the_date( 'c', $post_id );
		$modified  = get_the_modified_date( 'c', $post_id );
		echo '<meta property="article:published_time" content="' . esc_attr( $published ) . '" />' . "\n";
		echo '<meta property="article:modified_time" content="' . esc_attr( $modified ) . '" />' . "\n";
	}

	// Twitter — card type derived from og_image.
	echo '<meta name="twitter:card" content="' . ( $og_image ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
	if ( $description ) {
		echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
	}
	if ( $og_image ) {
		echo '<meta name="twitter:image" content="' . esc_url( $og_image ) . '" />' . "\n";
	}

	// JSON-LD @graph.
	lean_seo_emit_jsonld( $is_singular ? $post_id : 0, $url, $title, $description, $og_image, $og_type );

	echo "<!-- /Lean SEO -->\n";
}

/**
 * Trim a string to a max length, suffixing ellipsis.
 *
 * @param string $s   String.
 * @param int    $max Max length.
 * @return string
 */
function lean_seo_trim( $s, $max ) {
	$s = trim( $s );
	if ( strlen( $s ) <= $max ) {
		return $s;
	}
	return rtrim( substr( $s, 0, $max - 1 ) ) . '…';
}

/**
 * Extract the first <img> URL from the post content.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function lean_seo_first_content_image( $post_id ) {
	$content = get_post_field( 'post_content', $post_id );
	if ( ! $content || ! function_exists( 'preg_match' ) ) {
		return '';
	}
	if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $m ) ) {
		return $m[1];
	}
	return '';
}

/* ═══════════════════════════════════════════════════════════════════════════
   JSON-LD @graph — Organization, WebSite, Person, Article cross-referenced
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Emit minimal JSON-LD @graph. Extensible via `lean_seo_jsonld_graph` filter.
 *
 * @param int    $post_id     Post ID (0 if not singular).
 * @param string $url         Canonical/current URL.
 * @param string $title       Document title.
 * @param string $description Meta description.
 * @param string $og_image    OG image URL.
 * @param string $og_type     og:type value.
 * @return void
 */
function lean_seo_emit_jsonld( $post_id, $url, $title, $description, $og_image, $og_type ) {
	$site_url = home_url( '/' );
	$site_id  = $site_url . '#website';
	$org_id   = $site_url . '#organization';

	$graph = array();

	// ── Site Person — personal brand (v1.5.0) ────────────────────────────────
	// When lean_seo_person_name is set, this site is a personal brand.
	// The Person node becomes the primary publisher/author entity.
	// If both Person and Organization are configured (e.g. consultant + company),
	// Person.worksFor references the Organization, and Person is the publisher.
	// If only Organization is set (e.g. eco/media), behavior is unchanged.
	$site_person = lean_seo_get_site_person_node( $site_url );
	$has_site_person = ! empty( $site_person );

	// The entity used as publisher for WebSite and Article nodes.
	// Personal brand → #person.  Media/org site → #organization.
	$publisher_ref = $has_site_person
		? array( '@id' => $site_url . '#person' )
		: array( '@id' => $org_id );

	// ── Organization ─────────────────────────────────────────────────────────
	// Always emitted (used as the institutional anchor even on personal sites).
	// On personal brand sites, the org name defaults to site name and Person
	// references it via worksFor when set.
	$org_type = get_option( 'lean_seo_org_type', '' );
	$org = array(
		'@type' => ( 'NewsMediaOrganization' === $org_type ) ? 'NewsMediaOrganization' : 'Organization',
		'@id'   => $org_id,
		'name'  => get_bloginfo( 'name' ),
		'url'   => $site_url,
	);

	$org_logo = get_option( 'lean_seo_org_logo', '' );
	if ( ! $org_logo ) {
		$org_logo = apply_filters( 'lean_seo_organization_logo', '' );
	}
	if ( $org_logo ) {
		$org['logo'] = array( '@type' => 'ImageObject', 'url' => $org_logo );
	}

	$org_desc = get_option( 'lean_seo_org_description', '' );
	if ( $org_desc ) {
		$org['description'] = $org_desc;
	}

	$org_founding = get_option( 'lean_seo_org_founding_date', '' );
	if ( $org_founding ) {
		$org['foundingDate'] = $org_founding;
	}

	$org_founder_name = get_option( 'lean_seo_org_founder_name', '' );
	if ( $org_founder_name ) {
		$founder = array( '@type' => 'Person', 'name' => $org_founder_name );
		$founder_urls = lean_seo_parse_same_as( get_option( 'lean_seo_org_founder_sameas', '' ) );
		if ( $founder_urls ) {
			$founder['sameAs'] = $founder_urls;
		}
		$org['founder'] = $founder;
	}

	$org_email = get_option( 'lean_seo_org_contact_email', '' );
	if ( $org_email ) {
		$org['contactPoint'] = array(
			'@type'       => 'ContactPoint',
			'contactType' => 'customer support',
			'email'       => $org_email,
		);
	}

	$org_same_as = lean_seo_get_org_same_as();
	if ( $org_same_as ) {
		$org['sameAs'] = $org_same_as;
	}
	$graph[] = $org;

	// Emit the site Person node (personal brand) after Organization so the
	// worksFor reference to #organization resolves cleanly in the same @graph.
	if ( $has_site_person ) {
		// Link Person to Organization when org name is explicitly configured
		// (i.e. the person works for / represents a distinct org entity).
		$org_name_explicit = trim( (string) get_option( 'lean_seo_org_logo', '' ) )
			|| trim( (string) get_option( 'lean_seo_org_description', '' ) )
			|| trim( (string) get_option( 'lean_seo_org_founding_date', '' ) );
		// Simpler signal: only add worksFor when org has any enrichment beyond site name.
		if ( $org_name_explicit ) {
			$site_person['worksFor'] = array( '@id' => $org_id );
		}
		$graph[] = $site_person;
	}

	// ── WebSite + SearchAction ────────────────────────────────────────────────
	$graph[] = array(
		'@type'           => 'WebSite',
		'@id'             => $site_id,
		'url'             => $site_url,
		'name'            => get_bloginfo( 'name' ),
		'publisher'       => $publisher_ref,
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $site_url . '?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		),
	);

	// ── Article (only on singular) ────────────────────────────────────────────
	if ( $post_id ) {
		$post = get_post( $post_id );
		// Resolution: per-post meta > per-post-type default (via filter) > "Article".
		// `false` from the filter disables the Article node entirely.
		$type = $post ? lean_seo_get( $post_id, 'article_type' ) : '';
		if ( $post && ! $type ) {
			$type = apply_filters( 'lean_seo_default_article_type', 'Article', $post_id, $post->post_type );
		}
		if ( $post && false !== $type ) {
			// Author node: when a site Person is configured (personal brand),
			// that Person IS the author — we reference #person directly rather
			// than emitting a separate dynamic author node (which would collide
			// on personal sites where every post has the same author anyway).
			// On media/org sites without a site Person, use the dynamic author
			// node as before.
			if ( $has_site_person ) {
				$author_ref = array( '@id' => $site_url . '#person' );
			} else {
				$wp_author = get_userdata( $post->post_author );
				$person_id = $site_url . '#author-' . ( $wp_author ? $wp_author->ID : '0' );

				if ( $wp_author ) {
					$person_node = array(
						'@type' => 'Person',
						'@id'   => $person_id,
						'name'  => $wp_author->display_name,
						'url'   => get_author_posts_url( $wp_author->ID ),
					);
					$same_as = lean_seo_get_same_as();
					if ( $same_as ) {
						$person_node['sameAs'] = $same_as;
					}
					$graph[] = $person_node;
				}
				$author_ref = $wp_author ? array( '@id' => $person_id ) : null;
			}

			$article = array(
				'@type'            => $type,
				'@id'              => $url . '#article',
				'headline'         => $title,
				'description'      => $description,
				'url'              => $url,
				'datePublished'    => get_the_date( 'c', $post_id ),
				'dateModified'     => get_the_modified_date( 'c', $post_id ),
				'mainEntityOfPage' => array( '@id' => $url ),
				'isPartOf'         => array( '@id' => $site_id ),
				'publisher'        => $publisher_ref,
			);
			if ( $author_ref ) {
				$article['author'] = $author_ref;
			}
			if ( $og_image ) {
				$article['image'] = array(
					'@type' => 'ImageObject',
					'url'   => $og_image,
				);
			}
			$graph[] = $article;
		}
	}

	// FAQPage schema — only when post has opt-in FAQ meta.
	if ( $post_id ) {
		$faq_raw = lean_seo_get( $post_id, 'faq' );
		if ( $faq_raw ) {
			$faq_items = json_decode( $faq_raw, true );
			if ( is_array( $faq_items ) && $faq_items ) {
				$accepted = array();
				foreach ( $faq_items as $item ) {
					$q = isset( $item['q'] ) ? trim( $item['q'] ) : '';
					$a = isset( $item['a'] ) ? trim( $item['a'] ) : '';
					if ( $q && $a ) {
						$accepted[] = array(
							'@type'          => 'Question',
							'name'           => $q,
							'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $a ),
						);
					}
				}
				if ( $accepted ) {
					$graph[] = array(
						'@type'            => 'FAQPage',
						'@id'              => $url . '#faq',
						'mainEntity'       => $accepted,
					);
				}
			}
		}
	}

	// HowTo schema — only when post has opt-in HowTo meta.
	if ( $post_id ) {
		$howto_raw = lean_seo_get( $post_id, 'howto' );
		if ( $howto_raw ) {
			$howto_data = json_decode( $howto_raw, true );
			if ( is_array( $howto_data ) && ! empty( $howto_data['steps'] ) ) {
				$steps = array();
				foreach ( $howto_data['steps'] as $idx => $step ) {
					$sname = isset( $step['name'] ) ? trim( $step['name'] ) : '';
					$stext = isset( $step['text'] ) ? trim( $step['text'] ) : '';
					if ( ! $sname && ! $stext ) continue;
					$s = array(
						'@type'    => 'HowToStep',
						'position' => $idx + 1,
						'name'     => $sname ?: $stext,
						'text'     => $stext ?: $sname,
					);
					if ( ! empty( $step['img'] ) ) {
						$s['image'] = array( '@type' => 'ImageObject', 'url' => esc_url_raw( $step['img'] ) );
					}
					$steps[] = $s;
				}
				if ( $steps ) {
					$node = array(
						'@type' => 'HowTo',
						'@id'   => $url . '#howto',
						'name'  => isset( $howto_data['name'] ) && $howto_data['name'] ? $howto_data['name'] : $title,
						'step'  => $steps,
					);
					if ( ! empty( $howto_data['desc'] ) ) {
						$node['description'] = $howto_data['desc'];
					}
					$graph[] = $node;
				}
			}
		}
	}

	// Breadcrumbs (if available).
	$crumbs = lean_seo_get_breadcrumbs();
	if ( $crumbs && count( $crumbs ) > 1 ) {
		$items = array();
		foreach ( $crumbs as $i => $c ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $c['label'],
				'item'     => $c['url'] ? $c['url'] : null,
			);
		}
		$graph[] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	$graph = apply_filters( 'lean_seo_jsonld_graph', $graph, $post_id, $url );

	if ( empty( $graph ) ) {
		return;
	}

	$doc = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}

/* ═══════════════════════════════════════════════════════════════════════════
   SCHEMA MAPPING — resolve default article_type by category or post_type.
   Stored in `lean_seo_schema_map` wp_option, editable from Settings → Lean SEO.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Resolve the default JSON-LD type for a post by walking the category tree
 * (with inheritance) and falling back to per-post-type mapping.
 *
 * Priority: category mapping (ancestor-aware) > post_type mapping > "Article".
 *
 * @param string $default  Default article_type (passed by lean_seo_default_article_type filter).
 * @param int    $post_id  Post ID.
 * @param string $post_type Post type.
 * @return string|false
 */
add_filter( 'lean_seo_default_article_type', 'lean_seo_resolve_default_type', 10, 3 );

function lean_seo_resolve_default_type( $default, $post_id, $post_type ) {
	$map = get_option( 'lean_seo_schema_map', array() );

	// 1. Category-based mapping (only for post type that has categories taxonomy).
	if ( is_object_in_taxonomy( $post_type, 'category' ) ) {
		$cat_map = isset( $map['category'] ) && is_array( $map['category'] ) ? $map['category'] : array();
		if ( $cat_map ) {
			$post_cats = wp_get_post_categories( $post_id );
			foreach ( $post_cats as $cat_id ) {
				$check = array( $cat_id );
				$check = array_merge( $check, get_ancestors( $cat_id, 'category' ) );
				foreach ( $check as $cid ) {
					if ( isset( $cat_map[ $cid ] ) && $cat_map[ $cid ] !== '' ) {
						return $cat_map[ $cid ];
					}
				}
			}
		}
	}

	// 2. Post-type mapping.
	$pt_map = isset( $map['post_type'] ) && is_array( $map['post_type'] ) ? $map['post_type'] : array();
	if ( isset( $pt_map[ $post_type ] ) && $pt_map[ $post_type ] !== '' ) {
		return $pt_map[ $post_type ];
	}

	return $default;
}

/* ═══════════════════════════════════════════════════════════════════════════
   SETTINGS PAGE — Settings → Lean SEO. Tiny table-based UI.
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_menu', 'lean_seo_register_settings_page' );

function lean_seo_register_settings_page() {
	add_options_page(
		'Lean SEO',
		'Lean SEO',
		'manage_options',
		'lean-seo',
		'lean_seo_render_settings_page'
	);
}

add_action( 'admin_init', 'lean_seo_register_settings' );

function lean_seo_register_settings() {
	register_setting( 'lean_seo', 'lean_seo_schema_map', array(
		'type'              => 'array',
		'sanitize_callback' => 'lean_seo_sanitize_schema_map',
		'default'           => array( 'category' => array(), 'post_type' => array() ),
	) );
	register_setting( 'lean_seo', 'lean_seo_same_as', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_org_same_as', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_llmstxt_enabled', array(
		'type'              => 'string',
		'sanitize_callback' => function( $v ) { return ( '1' === $v ) ? '1' : '0'; },
		'default'           => '1',
	) );
	register_setting( 'lean_seo', 'lean_seo_llmstxt', array(
		'type'              => 'array',
		'sanitize_callback' => 'lean_seo_sanitize_llmstxt_opts',
		'default'           => array( 'include_pages' => 1, 'include_posts' => 1, 'posts_count' => 20 ),
	) );
	register_setting( 'lean_seo', 'lean_seo_indexnow_key', array(
		'type'              => 'string',
		'sanitize_callback' => 'lean_seo_sanitize_indexnow_key',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_ai_crawlers', array(
		'type'              => 'array',
		'sanitize_callback' => 'lean_seo_sanitize_ai_crawlers',
		'default'           => array(),
	) );
	register_setting( 'lean_seo', 'lean_seo_llmsfull_enabled', array(
		'type'              => 'string',
		'sanitize_callback' => function( $v ) { return ( '1' === $v ) ? '1' : '0'; },
		'default'           => '0',
	) );
	register_setting( 'lean_seo', 'lean_seo_llmsfull', array(
		'type'              => 'array',
		'sanitize_callback' => 'lean_seo_sanitize_llmsfull_opts',
		'default'           => array( 'posts_count' => 10, 'chars_per_post' => 3000 ),
	) );
	register_setting( 'lean_seo', 'lean_seo_image_sitemap_enabled', array(
		'type'              => 'string',
		'sanitize_callback' => function( $v ) { return ( '1' === $v ) ? '1' : '0'; },
		'default'           => '1',
	) );
	register_setting( 'lean_seo', 'lean_seo_rank_math_fallback', array(
		'type'              => 'string',
		'sanitize_callback' => function( $v ) { return ( '1' === $v ) ? '1' : '0'; },
		'default'           => '0',
	) );
	// v1.4.0 — Organization enrichment.
	register_setting( 'lean_seo', 'lean_seo_org_type', array(
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			return in_array( $v, array( 'Organization', 'NewsMediaOrganization' ), true ) ? $v : 'Organization';
		},
		'default'           => 'Organization',
	) );
	register_setting( 'lean_seo', 'lean_seo_org_logo', array(
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_org_description', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_org_founding_date', array(
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$v = sanitize_text_field( $v );
			// Accept YYYY or YYYY-MM or YYYY-MM-DD.
			return preg_match( '/^\d{4}(-\d{2}(-\d{2})?)?$/', $v ) ? $v : '';
		},
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_org_founder_name', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_org_founder_sameas', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_org_contact_email', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_email',
		'default'           => '',
	) );
	// v1.5.0 — Site Person (personal brand entity).
	register_setting( 'lean_seo', 'lean_seo_person_name', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_person_url', array(
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_person_image', array(
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_person_job_title', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_person_description', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );
	register_setting( 'lean_seo', 'lean_seo_person_sameas', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );
}

function lean_seo_sanitize_llmstxt_opts( $input ) {
	$out = array( 'include_pages' => 1, 'include_posts' => 1, 'posts_count' => 20 );
	if ( ! is_array( $input ) ) return $out;
	$out['include_pages'] = empty( $input['include_pages'] ) ? 0 : 1;
	$out['include_posts'] = empty( $input['include_posts'] ) ? 0 : 1;
	$out['posts_count']   = max( 1, min( 100, (int) ( $input['posts_count'] ?? 20 ) ) );
	return $out;
}

function lean_seo_sanitize_indexnow_key( $input ) {
	// Key must be 8–128 hex chars per IndexNow spec.
	$key = preg_replace( '/[^a-f0-9]/i', '', strtolower( sanitize_text_field( $input ) ) );
	if ( strlen( $key ) < 8 ) return '';
	return $key;
}

/**
 * Known AI crawlers with their default rule (allow = true).
 *
 * @return array<string, array{label:string, default:bool}>
 */
function lean_seo_ai_crawlers_registry() {
	return array(
		'GPTBot'          => array( 'label' => 'GPTBot (OpenAI)',              'default' => true ),
		'ClaudeBot'       => array( 'label' => 'ClaudeBot (Anthropic)',        'default' => true ),
		'PerplexityBot'   => array( 'label' => 'PerplexityBot (Perplexity)',   'default' => true ),
		'Google-Extended' => array( 'label' => 'Google-Extended (Gemini/SGE)', 'default' => true ),
		'CCBot'           => array( 'label' => 'CCBot (Common Crawl / AI)',    'default' => true ),
		'Applebot-Extended' => array( 'label' => 'Applebot-Extended (Apple AI)', 'default' => true ),
		'YouBot'          => array( 'label' => 'YouBot (You.com)',             'default' => true ),
		'anthropic-ai'    => array( 'label' => 'anthropic-ai (secondary UA)',  'default' => true ),
		'cohere-ai'       => array( 'label' => 'cohere-ai (Cohere)',           'default' => true ),
	);
}

function lean_seo_sanitize_ai_crawlers( $input ) {
	$registry = lean_seo_ai_crawlers_registry();
	$out = array();
	if ( ! is_array( $input ) ) return $out;
	foreach ( $registry as $bot => $info ) {
		if ( isset( $input[ $bot ] ) ) {
			$out[ $bot ] = ( '1' === $input[ $bot ] ) ? '1' : '0';
		}
	}
	return $out;
}

function lean_seo_sanitize_llmsfull_opts( $input ) {
	$out = array( 'posts_count' => 10, 'chars_per_post' => 3000 );
	if ( ! is_array( $input ) ) return $out;
	$out['posts_count']    = max( 1, min( 50, (int) ( $input['posts_count']    ?? 10 ) ) );
	$out['chars_per_post'] = max( 500, min( 10000, (int) ( $input['chars_per_post'] ?? 3000 ) ) );
	return $out;
}

function lean_seo_sanitize_schema_map( $input ) {
	$out = array( 'category' => array(), 'post_type' => array() );
	if ( ! is_array( $input ) ) return $out;
	foreach ( array( 'category', 'post_type' ) as $bucket ) {
		if ( ! isset( $input[ $bucket ] ) || ! is_array( $input[ $bucket ] ) ) continue;
		foreach ( $input[ $bucket ] as $key => $val ) {
			$val = sanitize_text_field( $val );
			if ( $val === '' ) continue; // skip empties
			if ( $bucket === 'category' ) {
				$cid = intval( $key );
				if ( $cid > 0 ) $out['category'][ $cid ] = $val;
			} else {
				$pt = sanitize_key( $key );
				if ( $pt ) $out['post_type'][ $pt ] = $val;
			}
		}
	}
	return $out;
}

function lean_seo_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$map = get_option( 'lean_seo_schema_map', array( 'category' => array(), 'post_type' => array() ) );
	$cat_map = isset( $map['category'] ) ? $map['category'] : array();
	$pt_map  = isset( $map['post_type'] ) ? $map['post_type'] : array();

	// Options v1.2.
	$same_as         = get_option( 'lean_seo_same_as', '' );
	$llmstxt_enabled = get_option( 'lean_seo_llmstxt_enabled', '1' );
	$llmstxt_opts    = get_option( 'lean_seo_llmstxt', array( 'include_pages' => 1, 'include_posts' => 1, 'posts_count' => 20 ) );
	$indexnow_key    = get_option( 'lean_seo_indexnow_key', '' );
	// Options v1.3.
	$ai_crawlers          = get_option( 'lean_seo_ai_crawlers', array() );
	$llmsfull_enabled     = get_option( 'lean_seo_llmsfull_enabled', '0' );
	$llmsfull_opts        = get_option( 'lean_seo_llmsfull', array( 'posts_count' => 10, 'chars_per_post' => 3000 ) );
	$img_sitemap_enabled  = get_option( 'lean_seo_image_sitemap_enabled', '1' );
	$rm_fallback          = get_option( 'lean_seo_rank_math_fallback', '0' );
	$org_same_as            = get_option( 'lean_seo_org_same_as', '' );
	// Options v1.4.
	$org_type               = get_option( 'lean_seo_org_type', 'Organization' );
	$org_logo_opt           = get_option( 'lean_seo_org_logo', '' );
	$org_description        = get_option( 'lean_seo_org_description', '' );
	$org_founding_date      = get_option( 'lean_seo_org_founding_date', '' );
	$org_founder_name       = get_option( 'lean_seo_org_founder_name', '' );
	$org_founder_sameas     = get_option( 'lean_seo_org_founder_sameas', '' );
	$org_contact_email      = get_option( 'lean_seo_org_contact_email', '' );
	// Options v1.5 — site Person (personal brand).
	$person_name            = get_option( 'lean_seo_person_name', '' );
	$person_url             = get_option( 'lean_seo_person_url', '' );
	$person_image           = get_option( 'lean_seo_person_image', '' );
	$person_job_title       = get_option( 'lean_seo_person_job_title', '' );
	$person_description     = get_option( 'lean_seo_person_description', '' );
	$person_sameas          = get_option( 'lean_seo_person_sameas', '' );

	$article_types = apply_filters( 'lean_seo_article_types', array(
		''                       => '(default Article)',
		'Article'                => 'Article',
		'NewsArticle'            => 'NewsArticle',
		'BlogPosting'            => 'BlogPosting',
		'TechArticle'            => 'TechArticle',
		'OpinionNewsArticle'     => 'OpinionNewsArticle',
		'AnalysisNewsArticle'    => 'AnalysisNewsArticle',
		'ReportageNewsArticle'   => 'ReportageNewsArticle',
		'ScholarlyArticle'       => 'ScholarlyArticle',
		'Report'                 => 'Report',
		'Event'                  => 'Event',
		'JobPosting'             => 'JobPosting',
		'DefinedTerm'            => 'DefinedTerm',
		'PodcastEpisode'         => 'PodcastEpisode',
		'VideoObject'            => 'VideoObject',
		'Person'                 => 'Person',
	) );

	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	$categories = get_categories( array( 'hide_empty' => false, 'orderby' => 'name' ) );

	?>
	<div class="wrap">
		<h1>Lean SEO</h1>
		<p>Mapeá qué schema.org type emitir por defecto para cada categoría o tipo de contenido. La prioridad es: <strong>meta por post</strong> &gt; <strong>categoría</strong> (con herencia de árbol) &gt; <strong>tipo de contenido</strong> &gt; <code>Article</code>.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'lean_seo' ); ?>

			<h2>Mapeo por categoría</h2>
			<p class="description">El schema se aplica a posts de esa categoría <em>y a todos sus descendientes</em>. Solo se muestran categorías con posts.</p>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th style="width:60%">Categoría</th><th>JSON-LD type</th></tr></thead>
				<tbody>
				<?php foreach ( $categories as $cat ): if ( $cat->count == 0 ) continue; ?>
					<tr>
						<td><?php echo esc_html( $cat->name ); ?> <code style="opacity:.5">#<?php echo (int) $cat->term_id; ?> · <?php echo (int) $cat->count; ?> posts</code></td>
						<td>
							<select name="lean_seo_schema_map[category][<?php echo (int) $cat->term_id; ?>]">
								<?php foreach ( $article_types as $val => $label ): ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( isset( $cat_map[ $cat->term_id ] ) ? $cat_map[ $cat->term_id ] : '', $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px">Mapeo por tipo de contenido</h2>
			<p class="description">Fallback cuando ninguna categoría coincide. Útil para CPTs (glosario, eventos, convocatorias, etc).</p>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th style="width:60%">Post type</th><th>JSON-LD type</th></tr></thead>
				<tbody>
				<?php foreach ( $post_types as $pt ): ?>
					<tr>
						<td><?php echo esc_html( $pt->labels->singular_name ); ?> <code style="opacity:.5"><?php echo esc_html( $pt->name ); ?></code></td>
						<td>
							<select name="lean_seo_schema_map[post_type][<?php echo esc_attr( $pt->name ); ?>]">
								<?php foreach ( $article_types as $val => $label ): ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( isset( $pt_map[ $pt->name ] ) ? $pt_map[ $pt->name ] : '', $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px">Organization (datos estructurados)</h2>
			<p class="description">Enriquecé el nodo <code>Organization</code> del JSON-LD <code>@graph</code>. Todos los campos son opt-in: solo se emiten si tienen valor.</p>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th style="width:36%">Campo</th><th>Valor</th></tr></thead>
				<tbody>
				<tr>
					<td><strong>Tipo</strong> <code>@type</code><br><span style="font-size:12px;color:#666">Organization = genérico · NewsMediaOrganization = medios/editoriales</span></td>
					<td>
						<select name="lean_seo_org_type">
							<option value="Organization" <?php selected( $org_type, 'Organization' ); ?>>Organization (default)</option>
							<option value="NewsMediaOrganization" <?php selected( $org_type, 'NewsMediaOrganization' ); ?>>NewsMediaOrganization</option>
						</select>
					</td>
				</tr>
				<tr>
					<td><strong>Logo</strong> <code>logo</code><br><span style="font-size:12px;color:#666">URL de imagen. Recomendado: 112×112 px mínimo, fondo no transparente.</span></td>
					<td><input type="url" name="lean_seo_org_logo" value="<?php echo esc_attr( $org_logo_opt ); ?>" placeholder="https://example.com/logo.png" style="width:100%" /></td>
				</tr>
				<tr>
					<td><strong>Descripción</strong> <code>description</code></td>
					<td><textarea name="lean_seo_org_description" rows="2" style="width:100%"><?php echo esc_textarea( $org_description ); ?></textarea></td>
				</tr>
				<tr>
					<td><strong>Fecha de fundación</strong> <code>foundingDate</code><br><span style="font-size:12px;color:#666">Formato ISO: YYYY, YYYY-MM o YYYY-MM-DD</span></td>
					<td><input type="text" name="lean_seo_org_founding_date" value="<?php echo esc_attr( $org_founding_date ); ?>" placeholder="YYYY-MM-DD" style="width:160px;font-family:monospace" /></td>
				</tr>
				<tr>
					<td><strong>Fundador — nombre</strong> <code>founder.name</code></td>
					<td><input type="text" name="lean_seo_org_founder_name" value="<?php echo esc_attr( $org_founder_name ); ?>" placeholder="Jane Doe" style="width:100%" /></td>
				</tr>
				<tr>
					<td><strong>Fundador — perfiles</strong> <code>founder.sameAs</code><br><span style="font-size:12px;color:#666">Una URL por línea. Solo se emite si hay nombre de fundador.</span></td>
					<td><textarea name="lean_seo_org_founder_sameas" rows="4" style="width:100%;font-family:monospace"><?php echo esc_textarea( $org_founder_sameas ); ?></textarea></td>
				</tr>
				<tr>
					<td><strong>Email de contacto</strong> <code>contactPoint.email</code><br><span style="font-size:12px;color:#666">Se emite como <code>ContactPoint</code> con <code>contactType: customer support</code>.</span></td>
					<td><input type="email" name="lean_seo_org_contact_email" value="<?php echo esc_attr( $org_contact_email ); ?>" placeholder="hello@example.com" style="width:100%" /></td>
				</tr>
				<tr>
					<td><strong>Redes sociales</strong> <code>sameAs</code><br><span style="font-size:12px;color:#666">Perfiles IG/LI/FB/YT de la organización. Una URL por línea.</span></td>
					<td><textarea name="lean_seo_org_same_as" rows="5" style="width:100%;font-family:monospace"><?php echo esc_textarea( $org_same_as ); ?></textarea></td>
				</tr>
				</tbody>
			</table>

			<h2 style="margin-top:24px">Person — entidad del sitio (marca personal)</h2>
			<p class="description">Para sitios de <strong>marca personal</strong> (portfolio, blog de autor, creador). Cuando se configura el nombre, se emite un nodo <code>Person</code> con <code>@id #person</code> como entidad principal del knowledge graph: referenciada como <code>publisher</code> y <code>author</code> en WebSite y Article.<br>
			<span style="font-size:12px;color:#666">Dejar vacío si el sitio es una organización o un medio — en ese caso Organization sigue siendo el publisher.</span></p>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th style="width:36%">Campo</th><th>Valor</th></tr></thead>
				<tbody>
				<tr>
					<td><strong>Nombre</strong> <code>name</code> <span style="color:#c00">★</span><br><span style="font-size:12px;color:#666">Requerido para activar esta sección. Si está vacío, toda la sección se ignora.</span></td>
					<td><input type="text" name="lean_seo_person_name" value="<?php echo esc_attr( $person_name ); ?>" placeholder="Jane Doe" style="width:100%" /></td>
				</tr>
				<tr>
					<td><strong>URL</strong> <code>url</code><br><span style="font-size:12px;color:#666">Default: URL raíz del sitio.</span></td>
					<td><input type="url" name="lean_seo_person_url" value="<?php echo esc_attr( $person_url ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" style="width:100%" /></td>
				</tr>
				<tr>
					<td><strong>Imagen</strong> <code>image</code><br><span style="font-size:12px;color:#666">URL de foto de perfil o avatar. Recomendado: cuadrada, mínimo 96×96 px.</span></td>
					<td><input type="url" name="lean_seo_person_image" value="<?php echo esc_attr( $person_image ); ?>" placeholder="https://example.com/photo.jpg" style="width:100%" /></td>
				</tr>
				<tr>
					<td><strong>Job title</strong> <code>jobTitle</code></td>
					<td><input type="text" name="lean_seo_person_job_title" value="<?php echo esc_attr( $person_job_title ); ?>" placeholder="Founder, Entrepreneur, Developer…" style="width:100%" /></td>
				</tr>
				<tr>
					<td><strong>Descripción</strong> <code>description</code></td>
					<td><textarea name="lean_seo_person_description" rows="2" style="width:100%"><?php echo esc_textarea( $person_description ); ?></textarea></td>
				</tr>
				<tr>
					<td><strong>Perfiles sociales</strong> <code>sameAs</code><br><span style="font-size:12px;color:#666">Una URL por línea. LinkedIn, Twitter/X, GitHub, Instagram, Skool, etc.</span></td>
					<td><textarea name="lean_seo_person_sameas" rows="6" style="width:100%;font-family:monospace"><?php echo esc_textarea( $person_sameas ); ?></textarea></td>
				</tr>
				</tbody>
			</table>

			<h2 style="margin-top:24px">JSON-LD — Person sameAs (autor del post)</h2>
			<p class="description">Perfiles del <strong>autor principal</strong> en sitios single-author donde NO se usa la sección "Person — marca personal" de arriba. Una URL por línea. Se emiten en el nodo <code>Person</code> dinámico del autor del post.<br>
			<span style="color:#b85c00;font-size:12px">Nota: si configuraste la sección "Person — marca personal" arriba, este campo se ignora (el nodo del sitio toma prioridad). En sitios multi-autor, dejar vacío y usar Organization sameAs.</span></p>
			<textarea name="lean_seo_same_as" rows="5" style="width:100%;max-width:720px;font-family:monospace"><?php echo esc_textarea( $same_as ); ?></textarea>

			<h2 style="margin-top:24px">llms.txt</h2>
			<p class="description">Sirve <code>/llms.txt</code> para que los crawlers de LLMs indexen el contenido del sitio. <a href="https://llmstxt.org" target="_blank" rel="noopener">Estándar llmstxt.org</a>.</p>
			<table class="form-table" style="max-width:720px">
				<tr><th scope="row">Habilitar</th><td>
					<input type="hidden" name="lean_seo_llmstxt_enabled" value="0" />
					<label><input type="checkbox" name="lean_seo_llmstxt_enabled" value="1" <?php checked( $llmstxt_enabled, '1' ); ?> /> Activar <code>/llms.txt</code></label>
				</td></tr>
				<tr><th scope="row">Incluir páginas</th><td>
					<label><input type="checkbox" name="lean_seo_llmstxt[include_pages]" value="1" <?php checked( ! empty( $llmstxt_opts['include_pages'] ) ); ?> /> Sección "Pages" (hasta 20 páginas estáticas)</label>
				</td></tr>
				<tr><th scope="row">Incluir posts</th><td>
					<label><input type="checkbox" name="lean_seo_llmstxt[include_posts]" value="1" <?php checked( ! empty( $llmstxt_opts['include_posts'] ) ); ?> /> Sección "Recent posts"</label>
					<input type="number" name="lean_seo_llmstxt[posts_count]" value="<?php echo (int) ( $llmstxt_opts['posts_count'] ?? 20 ); ?>" min="1" max="100" style="width:70px;margin-left:8px" />
					<span class="description"> posts (máx. 100)</span>
				</td></tr>
				<tr><th scope="row">Preview</th><td>
					<?php if ( $llmstxt_enabled ): ?>
					<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_url( home_url( '/llms.txt' ) ); ?></a>
					<br><small class="description">Guardá la configuración primero para actualizar la caché.</small>
					<?php else: ?><span class="description">Desactivado.</span><?php endif; ?>
				</td></tr>
			</table>

			<h2 style="margin-top:24px">IndexNow</h2>
			<p class="description">Notifica automáticamente a Bing / Yandex / Naver cuando publicás o actualizás contenido. <strong>No es Google</strong> — para Google usá Google Search Console / Indexing API.</p>
			<table class="form-table" style="max-width:720px">
				<tr><th scope="row">Key</th><td>
					<input type="text" name="lean_seo_indexnow_key" value="<?php echo esc_attr( $indexnow_key ); ?>" placeholder="abc123... (8–128 hex chars)" style="width:100%;max-width:420px;font-family:monospace" />
					<p class="description">8–128 caracteres hex. Si está vacío, IndexNow está desactivado.<br>
					El key file se sirve automáticamente en <code><?php echo esc_url( home_url( '/' ) ); ?><?php echo $indexnow_key ? esc_html( $indexnow_key ) : '{key}'; ?>.txt</code><br>
					Obtené tu key en <a href="https://www.indexnow.org/" target="_blank" rel="noopener">indexnow.org</a> o generá una string hex aleatoria de 32 chars.</p>
				</td></tr>
				<?php if ( $indexnow_key ): ?>
				<tr><th scope="row">Key file</th><td>
					<a href="<?php echo esc_url( home_url( '/' . $indexnow_key . '.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/' . $indexnow_key . '.txt' ) ); ?></a>
					<span class="description"> — debe devolver la key en texto plano.</span>
				</td></tr>
				<?php endif; ?>
			</table>

			<h2 style="margin-top:24px">AI Crawlers — robots.txt</h2>
			<p class="description"><strong>Default: PERMITIR todos.</strong> La estrategia de AEO (Answer Engine Optimization) es que los LLMs te citen. Solo desactivá si tenés razón editorial concreta. Se inyectan en el <code>robots.txt</code> generado por WP.</p>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th>Bot</th><th style="width:120px">Acción</th></tr></thead>
				<tbody>
				<?php foreach ( lean_seo_ai_crawlers_registry() as $bot => $info ): ?>
					<?php
					$saved = isset( $ai_crawlers[ $bot ] ) ? $ai_crawlers[ $bot ] : null;
					$is_allowed = ( null === $saved ) ? $info['default'] : ( '1' === $saved );
					?>
					<tr>
						<td><?php echo esc_html( $info['label'] ); ?> <code style="opacity:.5">User-agent: <?php echo esc_html( $bot ); ?></code></td>
						<td>
							<select name="lean_seo_ai_crawlers[<?php echo esc_attr( $bot ); ?>]">
								<option value="1" <?php selected( $is_allowed ); ?>>Allow</option>
								<option value="0" <?php selected( ! $is_allowed ); ?>>Disallow</option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px">Image Sitemap</h2>
			<p class="description">Sirve <code>/sitemap-images.xml</code> con las imágenes (featured + adjuntas) de los posts publicados. Ayuda a Google Imágenes a descubrir y re-indexar tus fotos.</p>
			<table class="form-table" style="max-width:720px">
				<tr><th scope="row">Habilitar</th><td>
					<input type="hidden" name="lean_seo_image_sitemap_enabled" value="0" />
					<label><input type="checkbox" name="lean_seo_image_sitemap_enabled" value="1" <?php checked( $img_sitemap_enabled, '1' ); ?> /> Activar <code>/sitemap-images.xml</code></label>
				</td></tr>
				<?php if ( $img_sitemap_enabled ): ?>
				<tr><th scope="row">URL</th><td>
					<a href="<?php echo esc_url( home_url( '/sitemap-images.xml' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_url( home_url( '/sitemap-images.xml' ) ); ?></a>
					<span class="description"> — caché 6h, se regenera al publicar.</span>
				</td></tr>
				<?php endif; ?>
			</table>

			<h2 style="margin-top:24px">llms-full.txt</h2>
			<p class="description">Versión extendida de <code>/llms.txt</code> que incluye el contenido en markdown de los posts más relevantes. Útil para LLMs que quieren contexto profundo. <strong>Default: desactivado</strong> — habilitar solo si tenés contenido sustancioso.</p>
			<table class="form-table" style="max-width:720px">
				<tr><th scope="row">Habilitar</th><td>
					<input type="hidden" name="lean_seo_llmsfull_enabled" value="0" />
					<label><input type="checkbox" name="lean_seo_llmsfull_enabled" value="1" <?php checked( $llmsfull_enabled, '1' ); ?> /> Activar <code>/llms-full.txt</code></label>
				</td></tr>
				<tr><th scope="row">Posts a incluir</th><td>
					<input type="number" name="lean_seo_llmsfull[posts_count]" value="<?php echo (int) ( $llmsfull_opts['posts_count'] ?? 10 ); ?>" min="1" max="50" style="width:70px" />
					<span class="description"> posts (máx. 50, por fecha desc)</span>
				</td></tr>
				<tr><th scope="row">Caracteres por post</th><td>
					<input type="number" name="lean_seo_llmsfull[chars_per_post]" value="<?php echo (int) ( $llmsfull_opts['chars_per_post'] ?? 3000 ); ?>" min="500" max="10000" step="500" style="width:90px" />
					<span class="description"> chars de contenido (500–10.000 · default 3.000)</span>
				</td></tr>
				<?php if ( $llmsfull_enabled ): ?>
				<tr><th scope="row">Preview</th><td>
					<a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_url( home_url( '/llms-full.txt' ) ); ?></a>
					<br><small class="description">Caché 12h. Se invalida al publicar/guardar.</small>
				</td></tr>
				<?php endif; ?>
			</table>

			<h2 style="margin-top:24px">Migración desde Rank Math</h2>
			<p class="description">Activá el fallback para que lean-seo lea automáticamente los meta de Rank Math (<code>rank_math_title</code>, <code>rank_math_description</code>, etc.) en posts donde los campos lean-seo estén vacíos. Útil durante la transición, antes de correr el script de migración completa.</p>
			<table class="form-table" style="max-width:720px">
				<tr><th scope="row">Fallback Rank Math</th><td>
					<input type="hidden" name="lean_seo_rank_math_fallback" value="0" />
					<label><input type="checkbox" name="lean_seo_rank_math_fallback" value="1" <?php checked( $rm_fallback, '1' ); ?> /> Leer <code>rank_math_*</code> como fallback cuando el campo lean-seo está vacío</label>
					<p class="description">Desactivar después de correr <code>migration/migrate-from-rank-math.php</code> y verificar. Este fallback agrega una lectura extra de postmeta por request en posts sin datos lean-seo.</p>
				</td></tr>
				<tr><th scope="row">Script de migración</th><td>
					<code>wp eval-file wp-content/plugins/lean-seo/migration/migrate-from-rank-math.php</code> (dry-run)<br>
					<code>wp eval-file wp-content/plugins/lean-seo/migration/migrate-from-rank-math.php -- --apply</code> (ejecutar)
					<p class="description">Copia <code>rank_math_*</code> → <code>_lean_seo_*</code> de forma idempotente, sin pisar valores existentes. Ver <code>migration/migrate-from-rank-math.php</code> para instrucciones completas.</p>
				</td></tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* ═══════════════════════════════════════════════════════════════════════════
   SCHEMA HELPERS — reusable nodes for CPT plugins to inject via
   `lean_seo_jsonld_graph` filter. Each returns a single JSON-LD node array.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Build a schema.org Event node. Useful for `eventos`/`tribe_events` CPT plugins.
 *
 * @param array $data Required: name, startDate. Optional: endDate, location, url,
 *                    description, image, organizer, eventStatus, eventAttendanceMode.
 * @return array
 */
function lean_seo_schema_event( $data ) {
	$node = array( '@type' => 'Event' );
	foreach ( array( 'name', 'startDate', 'endDate', 'description', 'url',
	                  'eventStatus', 'eventAttendanceMode', 'inLanguage' ) as $k ) {
		if ( ! empty( $data[ $k ] ) ) $node[ $k ] = $data[ $k ];
	}
	if ( ! empty( $data['location'] ) ) {
		// location can be a string (Place name) or already a structured array
		$node['location'] = is_array( $data['location'] ) ? $data['location'] : array(
			'@type' => 'Place',
			'name'  => $data['location'],
		);
	}
	if ( ! empty( $data['image'] ) ) {
		$node['image'] = array( '@type' => 'ImageObject', 'url' => $data['image'] );
	}
	if ( ! empty( $data['organizer'] ) ) {
		$node['organizer'] = is_array( $data['organizer'] ) ? $data['organizer'] : array(
			'@type' => 'Organization',
			'name'  => $data['organizer'],
		);
	}
	return $node;
}

/**
 * Build a schema.org DefinedTerm node. For glossary CPT plugins.
 *
 * @param array $data Required: name, description. Optional: url, termCode, inDefinedTermSet.
 * @return array
 */
function lean_seo_schema_defined_term( $data ) {
	$node = array( '@type' => 'DefinedTerm' );
	foreach ( array( 'name', 'description', 'url', 'termCode', 'inDefinedTermSet' ) as $k ) {
		if ( ! empty( $data[ $k ] ) ) $node[ $k ] = $data[ $k ];
	}
	return $node;
}

/**
 * Build a schema.org JobPosting node. For convocatorias / job CPT plugins.
 *
 * @param array $data Required: title, description, datePosted. Optional: validThrough,
 *                    hiringOrganization, jobLocation, employmentType, baseSalary, applicantLocationRequirements.
 * @return array
 */
function lean_seo_schema_job_posting( $data ) {
	$node = array( '@type' => 'JobPosting' );
	foreach ( array( 'title', 'description', 'datePosted', 'validThrough',
	                  'employmentType', 'applicantLocationRequirements', 'directApply' ) as $k ) {
		if ( ! empty( $data[ $k ] ) ) $node[ $k ] = $data[ $k ];
	}
	if ( ! empty( $data['hiringOrganization'] ) ) {
		$node['hiringOrganization'] = is_array( $data['hiringOrganization'] ) ? $data['hiringOrganization'] : array(
			'@type' => 'Organization',
			'name'  => $data['hiringOrganization'],
		);
	}
	if ( ! empty( $data['jobLocation'] ) ) {
		$node['jobLocation'] = is_array( $data['jobLocation'] ) ? $data['jobLocation'] : array(
			'@type' => 'Place',
			'address' => array( '@type' => 'PostalAddress', 'addressLocality' => $data['jobLocation'] ),
		);
	}
	if ( ! empty( $data['baseSalary'] ) && is_array( $data['baseSalary'] ) ) {
		$node['baseSalary'] = $data['baseSalary'];
	}
	return $node;
}

/**
 * Build a schema.org PodcastEpisode node. For podcast CPT plugins.
 *
 * @param array $data Required: name, url. Optional: datePublished, duration, description,
 *                    image, episodeNumber, seasonNumber, actor[], associatedMedia.
 * @return array
 */
function lean_seo_schema_podcast_episode( $data ) {
	$node = array( '@type' => 'PodcastEpisode' );
	foreach ( array( 'name', 'url', 'datePublished', 'duration', 'description',
	                  'episodeNumber', 'seasonNumber', 'inLanguage' ) as $k ) {
		if ( ! empty( $data[ $k ] ) ) $node[ $k ] = $data[ $k ];
	}
	if ( ! empty( $data['image'] ) ) {
		$node['image'] = is_array( $data['image'] ) ? $data['image']
			: array( '@type' => 'ImageObject', 'url' => $data['image'] );
	}
	if ( ! empty( $data['actor'] ) && is_array( $data['actor'] ) ) {
		$node['actor'] = $data['actor'];
	}
	if ( ! empty( $data['partOfSeries'] ) ) {
		$node['partOfSeries'] = is_array( $data['partOfSeries'] ) ? $data['partOfSeries'] : array(
			'@type' => 'PodcastSeries',
			'name'  => $data['partOfSeries'],
		);
	}
	if ( ! empty( $data['associatedMedia'] ) ) {
		$node['associatedMedia'] = $data['associatedMedia'];
	}
	return $node;
}

/**
 * Build a schema.org VideoObject node. For posts with embedded videos
 * (YouTube/Vimeo/auto-hosted). Required by Google for SERP video carousel.
 *
 * @param array $data Required: name, description, thumbnailUrl, uploadDate.
 *                    Recommended: duration (ISO 8601, e.g. PT1H2M30S), contentUrl, embedUrl, hasPart[] (chapters).
 * @return array
 */
function lean_seo_schema_video_object( $data ) {
	$node = array( '@type' => 'VideoObject' );
	foreach ( array( 'name', 'description', 'thumbnailUrl', 'uploadDate', 'duration',
	                  'contentUrl', 'embedUrl', 'inLanguage' ) as $k ) {
		if ( ! empty( $data[ $k ] ) ) $node[ $k ] = $data[ $k ];
	}
	// Chapter markers — Google shows these in SERP for video results
	if ( ! empty( $data['hasPart'] ) && is_array( $data['hasPart'] ) ) {
		$node['hasPart'] = $data['hasPart'];
	}
	if ( ! empty( $data['actor'] ) && is_array( $data['actor'] ) ) {
		$node['actor'] = $data['actor'];
	}
	return $node;
}

/**
 * Build a schema.org Person node. Useful for `actor` CPT or author bio plugins.
 *
 * @param array $data Required: name. Optional: url, image, jobTitle, worksFor, sameAs[], description.
 * @return array
 */
function lean_seo_schema_person( $data ) {
	$node = array( '@type' => 'Person' );
	foreach ( array( 'name', 'url', 'jobTitle', 'description', 'givenName', 'familyName' ) as $k ) {
		if ( ! empty( $data[ $k ] ) ) $node[ $k ] = $data[ $k ];
	}
	if ( ! empty( $data['image'] ) ) {
		$node['image'] = is_array( $data['image'] ) ? $data['image']
			: array( '@type' => 'ImageObject', 'url' => $data['image'] );
	}
	if ( ! empty( $data['worksFor'] ) ) {
		$node['worksFor'] = is_array( $data['worksFor'] ) ? $data['worksFor'] : array(
			'@type' => 'Organization',
			'name'  => $data['worksFor'],
		);
	}
	if ( ! empty( $data['sameAs'] ) && is_array( $data['sameAs'] ) ) {
		$node['sameAs'] = $data['sameAs']; // social profile URLs
	}
	return $node;
}

/* ═══════════════════════════════════════════════════════════════════════════
   BREADCRUMBS — public function `lean_seo_breadcrumbs()` for theme use
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Compute breadcrumb items for the current request.
 * Filterable via `lean_seo_breadcrumbs_items`.
 *
 * @return array<int, array{label:string,url:string}>
 */
function lean_seo_get_breadcrumbs() {
	$crumbs = array(
		array( 'label' => __( 'Inicio', 'lean-seo' ), 'url' => home_url( '/' ) ),
	);

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && 'post' === $post->post_type ) {
			$cats = get_the_category( $post->ID );
			if ( ! empty( $cats ) ) {
				$primary = $cats[0];
				$ancestors = array_reverse( get_ancestors( $primary->term_id, 'category' ) );
				foreach ( $ancestors as $aid ) {
					$a = get_term( $aid, 'category' );
					if ( $a && ! is_wp_error( $a ) ) {
						$crumbs[] = array( 'label' => $a->name, 'url' => get_term_link( $a ) );
					}
				}
				$crumbs[] = array( 'label' => $primary->name, 'url' => get_term_link( $primary ) );
			}
		} elseif ( $post && ! in_array( $post->post_type, array( 'page' ), true ) ) {
			$pt_obj = get_post_type_object( $post->post_type );
			if ( $pt_obj && ! empty( $pt_obj->has_archive ) ) {
				$crumbs[] = array(
					'label' => $pt_obj->labels->name,
					'url'   => get_post_type_archive_link( $post->post_type ),
				);
			}
		}
		if ( $post ) {
			$crumbs[] = array( 'label' => get_the_title( $post ), 'url' => '' );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term && isset( $term->taxonomy ) ) {
			$ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy ) );
			foreach ( $ancestors as $aid ) {
				$a = get_term( $aid, $term->taxonomy );
				if ( $a && ! is_wp_error( $a ) ) {
					$crumbs[] = array( 'label' => $a->name, 'url' => get_term_link( $a ) );
				}
			}
			$crumbs[] = array( 'label' => $term->name, 'url' => '' );
		}
	} elseif ( is_post_type_archive() ) {
		$pt = get_post_type();
		$obj = get_post_type_object( $pt );
		if ( $obj ) {
			$crumbs[] = array( 'label' => $obj->labels->name, 'url' => '' );
		}
	} elseif ( is_search() ) {
		$crumbs[] = array( 'label' => sprintf( __( 'Búsqueda: %s', 'lean-seo' ), get_search_query() ), 'url' => '' );
	} elseif ( is_404() ) {
		$crumbs[] = array( 'label' => __( '404', 'lean-seo' ), 'url' => '' );
	} elseif ( is_author() ) {
		$crumbs[] = array( 'label' => get_queried_object()->display_name, 'url' => '' );
	}

	return apply_filters( 'lean_seo_breadcrumbs_items', $crumbs );
}

/**
 * Build breadcrumbs HTML (returns string instead of echo).
 *
 * @param array $args Options: separator, class.
 * @return string
 */
function lean_seo_breadcrumbs_html( $args = array() ) {
	$crumbs = lean_seo_get_breadcrumbs();
	if ( count( $crumbs ) <= 1 ) {
		return '';
	}
	$sep   = isset( $args['separator'] ) ? $args['separator'] : '›';
	$class = isset( $args['class'] ) ? $args['class'] : 'lean-seo-breadcrumbs';

	$parts = array();
	$last  = count( $crumbs ) - 1;
	foreach ( $crumbs as $i => $c ) {
		if ( $i === $last || empty( $c['url'] ) ) {
			$parts[] = '<span aria-current="page">' . esc_html( $c['label'] ) . '</span>';
		} else {
			$parts[] = '<a href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['label'] ) . '</a>';
		}
	}
	return '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Migas de pan', 'lean-seo' ) . '">'
		. implode( ' <span aria-hidden="true">' . esc_html( $sep ) . '</span> ', $parts )
		. '</nav>';
}

/**
 * Render breadcrumbs HTML. Call from the theme: `lean_seo_breadcrumbs();`
 * Filterable via `lean_seo_breadcrumbs_html`.
 *
 * @param array $args Options: separator, class.
 * @return void
 */
function lean_seo_breadcrumbs( $args = array() ) {
	$crumbs = lean_seo_get_breadcrumbs();
	if ( count( $crumbs ) <= 1 ) {
		return;
	}
	$sep   = isset( $args['separator'] ) ? $args['separator'] : '›';
	$class = isset( $args['class'] ) ? $args['class'] : 'lean-seo-breadcrumbs';

	$parts = array();
	$last  = count( $crumbs ) - 1;
	foreach ( $crumbs as $i => $c ) {
		if ( $i === $last || empty( $c['url'] ) ) {
			$parts[] = '<span aria-current="page">' . esc_html( $c['label'] ) . '</span>';
		} else {
			$parts[] = '<a href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['label'] ) . '</a>';
		}
	}
	$html = '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Migas de pan', 'lean-seo' ) . '">'
		. implode( ' <span aria-hidden="true">' . esc_html( $sep ) . '</span> ', $parts )
		. '</nav>';

	echo apply_filters( 'lean_seo_breadcrumbs_html', $html, $crumbs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/* ═══════════════════════════════════════════════════════════════════════════
   BREADCRUMBS SHORTCODE — [lean_seo_breadcrumbs] for embed in content
   ═══════════════════════════════════════════════════════════════════════════ */

add_shortcode( 'lean_seo_breadcrumbs', 'lean_seo_breadcrumbs_shortcode' );

/**
 * Shortcode handler. Usage: [lean_seo_breadcrumbs] or [lean_seo_breadcrumbs separator="/" class="my-bc"]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function lean_seo_breadcrumbs_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'separator' => '›',
		'class'     => 'lean-seo-breadcrumbs',
	), $atts, 'lean_seo_breadcrumbs' );

	return lean_seo_breadcrumbs_html( $atts );
}

/* ═══════════════════════════════════════════════════════════════════════════
   BREADCRUMBS AUTO-INJECT — optional, off by default
   Inject before post content via `the_content` filter. Useful as a drop-in
   replacement for SmartCrawl/Yoast breadcrumb injection without touching theme.
   Enable with: `add_filter( 'lean_seo_auto_inject_breadcrumbs', '__return_true' );`
   ═══════════════════════════════════════════════════════════════════════════ */

add_filter( 'the_content', 'lean_seo_maybe_inject_breadcrumbs', 5 );

/**
 * Prepend breadcrumbs to the_content on singular views when auto-inject is enabled.
 *
 * @param string $content Post content HTML.
 * @return string
 */
function lean_seo_maybe_inject_breadcrumbs( $content ) {
	if ( ! is_singular() || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}
	if ( ! apply_filters( 'lean_seo_auto_inject_breadcrumbs', false ) ) {
		return $content;
	}
	$html = lean_seo_breadcrumbs_html();
	if ( ! $html ) {
		return $content;
	}
	return $html . "\n" . $content;
}

/* ═══════════════════════════════════════════════════════════════════════════
   SITEMAP — augment WP-native wp-sitemap.xml with <lastmod>
   ═══════════════════════════════════════════════════════════════════════════ */

add_filter( 'wp_sitemaps_posts_entry', 'lean_seo_sitemap_lastmod', 10, 3 );

/**
 * Add <lastmod> to WP-native sitemap entries. WP doesn't include it by default,
 * but Google uses it for crawl prioritization.
 *
 * @param array  $entry     Sitemap entry.
 * @param WP_Post $post     Post object.
 * @param string $post_type Post type.
 * @return array
 */
function lean_seo_sitemap_lastmod( $entry, $post, $post_type ) {
	$entry['lastmod'] = get_post_modified_time( DATE_W3C, true, $post );
	return $entry;
}

/* ═══════════════════════════════════════════════════════════════════════════
   ADMIN — minimal meta box + conflict notice
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_notices', 'lean_seo_conflict_notice' );

/**
 * Warn the admin if another SEO plugin is also active (likely to duplicate tags).
 *
 * @return void
 */
function lean_seo_conflict_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$active    = (array) get_option( 'active_plugins', array() );
	$conflicts = array();
	$rules     = array(
		'/(wordpress-seo|wp-seo\.php)/i' => 'Yoast SEO',
		'/(seo-by-rank-math|rank-math)/i' => 'Rank Math',
		'/(wpmu-dev-seo|smartcrawl)/i'   => 'SmartCrawl',
		'/(all-in-one-seo|aioseo)/i'     => 'AIOSEO',
		'/(slim-seo)/i'                  => 'Slim SEO',
		'/(seopress)/i'                  => 'SEOPress',
	);
	foreach ( $active as $plugin ) {
		foreach ( $rules as $regex => $name ) {
			if ( preg_match( $regex, $plugin ) ) {
				$conflicts[ $name ] = true;
			}
		}
	}
	if ( ! $conflicts ) {
		return;
	}
	$list = esc_html( implode( ', ', array_keys( $conflicts ) ) );
	echo '<div class="notice notice-warning"><p><strong>Lean SEO:</strong> detectó otros plugins SEO activos (' . $list . '). Pueden duplicarse canonical, meta description y OG tags. Verificá en el HTML del frontend y desactivá los redundantes una vez confirmes que Lean SEO cubre tus necesidades.</p></div>';
}

add_action( 'add_meta_boxes', 'lean_seo_add_meta_box' );

/**
 * Register the meta box on supported post types.
 *
 * @return void
 */
function lean_seo_add_meta_box() {
	$post_types = get_post_types( array( 'public' => true ), 'names' );
	$post_types = apply_filters( 'lean_seo_post_types', $post_types );
	foreach ( $post_types as $pt ) {
		add_meta_box( 'lean_seo_box', 'Lean SEO', 'lean_seo_render_meta_box', $pt, 'normal', 'low' );
	}
}

/**
 * Render the meta box markup.
 *
 * @param WP_Post $post Post being edited.
 * @return void
 */
function lean_seo_render_meta_box( $post ) {
	wp_nonce_field( 'lean_seo_save', 'lean_seo_nonce' );
	$title       = lean_seo_get( $post->ID, 'title' );
	$canonical   = lean_seo_get( $post->ID, 'canonical' );
	$description = lean_seo_get( $post->ID, 'description' );
	$og_image    = lean_seo_get( $post->ID, 'og_image' );
	$og_type     = lean_seo_get( $post->ID, 'og_type' );
	$art_type    = lean_seo_get( $post->ID, 'article_type' );
	$noindex     = lean_seo_get( $post->ID, 'noindex' );
	$nofollow    = lean_seo_get( $post->ID, 'nofollow' );

	$guidelines = apply_filters( 'lean_seo_length_guidelines', array(
		'title' => array( 'optimal_min' => LEAN_SEO_TITLE_OPTIMAL_MIN, 'optimal_max' => LEAN_SEO_TITLE_OPTIMAL_MAX, 'hard_max' => LEAN_SEO_TITLE_HARD_MAX ),
		'desc'  => array( 'optimal_min' => LEAN_SEO_DESC_OPTIMAL_MIN,  'optimal_max' => LEAN_SEO_DESC_OPTIMAL_MAX,  'hard_max' => LEAN_SEO_DESC_HARD_MAX  ),
	) );

	echo '<style>'
		. '.lean-seo-row{margin:10px 0}'
		. '.lean-seo-row label{display:flex;justify-content:space-between;align-items:baseline;font-weight:600;margin-bottom:4px}'
		. '.lean-seo-row input[type=url],.lean-seo-row input[type=text],.lean-seo-row textarea,.lean-seo-row select{width:100%}'
		. '.lean-seo-counter{font-weight:400;font-size:12px;color:#666;font-variant-numeric:tabular-nums}'
		. '.lean-seo-counter.good{color:#1b7a3e}'
		. '.lean-seo-counter.warn{color:#b85c00}'
		. '.lean-seo-counter.over{color:#a00;font-weight:600}'
		. '.lean-seo-cols{display:flex;gap:16px}'
		. '.lean-seo-cols>div{flex:1}'
		. '.lean-seo-help{font-size:12px;color:#666;margin-top:3px}'
		. '</style>';

	echo '<div class="lean-seo-row"><label for="lean_seo_title">SEO title <span class="lean-seo-counter" data-counter-for="lean_seo_title">0</span></label>';
	echo '<input type="text" id="lean_seo_title" name="lean_seo_title" value="' . esc_attr( $title ) . '" placeholder="' . esc_attr( get_the_title( $post ) ) . '" maxlength="' . (int) $guidelines['title']['hard_max'] . '" />';
	echo '<div class="lean-seo-help">Óptimo: ' . (int) $guidelines['title']['optimal_min'] . '–' . (int) $guidelines['title']['optimal_max'] . ' caracteres · Google trunca tras ' . (int) $guidelines['title']['hard_max'] . '.</div></div>';

	echo '<div class="lean-seo-row"><label for="lean_seo_description">Meta description <span class="lean-seo-counter" data-counter-for="lean_seo_description">0</span></label>';
	echo '<textarea id="lean_seo_description" name="lean_seo_description" rows="3" maxlength="' . (int) $guidelines['desc']['hard_max'] . '">' . esc_textarea( $description ) . '</textarea>';
	echo '<div class="lean-seo-help">Óptimo: ' . (int) $guidelines['desc']['optimal_min'] . '–' . (int) $guidelines['desc']['optimal_max'] . ' caracteres · Google trunca tras ' . (int) $guidelines['desc']['hard_max'] . '.</div></div>';

	echo '<div class="lean-seo-row"><label for="lean_seo_canonical">Canonical URL</label>';
	echo '<input type="url" id="lean_seo_canonical" name="lean_seo_canonical" value="' . esc_attr( $canonical ) . '" placeholder="' . esc_attr( get_permalink( $post ) ) . '" /></div>';

	echo '<div class="lean-seo-row"><label for="lean_seo_og_image">OG image URL</label>';
	echo '<input type="url" id="lean_seo_og_image" name="lean_seo_og_image" value="' . esc_attr( $og_image ) . '" placeholder="(featured image si está vacío)" />';
	echo '<div class="lean-seo-help">Recomendado 1200×630 px (relación 1.91:1) para que Twitter/X y LinkedIn usen card grande.</div></div>';

	echo '<div class="lean-seo-cols">';
	echo '<div class="lean-seo-row"><label for="lean_seo_og_type">og:type</label>';
	echo '<select id="lean_seo_og_type" name="lean_seo_og_type">';
	foreach ( array( '' => '(auto)', 'article' => 'article', 'website' => 'website', 'profile' => 'profile', 'video.other' => 'video.other' ) as $val => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $og_type, $val, false ), esc_html( $label ) );
	}
	echo '</select></div>';

	echo '<div class="lean-seo-row"><label for="lean_seo_article_type">JSON-LD type</label>';
	echo '<select id="lean_seo_article_type" name="lean_seo_article_type">';
	$article_types = apply_filters( 'lean_seo_article_types', array(
		''                       => 'Article (default)',
		'NewsArticle'            => 'NewsArticle (news)',
		'BlogPosting'            => 'BlogPosting',
		'TechArticle'            => 'TechArticle',
		'OpinionNewsArticle'     => 'OpinionNewsArticle (columnas)',
		'AnalysisNewsArticle'    => 'AnalysisNewsArticle',
		'ReportageNewsArticle'   => 'ReportageNewsArticle',
		'BackgroundNewsArticle'  => 'BackgroundNewsArticle',
		'ScholarlyArticle'       => 'ScholarlyArticle',
		'Report'                 => 'Report',
	) );
	foreach ( $article_types as $val => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $art_type, $val, false ), esc_html( $label ) );
	}
	echo '</select></div>';
	echo '</div>'; // /.lean-seo-cols

	echo '<div class="lean-seo-row">';
	echo '<label style="display:inline"><input type="checkbox" name="lean_seo_noindex" value="1"' . checked( $noindex, true, false ) . ' /> noindex</label>&nbsp;&nbsp;';
	echo '<label style="display:inline"><input type="checkbox" name="lean_seo_nofollow" value="1"' . checked( $nofollow, true, false ) . ' /> nofollow</label>';
	echo '</div>';

	// FAQ schema — opt-in pars Q&A.
	$faq_raw = lean_seo_get( $post->ID, 'faq' );
	$faq_decoded = $faq_raw ? json_decode( $faq_raw, true ) : array();
	if ( ! is_array( $faq_decoded ) ) $faq_decoded = array();
	// Ensure at least 3 empty slots for UX.
	while ( count( $faq_decoded ) < 3 ) $faq_decoded[] = array( 'q' => '', 'a' => '' );
	echo '<details style="margin-top:12px"><summary style="font-weight:600;cursor:pointer">FAQPage schema <span style="font-weight:400;font-size:12px;color:#666">(opt-in — solo si el post tiene una sección FAQ real)</span></summary>';
	echo '<div style="margin-top:8px">';
	echo '<p class="description" style="margin-bottom:8px">Completá pares pregunta/respuesta. Filas vacías se ignoran. <strong>No uses schema FAQ si el contenido no tiene FAQ real</strong> — Google penaliza el spam de schema.</p>';
	foreach ( $faq_decoded as $fi => $fpair ) {
		$fq = isset( $fpair['q'] ) ? $fpair['q'] : '';
		$fa = isset( $fpair['a'] ) ? $fpair['a'] : '';
		echo '<div style="border:1px solid #ddd;padding:8px;margin-bottom:6px;border-radius:3px">';
		echo '<input type="text" name="lean_seo_faq[' . $fi . '][q]" value="' . esc_attr( $fq ) . '" placeholder="Pregunta" style="width:100%;margin-bottom:4px" />';
		echo '<textarea name="lean_seo_faq[' . $fi . '][a]" rows="2" placeholder="Respuesta" style="width:100%">' . esc_textarea( $fa ) . '</textarea>';
		echo '</div>';
	}
	echo '<p class="description">Para agregar más filas, guardá el post y abrí esta sección nuevamente. Se auto-expande de a 3.</p>';
	echo '</div></details>';

	// HowTo schema — opt-in steps.
	$howto_raw = lean_seo_get( $post->ID, 'howto' );
	$howto_decoded = $howto_raw ? json_decode( $howto_raw, true ) : array();
	if ( ! is_array( $howto_decoded ) ) $howto_decoded = array();
	$howto_name  = isset( $howto_decoded['name'] )  ? $howto_decoded['name']  : '';
	$howto_desc  = isset( $howto_decoded['desc'] )  ? $howto_decoded['desc']  : '';
	$howto_steps = isset( $howto_decoded['steps'] ) ? $howto_decoded['steps'] : array();
	while ( count( $howto_steps ) < 3 ) $howto_steps[] = array( 'name' => '', 'text' => '', 'img' => '' );
	echo '<details style="margin-top:8px"><summary style="font-weight:600;cursor:pointer">HowTo schema <span style="font-weight:400;font-size:12px;color:#666">(opt-in — solo para posts de tipo guía paso a paso)</span></summary>';
	echo '<div style="margin-top:8px">';
	echo '<p class="description" style="margin-bottom:8px">Solo activar si el post es una guía procedimental real. Requiere pasos ordenados.</p>';
	echo '<input type="text" name="lean_seo_howto[name]" value="' . esc_attr( $howto_name ) . '" placeholder="Nombre del procedimiento (opcional, usa el título si está vacío)" style="width:100%;margin-bottom:4px" />';
	echo '<textarea name="lean_seo_howto[desc]" rows="2" placeholder="Descripción breve del procedimiento (opcional)" style="width:100%;margin-bottom:8px">' . esc_textarea( $howto_desc ) . '</textarea>';
	foreach ( $howto_steps as $si => $step ) {
		$sn = isset( $step['name'] ) ? $step['name'] : '';
		$st = isset( $step['text'] ) ? $step['text'] : '';
		$si_img = isset( $step['img'] )  ? $step['img']  : '';
		$pos = $si + 1;
		echo '<div style="border:1px solid #ddd;padding:8px;margin-bottom:6px;border-radius:3px">';
		echo '<strong style="font-size:11px;color:#666">Paso ' . $pos . '</strong>';
		echo '<input type="text" name="lean_seo_howto[steps][' . $si . '][name]" value="' . esc_attr( $sn ) . '" placeholder="Nombre del paso" style="width:100%;margin:4px 0" />';
		echo '<textarea name="lean_seo_howto[steps][' . $si . '][text]" rows="2" placeholder="Descripción del paso" style="width:100%;margin-bottom:4px">' . esc_textarea( $st ) . '</textarea>';
		echo '<input type="url" name="lean_seo_howto[steps][' . $si . '][img]" value="' . esc_attr( $si_img ) . '" placeholder="URL imagen del paso (opcional)" style="width:100%" />';
		echo '</div>';
	}
	echo '</div></details>';

	// Inline JS — live char counters with color-coded feedback.
	// Admin-only, ~30 LOC. Frontend stays JS-free.
	?>
	<script>
	(function () {
		var rules = {
			lean_seo_title:       { min: <?php echo (int) $guidelines['title']['optimal_min']; ?>, max: <?php echo (int) $guidelines['title']['optimal_max']; ?>, hard: <?php echo (int) $guidelines['title']['hard_max']; ?> },
			lean_seo_description: { min: <?php echo (int) $guidelines['desc']['optimal_min']; ?>,  max: <?php echo (int) $guidelines['desc']['optimal_max']; ?>,  hard: <?php echo (int) $guidelines['desc']['hard_max']; ?>  }
		};
		function update(input) {
			var r = rules[input.id], n = input.value.length;
			var counter = document.querySelector('[data-counter-for="' + input.id + '"]');
			if (!counter) return;
			counter.textContent = n + ' / ' + r.max + (n > r.hard ? ' (sobre ' + r.hard + ')' : '');
			counter.className = 'lean-seo-counter ' + (n > r.hard ? 'over' : (n < r.min || n > r.max ? 'warn' : (n === 0 ? '' : 'good')));
		}
		['lean_seo_title','lean_seo_description'].forEach(function (id) {
			var el = document.getElementById(id);
			if (el) { update(el); el.addEventListener('input', function () { update(el); }); }
		});
	})();
	</script>
	<?php
}

/* ═══════════════════════════════════════════════════════════════════════════
   SAME AS — helper for Person node, used by lean_seo_emit_jsonld
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Parse a newline-separated list of URLs from a raw option value.
 *
 * @param string $raw Raw option string.
 * @return array Valid URLs only.
 */
function lean_seo_parse_same_as( $raw ) {
	if ( ! $raw ) {
		return array();
	}
	$urls = array();
	foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
		$line = trim( $line );
		if ( $line && filter_var( $line, FILTER_VALIDATE_URL ) ) {
			$urls[] = $line;
		}
	}
	return $urls;
}

/**
 * Return sameAs URLs for the Person node (single-author sites).
 *
 * @return array
 */
function lean_seo_get_same_as() {
	return lean_seo_parse_same_as( get_option( 'lean_seo_same_as', '' ) );
}

/**
 * Return sameAs URLs for the Organization node.
 * Use this for social profiles of the publication/brand, not of individual authors.
 *
 * @return array
 */
function lean_seo_get_org_same_as() {
	return lean_seo_parse_same_as( get_option( 'lean_seo_org_same_as', '' ) );
}

/**
 * Return the site Person node array if a personal brand is configured,
 * or an empty array if `lean_seo_person_name` is not set.
 *
 * This is the *site-level* Person (the founder / personal brand), NOT the
 * per-post dynamic author node.  The two are intentionally separate.
 *
 * @param string $site_url Site home URL with trailing slash.
 * @return array Schema.org Person node, or empty array when not configured.
 */
function lean_seo_get_site_person_node( $site_url ) {
	$name = trim( (string) get_option( 'lean_seo_person_name', '' ) );
	if ( ! $name ) {
		return array(); // not a personal brand site — bail
	}

	$person_id = $site_url . '#person';
	$node = array(
		'@type' => 'Person',
		'@id'   => $person_id,
		'name'  => $name,
		'url'   => esc_url_raw( get_option( 'lean_seo_person_url', $site_url ) ?: $site_url ),
	);

	$image = trim( (string) get_option( 'lean_seo_person_image', '' ) );
	if ( $image ) {
		$node['image'] = array( '@type' => 'ImageObject', 'url' => $image );
	}

	$job_title = trim( (string) get_option( 'lean_seo_person_job_title', '' ) );
	if ( $job_title ) {
		$node['jobTitle'] = $job_title;
	}

	$description = trim( (string) get_option( 'lean_seo_person_description', '' ) );
	if ( $description ) {
		$node['description'] = $description;
	}

	$same_as = lean_seo_parse_same_as( get_option( 'lean_seo_person_sameas', '' ) );
	if ( $same_as ) {
		$node['sameAs'] = $same_as;
	}

	return $node;
}

/* ═══════════════════════════════════════════════════════════════════════════
   LLMS.TXT — serve /llms.txt dynamically, cached via transient
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Generate llms.txt content and cache it in a transient (12h).
 * Called on post save so the cache is warm when the crawler hits.
 *
 * @return string
 */
function lean_seo_generate_llmstxt() {
	$site_name = get_bloginfo( 'name' );
	$tagline   = get_bloginfo( 'description' );

	$lines = array();
	$lines[] = '# ' . $site_name;
	$lines[] = '';
	if ( $tagline ) {
		$lines[] = '> ' . $tagline;
		$lines[] = '';
	}

	// Optional sections from settings.
	$opts = get_option( 'lean_seo_llmstxt', array() );
	$include_pages   = ! isset( $opts['include_pages'] )   || $opts['include_pages'];
	$include_posts   = ! isset( $opts['include_posts'] )   || $opts['include_posts'];
	$posts_count     = isset( $opts['posts_count'] ) ? (int) $opts['posts_count'] : 20;

	// Key pages.
	if ( $include_pages ) {
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'fields'         => 'all',
		) );
		if ( $pages ) {
			$lines[] = '## Pages';
			$lines[] = '';
			foreach ( $pages as $p ) {
				$lines[] = '- [' . strip_tags( $p->post_title ) . '](' . get_permalink( $p ) . ')';
			}
			$lines[] = '';
		}
	}

	// Recent posts.
	if ( $include_posts ) {
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $posts_count > 0 ? $posts_count : 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		if ( $posts ) {
			$lines[] = '## Recent posts';
			$lines[] = '';
			foreach ( $posts as $p ) {
				$desc = lean_seo_get( $p->ID, 'description' );
				if ( ! $desc ) {
					$desc = lean_seo_trim( wp_strip_all_tags( $p->post_excerpt ? $p->post_excerpt : $p->post_content ), 120 );
				}
				$entry = '- [' . strip_tags( $p->post_title ) . '](' . get_permalink( $p ) . ')';
				if ( $desc ) {
					$entry .= ': ' . $desc;
				}
				$lines[] = $entry;
			}
			$lines[] = '';
		}
	}

	// Apply filter so theme/CPT plugins can append custom sections.
	$lines = apply_filters( 'lean_seo_llmstxt_lines', $lines );

	return implode( "\n", $lines );
}

/**
 * Regenerate llms.txt transient on post save (publish/update).
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function lean_seo_refresh_llmstxt( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! in_array( $post->post_status, array( 'publish' ), true ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	delete_transient( 'lean_seo_llmstxt' );
	// Schedule generation out of the save critical path.
	wp_schedule_single_event( time(), 'lean_seo_build_llmstxt_event' );
}
add_action( 'save_post', 'lean_seo_refresh_llmstxt', 20, 2 );
add_action( 'lean_seo_build_llmstxt_event', 'lean_seo_build_llmstxt_cache' );

/**
 * Build and store the llms.txt cache. Runs via WP-Cron (deferred from save_post).
 *
 * @return void
 */
function lean_seo_build_llmstxt_cache() {
	set_transient( 'lean_seo_llmstxt', lean_seo_generate_llmstxt(), 12 * HOUR_IN_SECONDS );
}

/**
 * Serve /llms.txt from transient. Falls back to generating inline if transient is cold.
 *
 * Route matched via WP rewrite rule (lean_seo_route=llmstxt) — this ensures the
 * request reaches WordPress even on LiteSpeed/Nginx setups that intercept .txt paths
 * without a query string before PHP runs.  The path-based fallback is kept for
 * environments where the rewrite table has not been flushed yet.
 *
 * Hot path cost: 1 transient read (object cache hit = 0 DB queries; cache miss = 1 query).
 *
 * @return void
 */
function lean_seo_maybe_serve_llmstxt() {
	// Primary: WP rewrite resolved the route query var.
	$route = get_query_var( 'lean_seo_route' );
	if ( 'llmstxt' !== $route ) {
		// Fallback: direct path match (no-rewrite environments / unflushed table).
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = strtok( $_SERVER['REQUEST_URI'], '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '/llms.txt' !== $path ) {
			return;
		}
	}

	$enabled = get_option( 'lean_seo_llmstxt_enabled', '1' );
	if ( ! $enabled ) {
		return;
	}

	$content = get_transient( 'lean_seo_llmstxt' );
	if ( false === $content ) {
		$content = lean_seo_generate_llmstxt();
		set_transient( 'lean_seo_llmstxt', $content, 12 * HOUR_IN_SECONDS );
	}

	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text file
	exit;
}
add_action( 'template_redirect', 'lean_seo_maybe_serve_llmstxt', 1 );

/* ═══════════════════════════════════════════════════════════════════════════
   INDEXNOW — serve key file + ping on publish (non-blocking)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Serve the IndexNow verification key file at /{key}.txt.
 * Example: https://example.com/dc2ebb5760ac4dcd9c71c030fea11768.txt
 *
 * Route matched via WP rewrite rule (lean_seo_route=indexnow_key).
 * The rewrite regex captures the slug portion; we verify it matches the stored
 * key before serving — prevents serving for any arbitrary hex string.
 *
 * @return void
 */
function lean_seo_maybe_serve_indexnow_key() {
	$key = get_option( 'lean_seo_indexnow_key', '' );
	if ( ! $key ) {
		return;
	}

	// Primary: WP rewrite resolved the route.
	$route = get_query_var( 'lean_seo_route' );
	if ( 'indexnow_key' === $route ) {
		$slug = get_query_var( 'lean_seo_indexnow_key_slug', '' );
		if ( $slug !== $key ) {
			return; // Hex slug doesn't match stored key — not our file.
		}
	} else {
		// Fallback: direct path match.
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = strtok( $_SERVER['REQUEST_URI'], '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '/' . $key . '.txt' !== $path ) {
			return;
		}
	}

	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo esc_html( $key );
	exit;
}
add_action( 'template_redirect', 'lean_seo_maybe_serve_indexnow_key', 1 );

/**
 * Fire IndexNow ping when a post is published or updated to publish.
 * Non-blocking: uses wp_remote_post with blocking=false so the save is not delayed.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 * @return void
 */
function lean_seo_indexnow_ping( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status ) {
		return;
	}
	if ( ! in_array( $post->post_type, get_post_types( array( 'public' => true ), 'names' ), true ) ) {
		return;
	}

	$key = get_option( 'lean_seo_indexnow_key', '' );
	if ( ! $key ) {
		return;
	}

	$host   = wp_parse_url( home_url(), PHP_URL_HOST );
	$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );

	wp_remote_post(
		'https://api.indexnow.org/indexnow',
		array(
			'blocking' => false,
			'timeout'  => 5,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => $scheme . '://' . $host . '/' . $key . '.txt',
				'urlList'     => array( get_permalink( $post->ID ) ),
			) ),
		)
	);
}
add_action( 'transition_post_status', 'lean_seo_indexnow_ping', 10, 3 );

add_action( 'save_post', 'lean_seo_save_meta_box', 10, 2 );

/**
 * Persist meta box values on post save.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function lean_seo_save_meta_box( $post_id, $post ) {
	if ( ! isset( $_POST['lean_seo_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lean_seo_nonce'] ) ), 'lean_seo_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$text_fields = array(
		'title'        => 'sanitize_text_field',
		'description'  => 'sanitize_textarea_field',
		'canonical'    => 'esc_url_raw',
		'og_image'     => 'esc_url_raw',
		'og_type'      => 'sanitize_text_field',
		'article_type' => 'sanitize_text_field',
	);
	foreach ( $text_fields as $key => $sanitizer ) {
		$value = isset( $_POST[ 'lean_seo_' . $key ] ) ? call_user_func( $sanitizer, wp_unslash( $_POST[ 'lean_seo_' . $key ] ) ) : '';
		if ( $value ) {
			update_post_meta( $post_id, LEAN_SEO_NS . $key, $value );
		} else {
			delete_post_meta( $post_id, LEAN_SEO_NS . $key );
		}
	}

	foreach ( array( 'noindex', 'nofollow' ) as $key ) {
		$on = ! empty( $_POST[ 'lean_seo_' . $key ] );
		if ( $on ) {
			update_post_meta( $post_id, LEAN_SEO_NS . $key, true );
		} else {
			delete_post_meta( $post_id, LEAN_SEO_NS . $key );
		}
	}

	// FAQ — serialize valid pairs as JSON.
	if ( isset( $_POST['lean_seo_faq'] ) && is_array( $_POST['lean_seo_faq'] ) ) {
		$pairs = array();
		foreach ( $_POST['lean_seo_faq'] as $item ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$q = sanitize_text_field( wp_unslash( $item['q'] ?? '' ) );
			$a = sanitize_textarea_field( wp_unslash( $item['a'] ?? '' ) );
			if ( $q && $a ) {
				$pairs[] = array( 'q' => $q, 'a' => $a );
			}
		}
		if ( $pairs ) {
			update_post_meta( $post_id, LEAN_SEO_NS . 'faq', wp_json_encode( $pairs ) );
		} else {
			delete_post_meta( $post_id, LEAN_SEO_NS . 'faq' );
		}
	} else {
		delete_post_meta( $post_id, LEAN_SEO_NS . 'faq' );
	}

	// HowTo — serialize as JSON.
	if ( isset( $_POST['lean_seo_howto'] ) && is_array( $_POST['lean_seo_howto'] ) ) {
		$raw   = $_POST['lean_seo_howto']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$hname = sanitize_text_field( wp_unslash( $raw['name'] ?? '' ) );
		$hdesc = sanitize_textarea_field( wp_unslash( $raw['desc'] ?? '' ) );
		$steps = array();
		if ( isset( $raw['steps'] ) && is_array( $raw['steps'] ) ) {
			foreach ( $raw['steps'] as $idx => $step ) {
				$sn  = sanitize_text_field( wp_unslash( $step['name'] ?? '' ) );
				$st  = sanitize_textarea_field( wp_unslash( $step['text'] ?? '' ) );
				$img = esc_url_raw( wp_unslash( $step['img'] ?? '' ) );
				if ( $sn || $st ) {
					$entry = array( 'name' => $sn, 'text' => $st );
					if ( $img ) $entry['img'] = $img;
					$steps[] = $entry;
				}
			}
		}
		if ( $steps ) {
			$howto = array( 'name' => $hname, 'desc' => $hdesc, 'steps' => $steps );
			update_post_meta( $post_id, LEAN_SEO_NS . 'howto', wp_json_encode( $howto ) );
		} else {
			delete_post_meta( $post_id, LEAN_SEO_NS . 'howto' );
		}
	} else {
		delete_post_meta( $post_id, LEAN_SEO_NS . 'howto' );
	}
}

/* ═══════════════════════════════════════════════════════════════════════════
   ADMIN — plugin list "Settings" action link
   ═══════════════════════════════════════════════════════════════════════════ */

add_filter( 'plugin_action_links_lean-seo/lean-seo.php', 'lean_seo_action_links' );

/**
 * Add a "Settings" shortcut in the plugin list row.
 *
 * @param array $links Existing action links.
 * @return array
 */
function lean_seo_action_links( $links ) {
	$url = admin_url( 'options-general.php?page=lean-seo' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . __( 'Settings', 'lean-seo' ) . '</a>' );
	return $links;
}

/* ═══════════════════════════════════════════════════════════════════════════
   AI CRAWLERS — inject User-agent rules in WP-generated robots.txt
   Default: ALLOW all (AEO strategy: let LLMs cite the site).
   ═══════════════════════════════════════════════════════════════════════════ */

add_filter( 'robots_txt', 'lean_seo_robots_txt_ai_crawlers', 20, 2 );

/**
 * Append AI crawler rules to robots.txt. Only emits explicit Disallow lines;
 * bots not listed (or set to Allow) work with the global rules (default allow).
 *
 * @param string $output   Current robots.txt content.
 * @param bool   $public   Whether the site is public.
 * @return string
 */
function lean_seo_robots_txt_ai_crawlers( $output, $public ) {
	$registry = lean_seo_ai_crawlers_registry();
	$saved    = get_option( 'lean_seo_ai_crawlers', array() );
	$lines    = array();

	foreach ( $registry as $bot => $info ) {
		// Default = allow; only emit a rule when explicitly disallowed.
		$is_allowed = isset( $saved[ $bot ] ) ? ( '1' === $saved[ $bot ] ) : $info['default'];
		if ( ! $is_allowed ) {
			$lines[] = "User-agent: {$bot}";
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}
	}

	if ( ! $lines ) {
		return $output; // nothing to add — no overhead
	}

	return $output . "\n# Lean SEO — AI crawler rules\n" . implode( "\n", $lines );
}

/* ═══════════════════════════════════════════════════════════════════════════
   IMAGE SITEMAP — /sitemap-images.xml, transient-cached, regen on save
   Namespace: http://www.google.com/schemas/sitemap-image/1.1
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'template_redirect', 'lean_seo_maybe_serve_image_sitemap', 1 );
add_action( 'lean_seo_build_image_sitemap_event', 'lean_seo_build_image_sitemap_cache' );

/**
 * Serve /sitemap-images.xml from transient; generate inline on cold cache.
 *
 * Route matched via WP rewrite rule (lean_seo_route=imagesitemap).
 *
 * @return void
 */
function lean_seo_maybe_serve_image_sitemap() {
	// Primary: WP rewrite resolved the route query var.
	$route = get_query_var( 'lean_seo_route' );
	if ( 'imagesitemap' !== $route ) {
		// Fallback: direct path match.
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = strtok( $_SERVER['REQUEST_URI'], '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '/sitemap-images.xml' !== $path ) {
			return;
		}
	}

	if ( '1' !== get_option( 'lean_seo_image_sitemap_enabled', '1' ) ) {
		return;
	}

	$xml = get_transient( 'lean_seo_image_sitemap' );
	if ( false === $xml ) {
		$xml = lean_seo_generate_image_sitemap();
		set_transient( 'lean_seo_image_sitemap', $xml, 6 * HOUR_IN_SECONDS );
	}

	status_header( 200 );
	header( 'Content-Type: application/xml; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

/**
 * Generate image sitemap XML. Queries posts + attachments; no N+1.
 * Called from transient miss path or cron event.
 *
 * @return string XML content.
 */
function lean_seo_generate_image_sitemap() {
	// Batch query: published posts of all public types.
	$post_types = get_post_types( array( 'public' => true ), 'names' );
	// Exclude attachment (images are content not pages here).
	unset( $post_types['attachment'] );

	$posts = get_posts( array(
		'post_type'      => array_values( $post_types ),
		'post_status'    => 'publish',
		'posts_per_page' => apply_filters( 'lean_seo_image_sitemap_limit', 1000 ),
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'fields'         => 'all',
	) );

	$lines = array();
	$lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
	$lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
	$lines[] = '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

	foreach ( $posts as $post ) {
		$url = get_permalink( $post );
		if ( ! $url ) continue;

		$images = array();

		// Featured image.
		$thumb_id = get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_src( $thumb_id, 'full' );
			if ( $src ) {
				$alt     = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
				$images[] = array( 'src' => $src[0], 'title' => get_the_title( $thumb_id ), 'alt' => $alt );
			}
		}

		// Gallery / attached images (up to 10 extras).
		$attached = get_attached_media( 'image', $post->ID );
		$count    = 0;
		foreach ( $attached as $att ) {
			if ( $att->ID === $thumb_id ) continue; // already added
			if ( $count >= 10 ) break;
			$src = wp_get_attachment_image_src( $att->ID, 'full' );
			if ( $src ) {
				$alt     = get_post_meta( $att->ID, '_wp_attachment_image_alt', true );
				$images[] = array( 'src' => $src[0], 'title' => get_the_title( $att->ID ), 'alt' => $alt );
				$count++;
			}
		}

		if ( ! $images ) continue;

		$lines[] = '  <url>';
		$lines[] = '    <loc>' . esc_url( $url ) . '</loc>';
		foreach ( $images as $img ) {
			$lines[] = '    <image:image>';
			$lines[] = '      <image:loc>' . esc_url( $img['src'] ) . '</image:loc>';
			if ( $img['title'] ) {
				$lines[] = '      <image:title>' . esc_html( $img['title'] ) . '</image:title>';
			}
			if ( $img['alt'] ) {
				$lines[] = '      <image:caption>' . esc_html( $img['alt'] ) . '</image:caption>';
			}
			$lines[] = '    </image:image>';
		}
		$lines[] = '  </url>';
	}

	$lines[] = '</urlset>';
	return implode( "\n", $lines );
}

/**
 * Build image sitemap cache. Fired by cron event deferred from save_post.
 *
 * @return void
 */
function lean_seo_build_image_sitemap_cache() {
	set_transient( 'lean_seo_image_sitemap', lean_seo_generate_image_sitemap(), 6 * HOUR_IN_SECONDS );
}

// Hook into lean_seo_refresh_llmstxt trigger (same save_post priority) to also
// invalidate image sitemap cache when a post is published/updated.
add_action( 'save_post', 'lean_seo_refresh_image_sitemap', 20, 2 );

/**
 * Invalidate image sitemap cache on post save (publish only).
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function lean_seo_refresh_image_sitemap( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	delete_transient( 'lean_seo_image_sitemap' );
	wp_schedule_single_event( time(), 'lean_seo_build_image_sitemap_event' );
}

/* ═══════════════════════════════════════════════════════════════════════════
   LLMS-FULL.TXT — /llms-full.txt with full post content in Markdown
   Same caching pattern as /llms.txt. Default: disabled.
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'template_redirect', 'lean_seo_maybe_serve_llmsfull', 1 );
add_action( 'lean_seo_build_llmsfull_event', 'lean_seo_build_llmsfull_cache' );

/**
 * Serve /llms-full.txt from transient.
 *
 * Route matched via WP rewrite rule (lean_seo_route=llmsfull).
 *
 * @return void
 */
function lean_seo_maybe_serve_llmsfull() {
	// Primary: WP rewrite resolved the route query var.
	$route = get_query_var( 'lean_seo_route' );
	if ( 'llmsfull' !== $route ) {
		// Fallback: direct path match.
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = strtok( $_SERVER['REQUEST_URI'], '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '/llms-full.txt' !== $path ) {
			return;
		}
	}

	if ( '1' !== get_option( 'lean_seo_llmsfull_enabled', '0' ) ) {
		return;
	}

	$content = get_transient( 'lean_seo_llmsfull' );
	if ( false === $content ) {
		$content = lean_seo_generate_llmsfull();
		set_transient( 'lean_seo_llmsfull', $content, 12 * HOUR_IN_SECONDS );
	}

	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

/**
 * Generate llms-full.txt content. Includes post content converted to plain text
 * (strip_tags + basic markdown-ish structure). Truncated per post to keep size sane.
 *
 * @return string
 */
function lean_seo_generate_llmsfull() {
	$site_name = get_bloginfo( 'name' );
	$tagline   = get_bloginfo( 'description' );
	$opts      = get_option( 'lean_seo_llmsfull', array( 'posts_count' => 10, 'chars_per_post' => 3000 ) );
	$count     = max( 1, min( 50, (int) ( $opts['posts_count']    ?? 10 ) ) );
	$chars     = max( 500, min( 10000, (int) ( $opts['chars_per_post'] ?? 3000 ) ) );

	$lines   = array();
	$lines[] = '# ' . $site_name . ' — Full Content';
	$lines[] = '';
	if ( $tagline ) {
		$lines[] = '> ' . $tagline;
		$lines[] = '';
	}
	$lines[] = '> Generated: ' . gmdate( 'Y-m-d H:i' ) . ' UTC';
	$lines[] = '';

	$posts = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => $count,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	foreach ( $posts as $post ) {
		$title   = strip_tags( $post->post_title );
		$url     = get_permalink( $post );
		$desc    = lean_seo_get( $post->ID, 'description' );
		if ( ! $desc ) {
			$desc = wp_strip_all_tags( $post->post_excerpt );
		}
		$content = wp_strip_all_tags( $post->post_content );
		$content = lean_seo_trim( $content, $chars );

		$lines[] = '---';
		$lines[] = '';
		$lines[] = '## [' . $title . '](' . $url . ')';
		$lines[] = '';
		if ( $desc ) {
			$lines[] = '**' . $desc . '**';
			$lines[] = '';
		}
		$lines[] = $content;
		$lines[] = '';
	}

	$lines = apply_filters( 'lean_seo_llmsfull_lines', $lines );
	return implode( "\n", $lines );
}

/**
 * Build and cache llms-full.txt content.
 *
 * @return void
 */
function lean_seo_build_llmsfull_cache() {
	set_transient( 'lean_seo_llmsfull', lean_seo_generate_llmsfull(), 12 * HOUR_IN_SECONDS );
}

// Invalidate llms-full cache on publish, same as llms.txt.
add_action( 'save_post', 'lean_seo_refresh_llmsfull', 20, 2 );

/**
 * Invalidate llms-full.txt transient on post save.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function lean_seo_refresh_llmsfull( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! in_array( $post->post_status, array( 'publish' ), true ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	delete_transient( 'lean_seo_llmsfull' );
	wp_schedule_single_event( time(), 'lean_seo_build_llmsfull_event' );
}
