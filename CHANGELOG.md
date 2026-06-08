# Changelog

All notable changes to **Lean SEO** are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.2] — 2026-06-08

### Fixed

- **Virtual routes intercepted by LiteSpeed before PHP runs** (`/llms.txt`,
  `/llms-full.txt`, `/sitemap-images.xml`, `/{key}.txt`): LiteSpeed (and some
  Nginx/Apache configs) serve paths with `.txt`/`.xml` extensions directly from the
  filesystem and return 404 before WordPress/PHP runs when the file does not exist
  on disk. A query string bypassed this (because static files cannot have query args),
  which is why `?x=123` returned 200 while a bare request returned 404.

  Fix: register proper WP rewrite rules via `add_rewrite_rule()` for all four virtual
  routes, mapping them to the `lean_seo_route` query var. WP rewrite rules cause the
  server to route the request through `index.php` (the standard WordPress try_files
  rule), so LiteSpeed no longer intercepts it. Each handler now checks `get_query_var(
  'lean_seo_route' )` as the primary signal, with the legacy `$_SERVER['REQUEST_URI']`
  path check as a fallback for environments where the rewrite table has not been flushed.

  Rewrite table flushing: `flush_rewrite_rules()` fires on plugin activation
  (`register_activation_hook`) and deactivation (`register_deactivation_hook`).
  For re-uploads / auto-updates where the activation hook does not re-fire, an
  upgrade-detection function (`lean_seo_maybe_flush_rewrites`) runs on `init` and
  compares the stored `lean_seo_db_version` option against `LEAN_SEO_DB_VERSION`
  constant — if they differ it flushes automatically. Manual fallback: Settings →
  Permalinks → Save Changes.

- `lean_seo_db_version` option added to `uninstall.php` cleanup.

## [1.4.1] — 2026-06-08

### Fixed

- **HTTP 404 on virtual URLs** (`/llms.txt`, `/llms-full.txt`, `/sitemap-images.xml`,
  `/{key}.txt`): all four `template_redirect` handlers were serving correct content but
  without calling `status_header(200)`. WordPress sets `is_404()` for URLs that don't match
  any WP query before `template_redirect` fires, so the 404 status persisted even though
  the body was correct. Fix: added `status_header( 200 );` immediately before the
  `header( 'Content-Type:...' )` call in each serving branch. Early-return branches
  (feature disabled, path mismatch) are unaffected — they return without setting any
  status, allowing WP's native 404 handling to continue normally.

## [1.4.0] — 2026-06-08

### Added

- **Organization node enrichment**: six new opt-in fields on the `Organization` JSON-LD node.
  All fields read from `wp_options` once per `wp_head` call; nothing emitted if empty.
  - `lean_seo_org_type` — `Organization` (default) or `NewsMediaOrganization`. When set to
    `NewsMediaOrganization`, the `@type` changes to that subtype; `@id` stays `#organization`
    so existing `publisher`/`author` cross-references in the graph are unaffected.
  - `lean_seo_org_logo` — URL → `logo: {ImageObject}`. Takes precedence over the legacy
    `lean_seo_organization_logo` filter; filter still works as fallback.
  - `lean_seo_org_description` — string → `description`.
  - `lean_seo_org_founding_date` — ISO string (YYYY, YYYY-MM, or YYYY-MM-DD) → `foundingDate`.
    Sanitizer rejects non-matching strings silently.
  - `lean_seo_org_founder_name` + `lean_seo_org_founder_sameas` → `founder: {Person}` node
    with `name` + optional `sameAs[]` (reuses `lean_seo_parse_same_as()`). Not emitted if
    `founder_name` is empty.
  - `lean_seo_org_contact_email` → `contactPoint: {ContactPoint, contactType: customer support, email}`.
- Settings page: "JSON-LD — Organization sameAs" section replaced by unified
  "Organization (datos estructurados)" table grouping all seven org fields (new + existing sameAs).

### Settings added

- `lean_seo_org_type`, `lean_seo_org_logo`, `lean_seo_org_description`,
  `lean_seo_org_founding_date`, `lean_seo_org_founder_name`, `lean_seo_org_founder_sameas`,
  `lean_seo_org_contact_email`

## [1.3.1] — 2026-06-08

### Fixed

- **Organization sameAs** (bug: v1.2.0 applied `sameAs` only to the `Person` node, which in
  multi-author sites means every author gets the same social profiles — incorrect).
  `sameAs` now lives on the **Organization** node via a new `lean_seo_org_same_as` option
  (one URL per line, same format as the existing Person field). The Person `sameAs` field
  (`lean_seo_same_as`) is unchanged and still functional for single-author sites.
- `lean_seo_get_same_as()` refactored to share a private `lean_seo_parse_same_as()` helper
  with the new `lean_seo_get_org_same_as()`. No behavioral change for the Person field.
- Settings UI: explicit warning on Person sameAs field about the multi-author caveat.

### Settings added

- `lean_seo_org_same_as` — textarea, one URL per line. Organization social profiles.
  Set via `wp option update lean_seo_org_same_as $'url1\nurl2'` or Settings UI.

## [1.3.0] — 2026-06-08

### Architecture decision

Single-file monolith maintained. All five new features belong to the SEO domain —
splitting into `lean-seo-aeo` would create circular dependency on shared options/hooks.
LOC budget raised to < 2,500 (justified by domain scope; still radically smaller than
Yoast ~50K or Rank Math ~80K). Performance budget (0 DB queries on hot path, 0 JS
frontend) unchanged and passing.

### Added

- **FAQPage schema** (opt-in per post): JSON meta `_lean_seo_faq` stores Q&A pairs.
  Admin meta box shows collapsible section with pregunta/respuesta pairs (3 slots minimum,
  auto-expands on save). Emits `FAQPage` node in JSON-LD `@graph` only when data is present.
  Never auto-detects from content — explicit opt-in only to avoid schema spam penalties.

- **HowTo schema** (opt-in per post): JSON meta `_lean_seo_howto` stores procedure name,
  description, and ordered steps (name + text + optional image URL). Emits `HowTo` node
  with `HowToStep` children in JSON-LD `@graph`. Same collapsible UI pattern as FAQ.

- **AI crawlers control**: `robots_txt` filter appends explicit `User-agent` / `Disallow: /`
  blocks for 9 known AI bots (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot,
  Applebot-Extended, YouBot, anthropic-ai, cohere-ai). **Default: ALLOW all** (AEO strategy).
  Per-bot control via Settings dropdown. Zero overhead when all bots are allowed (no lines added).

- **Image sitemap** (`/sitemap-images.xml`): transient-cached (6h), served via `template_redirect`.
  Includes featured image + up to 10 attached images per post with Google image namespace
  (`xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"`). Respects up to 1000 posts
  (filterable via `lean_seo_image_sitemap_limit`). Invalidated and regenerated async on publish.

- **`/llms-full.txt`**: extended version of `/llms.txt` with full post content (plain text,
  configurable chars per post). Default: **disabled** (must opt-in in Settings). Same
  transient pattern (12h TTL, invalidated on publish). Extensible via `lean_seo_llmsfull_lines`.

- **Plugin action link "Settings"**: shortcut in the Plugins list row pointing to
  `options-general.php?page=lean-seo`. Settings page lives under Ajustes → Lean SEO.

- **Rank Math → lean-seo migration** (Hybrid approach):
  - Fallback reads: `lean_seo_get()` transparently reads `rank_math_title`,
    `rank_math_description`, `rank_math_canonical_url`, `rank_math_facebook_image`,
    `rank_math_robots` when the lean-seo field is empty and fallback is enabled in Settings.
    Active from the moment lean-seo is installed, before running any migration script.
  - Migration script `migration/migrate-from-rank-math.php`: WP-CLI `eval-file` script,
    dry-run by default, `--apply` to write. Idempotent, non-destructive on destination.
    Reports counts per meta key. Includes redirect count notice (not migrated here —
    use lean-redirects separately).

### Settings added

- `lean_seo_ai_crawlers` — array, bot→'1'(allow)/'0'(disallow). Default empty = all allowed.
- `lean_seo_llmsfull_enabled` — checkbox, default off.
- `lean_seo_llmsfull` — array: `posts_count` (1–50), `chars_per_post` (500–10000).
- `lean_seo_image_sitemap_enabled` — checkbox, default on.
- `lean_seo_rank_math_fallback` — checkbox, default off.

### Filters added

- `lean_seo_image_sitemap_limit` — max posts in image sitemap (default 1000).
- `lean_seo_llmsfull_lines` — modify/extend lines before llms-full.txt is serialized.

### Uninstall

Added cleanup for all v1.3.0 options and transients. `_lean_seo_faq` and `_lean_seo_howto`
post meta deleted via bulk query.

## [1.2.0] — 2026-06-08

### Added

- **`/llms.txt` serving** (Feature A): dynamic file following the [llmstxt.org](https://llmstxt.org)
  standard — title, tagline, pages section, recent posts with per-post meta description.
  Served via `template_redirect` at priority 1. Content generated and stored in a transient
  (12h TTL); regenerated async via `wp_schedule_single_event` on every publish/update.
  Cache miss on first request generates inline (1 DB query, same as a normal page load).
  Configurable: enable/disable, include pages, include posts, posts count (1–100).
  Extensible via `lean_seo_llmstxt_lines` filter. Zero JS, zero DB queries on hot path
  when object cache is active.

- **IndexNow** (Feature B): key file served at `/{key}.txt` via `template_redirect`.
  Non-blocking ping to `https://api.indexnow.org/indexnow` on `transition_post_status`
  (publish/re-publish) using `wp_remote_post` with `blocking=false` — zero TTFB impact on save.
  Key configurable in Settings → Lean SEO. Blank key = feature off. Sanitized to hex-only,
  8 chars minimum. Note: IndexNow notifies Bing/Yandex/Naver/Seznam, NOT Google.

- **Person `sameAs`** (Check C): the JSON-LD Person node now emits `sameAs` when configured.
  Input: one URL per line in Settings → Lean SEO. Validated via `FILTER_VALIDATE_URL`.
  Previously the Person node had no `sameAs` — it was empty.

### Settings added

- `lean_seo_same_as` — textarea, one profile URL per line (LinkedIn, YouTube, Spotify, GitHub…)
- `lean_seo_llmstxt_enabled` — checkbox, default on
- `lean_seo_llmstxt` — array: `include_pages`, `include_posts`, `posts_count`
- `lean_seo_indexnow_key` — hex string, 8–128 chars

### Filters added

- `lean_seo_llmstxt_lines` — modify/extend the lines array before the file is serialized

### Uninstall

- Removes: `lean_seo_same_as`, `lean_seo_llmstxt_enabled`, `lean_seo_llmstxt`,
  `lean_seo_indexnow_key` options + `lean_seo_llmstxt` transient.

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
