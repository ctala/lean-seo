# AGENT.md — Lean SEO

Context for Claude/agents working on this plugin.

## What this plugin is

**Lean SEO** is a single-file WordPress plugin (~2,450 LOC) that covers SEO essentials for a modern WP site without the bloat of Yoast/Rank Math/SmartCrawl/AIOSEO. Part of the `lean-*` family. Canonical repo: `github.com/ctala/lean-seo`.

## What it covers

### Core (every request)

- Per-post SEO meta (title, description, canonical, og_image, og_type, article_type, noindex, nofollow) — registered with `show_in_rest:true` + `auth_callback` (edit_posts), fully writable from n8n/Python/Node via standard WP REST API. No custom endpoints needed.
- `wp_head` output: canonical (paginated/archive/search/404-aware), meta description, Open Graph, Twitter Cards, JSON-LD `@graph`
- Document title override via `wp_get_document_title` filter
- Advanced robots directives (`max-snippet:-1`, `max-image-preview:large`, `max-video-preview:-1`) by default
- Automatic `noindex` for thin contexts: search results, attachment pages, `?replytocom=`, 404
- Breadcrumbs via public function `lean_seo_breadcrumbs()` (HTML) + JSON-LD `BreadcrumbList`
- `<lastmod>` augment to native `wp-sitemap.xml` (WP 5.5+)
- Admin meta box (vanilla PHP, inline `<style>`, no JS enqueue, no CSS file)
- Conflict notice when another SEO plugin is active

### JSON-LD @graph — 12 schema types

| Node | Trigger |
|---|---|
| `Organization` / `NewsMediaOrganization` | Always. Controlled by `lean_seo_org_type` option. `@id` = `{site_url}/#organization` (stable — never changes). |
| `WebSite` + `SearchAction` | Always. |
| `Person` | Always (single-author). `sameAs` from `lean_seo_same_as` option. |
| `Article` / `NewsArticle` / `BlogPosting` / `TechArticle` | Posts with `_lean_seo_article_type` meta set. |
| `BreadcrumbList` | Pages with a breadcrumb trail (archives, categories, singles). |
| `VideoObject` | Posts with `_lean_seo_video_object` meta (JSON). |
| `PodcastEpisode` + `PodcastSeries` | Posts with `_lean_seo_podcast` meta (JSON). |
| `Event` | Posts with `_lean_seo_event` meta (JSON). |
| `JobPosting` | Posts with `_lean_seo_jobposting` meta (JSON). |
| `DefinedTerm` | Posts with `_lean_seo_definedterm` meta (JSON). |
| `FAQPage` | Posts with `_lean_seo_faq` meta (JSON array `[{q,a}]`). `@id` = `{url}#faq`. |
| `HowTo` | Posts with `_lean_seo_howto` meta (JSON object `{name,desc,steps[]}`). |

### Content discoverability

- `/llms.txt` — site summary for AI crawlers (llmstxt.org spec). Toggle: `lean_seo_llmstxt_enabled`.
- `/llms-full.txt` — full post content export. Default off. Toggle: `lean_seo_llmsfull_enabled`.
- `/sitemap-images.xml` — Google image sitemap (`http://www.google.com/schemas/sitemap-image/1.1` namespace). Separate from WP-native sitemap. Toggle: `lean_seo_image_sitemap_enabled`.
- `/{key}.txt` — IndexNow verification key file. Serves when `lean_seo_indexnow_key` is set.
- IndexNow ping on publish — non-blocking (`wp_remote_post blocking:false`). Hook: `transition_post_status publish`.
- AI crawlers control — 9 bots tracked. Default: allow all. Emits `Disallow: /` per-bot via `robots_txt` filter only for explicitly blocked bots.

### Organization node fields (all optional, all from `wp_options`)

| Option key | Schema.org field |
|---|---|
| `lean_seo_org_type` | `@type` — `Organization` or `NewsMediaOrganization` |
| `lean_seo_org_logo` | `logo.url` (ImageObject) |
| `lean_seo_org_description` | `description` |
| `lean_seo_org_founding_date` | `foundingDate` (ISO: YYYY, YYYY-MM, YYYY-MM-DD) |
| `lean_seo_org_founder_name` | `founder.name` (Person node inline) |
| `lean_seo_org_founder_sameas` | `founder.sameAs[]` (URL-per-line) |
| `lean_seo_org_contact_email` | `contactPoint.email` (ContactPoint, contactType "customer support") |
| `lean_seo_org_same_as` | `Organization.sameAs[]` (URL-per-line) |

### Rank Math interop

- **Fallback (read)**: when `lean_seo_rank_math_fallback = '1'`, reads `rank_math_title/description/canonical_url/facebook_image/robots` as fallback for empty lean-seo fields. Useful during gradual migration.
- **Migration script**: `migration/migrate-from-rank-math.php` — WP-CLI eval-file, dry-run default, `--apply` to write. Idempotent (never overwrites existing lean-seo values). Reports redirect count in `wp_rank_math_redirections` but does not migrate them (use `lean-redirects`).

## What it deliberately does NOT cover

- **Redirects** → `lean-redirects`
- **Keyword auto-linking** → `lean-autolinks`
- **Content analyzer / SEO score** → bloat
- **404 monitor** → server logs + Search Console
- **Visual social preview editor** → meta fields + Rich Results Test
- **WooCommerce / Local SEO schemas** → YAGNI; extend via `lean_seo_jsonld_graph` filter

## Architectural decisions

- **Single PHP file.** Easier to audit, reason about, and version. No autoloader/composer.
- **`_lean_seo_*` underscore-prefixed meta keys** — hidden from default search/sort/listing UIs but explicitly registered as `show_in_rest: true` with `auth_callback: 'edit_posts'`. This is the correct WP 6.x pattern for REST-accessible protected meta.
- **`@graph` JSON-LD pattern** with cross-referenced `@id`s instead of multiple `<script>` blocks. One request, one block, Google parses it correctly. `@id` values must remain stable — never change the `#organization`, `#website`, `#person` fragments.
- **`Organization.@id` is stable even when `@type` is `NewsMediaOrganization`.** The type changes but the `@id` (`{site_url}/#organization`) does not. All publisher/author cross-references depend on this ID being constant.
- **`lean_seo_parse_same_as()`** — shared helper used by Person sameAs, Organization sameAs, and founder sameAs. URL-per-line textarea → `filter_var(FILTER_VALIDATE_URL)` filtered array. Keeps the three use cases consistent.
- **`status_header(200)` required in virtual URL handlers.** WP sets `is_404()` before `template_redirect` fires for paths that do not match any WP query. Without an explicit `status_header(200)`, the HTTP status stays 404 even though the body is served correctly. Fix is in the serving branch only — early-return branches (`feature disabled`, `path mismatch`) must NOT call it, so WP's native 404 handling continues for real 404s.
- **Robots advanced directives ON by default.** Every site wanting rich snippets needs these. Filter `lean_seo_robots_directives` to override.
- **Zero JS, zero CSS** on frontend. Admin meta box uses inline `<style>`. No enqueued files.
- **OG image fallback chain**: explicit meta → featured image → first `<img>` in content → filter default.
- **Native WP `rel_canonical` removed** from `wp_head` to avoid duplicates.
- **IndexNow ping is non-blocking.** Uses `wp_remote_post` with `blocking: false` — post save is not delayed by the HTTP call.
- **Image sitemap uses `template_redirect`** (same pattern as llms.txt) instead of extending `WP_Sitemaps` provider. Simpler, no dependency on WP_Sitemaps internals.
- **AI crawlers registry is a pure function** (`lean_seo_ai_crawlers_registry()`). Returns `array(bot => label)`. Iterating it in `robots_txt` filter adds zero overhead when all bots are allowed (no Disallow emitted for allowed bots).
- **LOC budget revised to `< 2,500`** at v1.3.0 when AEO/schema features were added. This is the new ceiling. If it grows beyond 2,500, evaluate splitting into `lean-seo-aeo` or similar. See decision log below.

## Key design decisions log

| Version | Decision | Reason |
|---|---|---|
| v1.0 | Single file, no autoloader | Simplicity + auditability |
| v1.1 | `show_in_rest` + `auth_callback` on all meta | Pipeline (n8n/Python) must be able to set SEO without touching admin |
| v1.2 | Added llms.txt, IndexNow, Person sameAs | AI discoverability + indexing automation; all in SEO domain |
| v1.3 | Added FAQPage, HowTo, AI crawlers, image sitemap, llms-full.txt, Rank Math migration | AEO/schema expansion; LOC budget raised to 2,500 |
| v1.3.1 | Moved sameAs from Person to Organization node for multi-author sites | Eco is multi-author; Person node is dynamic (per post author); Org node is global |
| v1.3.1 | Extracted `lean_seo_parse_same_as()` shared helper | Three sameAs fields (Person, Org, founder) need same URL-per-line parsing |
| v1.4.0 | Organization enrichment (6 new fields) | Needed for `NewsMediaOrganization` classification + `founder` + `contactPoint` |
| v1.4.1 | `status_header(200)` before serving virtual URLs | WP sets is_404() before template_redirect; content was served with HTTP 404 status |
| v1.4.2 | `add_rewrite_rule()` for all virtual routes + `query_vars` filter + activation/upgrade flush | LiteSpeed intercepts .txt/.xml paths without query string before PHP runs; rewrite rules make WP own the URL so the server routes them through index.php. Auto-flush via `lean_seo_db_version` option covers re-uploads where activation hook does not fire. |
| v1.3 | Monolith over split (`lean-seo` + `lean-seo-aeo`) | AEO features share options and hooks with core SEO; splitting creates circular dependency |

## Gotchas / things to watch

- **Conflict with other SEO plugins**: if Yoast/Rank Math/SmartCrawl is active simultaneously, tags will duplicate. The `lean_seo_conflict_notice` warns in admin but does not auto-disable anything. Switch order: install Lean SEO → verify HTML → deactivate legacy plugin.
- **Theme breadcrumbs**: most themes (GeneratePress, Astra, Kadence) call their own breadcrumb function. To use Lean SEO breadcrumbs, replace the theme call with `lean_seo_breadcrumbs()` in the template.
- **WP-sitemap `lastmod` requires WP 5.5+.** On older WP it silently no-ops.
- **`is_attachment()` noindex**: the robots meta is set; the 301 redirect from attachment URL to file is out of scope (use `lean-redirects`).
- **Image sitemap N+1 note**: `lean_seo_generate_image_sitemap()` calls `get_attached_media('image', $post)` per post during build (cache miss). This is N+1 during sitemap generation, but generation happens only on transient miss (6h TTL) or after `save_post`. Not on every page request — acceptable.
- **Checkbox unchecked in POST**: all boolean settings use a hidden `value="0"` field immediately before the checkbox. Without this, unchecking a checkbox sends no value and WP keeps the old value. Pattern established in v1.2.0 — apply it to any new boolean setting.
- **`_lean_seo_*` underscore prefix**: WP hides these from `get_post_custom()` default listing unless `show_in_rest` is registered. Always use `register_meta()` for any new meta key added — both for REST access and admin edit capability.
- **Rank Math `robots` field**: Rank Math stores robots as a serialized array (e.g., `['noindex', 'nofollow']`). The migration script handles this. The fallback reader in `lean_seo_get()` handles it too — do not assume it is a plain string.
- **IndexNow key file serves at root**: the handler checks `'/' . $key . '.txt' === $path` exactly. If the site is in a subdirectory (`/blog/`), the key file still needs to be at root — `template_redirect` fires after WP boots so `REQUEST_URI` reflects the real path. Test with `curl -I` rather than browser.
- **Rewrite rules require a flush to take effect** (v1.4.2): `add_rewrite_rule()` calls are cheap but the compiled rewrite table in the DB must be regenerated. Flush happens automatically on: (a) activation hook, (b) deactivation hook, (c) upgrade detection via `lean_seo_db_version` option. For manual recovery: Settings → Permalinks → Save. If the routes still return 404, confirm WP rewrite rules are stored: `wp rewrite list --path=/llms.txt` should show the lean_seo_route match. If `LEAN_SEO_DB_VERSION` constant is bumped, also bump the stored option — the auto-flush fires on next `init`.
- **`lean_seo_indexnow_key_slug` query var**: the IndexNow rewrite captures the hex slug from the URL into this var. The handler verifies it matches the stored key — this is intentional to prevent any arbitrary `.txt` at root from being intercepted. If `lean_seo_indexnow_key` is empty, the handler returns immediately (no output, no status_header).

## REST API — integration reference for pipeline agents

All `_lean_seo_*` meta keys are writable via `POST /wp-json/wp/v2/{post_type}/{id}` with `meta` object. Auth: Application Password, user with `edit_posts`.

### Complete meta key list

| Key | Type | Notes |
|---|---|---|
| `_lean_seo_title` | string | `<title>` override |
| `_lean_seo_description` | string | `<meta name="description">` |
| `_lean_seo_canonical` | string | Canonical URL override |
| `_lean_seo_og_image` | string | OG/Twitter image URL |
| `_lean_seo_og_type` | string | `article`, `website`, `profile` |
| `_lean_seo_article_type` | string | `Article`, `NewsArticle`, `BlogPosting`, `TechArticle` |
| `_lean_seo_noindex` | boolean | `true` = noindex |
| `_lean_seo_nofollow` | boolean | `true` = nofollow |
| `_lean_seo_faq` | string (JSON) | `[{"q":"...","a":"..."}]` — FAQPage schema |
| `_lean_seo_howto` | string (JSON) | `{"name":"...","desc":"...","steps":[{"name":"...","text":"..."}]}` — HowTo schema |

### Minimal n8n HTTP Request node body

```json
{
  "meta": {
    "_lean_seo_title": "{{ $json.seo_title }}",
    "_lean_seo_description": "{{ $json.seo_description }}",
    "_lean_seo_article_type": "NewsArticle"
  }
}
```

Set as `Body Content Type: JSON`. Auth: Basic with App Password credentials stored in n8n credentials store.

## Performance budget

| Metric | Budget | How to test |
|---|---|---|
| LOC | < 2,700 (revised v1.4.2 — rewrite rules block ~90 LOC; was 2,500) | `wc -l lean-seo.php` |
| Frontend JS | 0 bytes | DevTools Network tab |
| Frontend CSS | 0 bytes | DevTools Network tab |
| DB queries added in `wp_head` | 0 (uses already-loaded `$post` + `get_option` cache) | Query Monitor |
| DB queries in virtual URL handlers | ≤ 1 transient read (object cache hit = 0 DB) | Query Monitor on `/llms.txt` |
| TTFB delta vs no plugin | < 5 ms | `ab -n 100 https://yoursite.com/` |
| `<head>` bytes added | ~1.5–2 KB | Diff HTML before/after |
| Lighthouse SEO score | 100/100 on a properly configured post | Lighthouse CI |

## Coexistence with Rank Math (cristiantala.com pattern)

Rank Math Pro already ships IndexNow natively. To use Lean SEO only for `llms.txt` + `IndexNow` while keeping Rank Math for canonical/meta/schema, add to `functions.php` or a mu-plugin:

```php
// Suppress lean-seo head output. Keep llms.txt and IndexNow.
add_filter( 'lean_seo_emit_enabled', '__return_false' );
remove_action( 'wp_head', 'lean_seo_emit', 1 );
remove_filter( 'pre_get_document_title', 'lean_seo_filter_title', 20 );
remove_filter( 'get_canonical_url', 'lean_seo_filter_canonical', 10 );
remove_filter( 'wp_robots', 'lean_seo_filter_robots', 20 );
remove_filter( 'wp_sitemaps_posts_entry', 'lean_seo_sitemap_lastmod', 10 );
```

`template_redirect` handlers (llms.txt, key file, image sitemap) and the `transition_post_status` IndexNow ping are unaffected.

**Recommendation**: only full-replace Rank Math when lean-seo is stable on eco and the delta of effort is worth it. Incremental is safer.

## Roadmap (post v1.4.1)

- Per-module disable constants (`LEAN_SEO_MODULE_HEAD`, `LEAN_SEO_MODULE_INDEXNOW`, etc.) so modules can be disabled at bootstrap without hook manipulation — more user-friendly than Option A snippet
- Block bindings API integration (WP 6.5+) for FSE themes
- Per-author sameAs via WP user meta (`get_user_meta($author->ID, 'lean_seo_same_as', true)`) exposed in the user profile screen — replaces global Person sameAs for multi-author sites. Today: Person sameAs is global (single-author use only); Organization sameAs covers the multi-author case
- `wp lean-seo migrate-from-smartcrawl` WP-CLI command
- Optional Gutenberg sidebar panel (`PluginDocumentSettingPanel`) as alternative to meta box

## When iterating on this plugin

Read this file first. Then before adding any feature:

1. Does this belong in `lean-seo` or in another lean plugin?
2. Will this break the performance budget (esp. LOC > 2,500)?
3. Is there a WP-native API in 6.5+ that already solves this?
4. Could this be a filter/hook instead of a new feature?
5. Does any new meta key need to be added to `uninstall.php` cleanup?
6. Does any new boolean setting need the hidden `value="0"` pattern?
7. Does any new virtual URL handler need `status_header(200)` in the serving branch?

If unsure, default to **don't add**. Lean stays lean by saying no.
