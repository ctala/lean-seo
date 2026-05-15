<?php
/**
 * Plugin Name: Lean SEO
 * Plugin URI:  https://github.com/ctala/lean-seo
 * Description: Ultra-lightweight SEO for WordPress. Canonical, meta, OG, Twitter, JSON-LD @graph, breadcrumbs, sitemap lastmod. Zero JS. No bloat.
 * Version:     1.1.0
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

define( 'LEAN_SEO_VERSION', '1.1.0' );
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
		return (bool) $v;
	}
	return is_string( $v ) ? trim( $v ) : '';
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

	// Organization.
	$org = array(
		'@type' => 'Organization',
		'@id'   => $org_id,
		'name'  => get_bloginfo( 'name' ),
		'url'   => $site_url,
	);
	$logo = apply_filters( 'lean_seo_organization_logo', '' );
	if ( $logo ) {
		$org['logo'] = array(
			'@type' => 'ImageObject',
			'url'   => $logo,
		);
	}
	$graph[] = $org;

	// WebSite + SearchAction.
	$graph[] = array(
		'@type'           => 'WebSite',
		'@id'             => $site_id,
		'url'             => $site_url,
		'name'            => get_bloginfo( 'name' ),
		'publisher'       => array( '@id' => $org_id ),
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $site_url . '?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		),
	);

	// Article (only on singular).
	if ( $post_id ) {
		$post = get_post( $post_id );
		// Resolution: per-post meta > per-post-type default (via filter) > "Article".
		// `false` from the filter disables emitting the Article node entirely (useful when
		// a CPT plugin will inject its own primary schema like Event/DefinedTerm/PodcastEpisode).
		$type = $post ? lean_seo_get( $post_id, 'article_type' ) : '';
		if ( $post && ! $type ) {
			$type = apply_filters( 'lean_seo_default_article_type', 'Article', $post_id, $post->post_type );
		}
		if ( $post && false !== $type ) {
			$author = get_userdata( $post->post_author );
			$person_id = $site_url . '#author-' . ( $author ? $author->ID : '0' );

			if ( $author ) {
				$graph[] = array(
					'@type' => 'Person',
					'@id'   => $person_id,
					'name'  => $author->display_name,
					'url'   => get_author_posts_url( $author->ID ),
				);
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
				'publisher'        => array( '@id' => $org_id ),
			);
			if ( $author ) {
				$article['author'] = array( '@id' => $person_id );
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
}
