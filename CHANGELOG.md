# Changelog

All notable changes to **Lean SEO** are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
