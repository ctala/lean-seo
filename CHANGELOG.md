# Changelog

All notable changes to **Lean SEO** are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-05-15

### Added
- **Settings page** at *Settings → Lean SEO* with two mapping tables:
  - **Category → schema** with tree inheritance (mapping a parent category cascades to all descendants)
  - **Post type → schema** as fallback when no category matches
- New filter `lean_seo_default_article_type($default, $post_id, $post_type)` for programmatic overrides
- New filter `lean_seo_article_types` to extend the dropdown options
- Article types extended: `OpinionNewsArticle`, `AnalysisNewsArticle`, `ReportageNewsArticle`,
  `BackgroundNewsArticle`, `ScholarlyArticle`, `Report`
- Schema helpers for CPT plugins (call from `lean_seo_jsonld_graph` filter):
  - `lean_seo_schema_event($data)` — for events/calendars
  - `lean_seo_schema_defined_term($data)` — for glossary CPTs
  - `lean_seo_schema_job_posting($data)` — for job listings / convocatorias
  - `lean_seo_schema_podcast_episode($data)` — for podcast episode CPTs
  - `lean_seo_schema_video_object($data)` — for posts with embedded videos
  - `lean_seo_schema_person($data)` — for actor/profile CPTs
- Article schema emission can be fully disabled by returning `false` from
  `lean_seo_default_article_type` (useful when a CPT plugin will inject its
  own primary schema like Event or DefinedTerm)

### Schema resolution priority

For each post, the JSON-LD type is resolved in this order:
1. Per-post meta `_lean_seo_article_type` (admin meta box)
2. Category mapping (Settings → Lean SEO) — walks ancestor tree
3. Post type mapping (Settings → Lean SEO)
4. `Article` (default)

Programmatic override available via `lean_seo_default_article_type` filter.

### Changed
- Uninstall handler now also removes the `lean_seo_schema_map` option

## [1.0.3] — 2026-05-15

### Added
- Shortcode `[lean_seo_breadcrumbs]` for embedding breadcrumbs anywhere in content,
  widgets, or Gutenberg shortcode blocks. Supports `separator` and `class` attributes.

Example: `[lean_seo_breadcrumbs separator="/" class="my-bc"]`

## [1.0.2] — 2026-05-15

### Added
- New filter `lean_seo_auto_inject_breadcrumbs` — when set to true, breadcrumbs
  are automatically prepended to the post content via `the_content` filter.
  Drop-in replacement for SmartCrawl/Yoast breadcrumb injection without
  touching the theme template. Default: off (theme controls via
  `lean_seo_breadcrumbs()`).
- New public function `lean_seo_breadcrumbs_html()` (returns string instead of echo)

### How to enable auto-inject
```php
add_filter( 'lean_seo_auto_inject_breadcrumbs', '__return_true' );
```

## [1.0.1] — 2026-05-15

### Added
- Live character counters in the admin meta box for SEO title and meta description
- Color-coded feedback: green (within optimal range), orange (suboptimal but under hard limit), red (over hard limit)
- Inline help text below each length-sensitive field with the recommended ranges
- New filter `lean_seo_length_guidelines` to customize optimal/hard limits per project
- New constants: `LEAN_SEO_TITLE_OPTIMAL_MIN/MAX/HARD_MAX`, `LEAN_SEO_DESC_OPTIMAL_MIN/MAX/HARD_MAX`
- OG image inline help recommending 1200×630 (1.91:1) for proper Twitter/X + LinkedIn card preview

### Changed
- Admin meta box: textarea for description now 3 rows (was 2) to comfortably fit the optimal range
- Inline JS (~30 LOC) added to admin meta box only — **frontend remains 100% JS-free**

## [1.0.0] — 2026-05-15

Initial release.

### Added
- Per-post SEO meta: `title`, `description`, `canonical`, `og_image`, `og_type`, `article_type`, `noindex`, `nofollow`
- REST API exposure of all eight meta keys (`show_in_rest` + `auth_callback`)
- Canonical URL handling for singular, paginated `/page/N/`, home/archives, search, 404
- Document title override via `wp_get_document_title` filter (theme separator-aware)
- Meta description with smart fallback chain: custom → excerpt → trimmed content
- Open Graph tags with dynamic `og:type` (article/website/profile)
- Twitter Cards with auto card type based on og:image presence
- JSON-LD `@graph` with cross-referenced `@id`s: Organization, WebSite+SearchAction, Person, Article, BreadcrumbList
- Breadcrumbs HTML generator via public function `lean_seo_breadcrumbs()` + JSON-LD BreadcrumbList
- Automatic `noindex` for search results, attachment pages, `?replytocom=`, 404
- Advanced robots directives by default: `max-snippet:-1`, `max-image-preview:large`, `max-video-preview:-1`
- OG image fallback chain: meta → featured image → first content `<img>` → filter default
- `<lastmod>` augment to native `wp-sitemap.xml` (WP 5.5+) via `wp_sitemaps_posts_entry` filter
- Admin meta box (minimal, vanilla PHP, no JS)
- Conflict warning notice when another SEO plugin (Yoast/Rank Math/SmartCrawl/AIOSEO/SEOPress) is active
- Clean uninstall (removes all post meta + plugin options)

### Filters

- `lean_seo_post_types` — restrict active post types
- `lean_seo_robots_directives` — override advanced robots directives
- `lean_seo_default_og_image` — site-wide fallback OG image
- `lean_seo_organization_logo` — Organization logo URL for JSON-LD
- `lean_seo_jsonld_graph` — modify the full JSON-LD `@graph` before emission
- `lean_seo_breadcrumbs_items` — modify breadcrumb items array
- `lean_seo_breadcrumbs_html` — modify final breadcrumbs HTML output

### Out of scope (by design)

- Redirects → use `lean-redirects`
- Keyword auto-linking → use `lean-autolinks`
- Content analyzer / SEO score widget
- 404 monitor / log
- Sitemap generation (WP-native is used + augmented)
- Visual social preview editor
- Local SEO / WooCommerce / News schemas (extend via `lean_seo_jsonld_graph` filter)
