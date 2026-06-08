# AGENT.md — Lean SEO

Context for Claude/agents working on this plugin.

## What this plugin is

**Lean SEO** is a single-file WordPress plugin (~780 LOC) that covers the SEO essentials for a modern WP site without the bloat of Yoast/Rank Math/SmartCrawl/AIOSEO. Part of the `lean-*` family.

## What it covers

- Per-post SEO meta (title, description, canonical, og_image, og_type, article_type, noindex, nofollow) registered with `show_in_rest:true` so external scripts (Python, Node, n8n) can set them via REST API
- `wp_head` output: canonical (with paginated/archive/search/404 handling), meta description, Open Graph, Twitter Cards, JSON-LD `@graph`
- JSON-LD `@graph` with cross-referenced `@id`: Organization, WebSite + SearchAction, Person, Article, BreadcrumbList
- Document title override via `wp_get_document_title` filter
- Advanced robots directives (`max-snippet:-1`, `max-image-preview:large`, `max-video-preview:-1`) by default
- Automatic `noindex` for thin contexts: search results, attachment pages, `?replytocom=`, 404
- Breadcrumbs via public function `lean_seo_breadcrumbs()` (HTML) + JSON-LD `BreadcrumbList`
- `<lastmod>` augment to native `wp-sitemap.xml` (WP 5.5+)
- Admin meta box (vanilla PHP, no JS)
- Conflict notice when another SEO plugin is active

## What it deliberately does NOT cover

- **Redirects** → out of scope. Use [`lean-redirects`](https://github.com/ctala/lean-redirects).
- **Keyword auto-linking** → out of scope. Use [`lean-autolinks`](https://github.com/ctala/lean-autolinks).
- **Content analyzer / SEO score** → bloat. Editors should write well, not chase a green light.
- **404 monitor** → server logs + Search Console cover it.
- **Sitemap generation** → WP-native `wp-sitemap.xml` is used; we just add `<lastmod>`.
- **Visual social preview editor** → meta fields + Rich Results Test are enough.
- **Local SEO / WooCommerce / News schemas** → YAGNI. Extend via `lean_seo_jsonld_graph` filter when truly needed.

## Architectural decisions

- **Single PHP file**. Easier to audit, reason about, and version. No autoloader/composer.
- **Underscore-prefixed meta keys (`_lean_seo_*`)** — hidden from default search/sort/listing UIs but explicitly registered as `show_in_rest`.
- **`@graph` JSON-LD pattern** with cross-referenced `@id`s instead of multiple separate `<script>` blocks. This is the 2026 standard followed by Yoast/Rank Math and what Google's docs recommend for cleaner parsing.
- **Robots advanced directives ON by default**. `max-snippet:-1`, `max-image-preview:large`, `max-video-preview:-1` are what every site wanting rich snippets needs. Filter `lean_seo_robots_directives` for override.
- **Zero JS, zero CSS** on frontend. Admin meta box uses inline `<style>` (no enqueue).
- **OG image fallback chain**: explicit meta → featured image → first `<img>` in content → filter default. Posts without featured image still share with preview.
- **Canonical override via `get_canonical_url` filter** to keep WP-native behavior intact when no custom value is set.
- **Native WP `rel_canonical` removed** from `wp_head` to avoid duplicates, since we emit our own.

## Gotchas / things to watch

- **Conflict with other SEO plugins**: if Yoast/Rank Math/SmartCrawl is active simultaneously, tags will duplicate. The `lean_seo_conflict_notice` warns in admin but doesn't auto-disable anything. Switch order: install Lean SEO, verify HTML in frontend, then deactivate the legacy plugin.
- **Theme breadcrumbs**: most themes (GeneratePress, Astra, Kadence) call their own breadcrumb function. To switch to Lean SEO breadcrumbs, edit the theme template and call `lean_seo_breadcrumbs()` where the previous function was.
- **WP-sitemap-augment requires WP 5.5+**. If you target older WP, the `lastmod` filter silently no-ops.
- **`is_attachment()` noindex** is set in robots meta. The actual 301 redirect from attachment URL to the file should be handled by `lean-redirects` with a pattern rule — out of scope here.

## Performance budget

| Metric | Budget | How to test |
|---|---|---|
| LOC | < 900 | `wc -l lean-seo.php` |
| Frontend JS | 0 bytes | DevTools Network |
| Frontend CSS | 0 bytes | DevTools Network |
| DB queries added in `wp_head` | 0 (uses already-loaded $post) | Query Monitor |
| TTFB delta vs no plugin | < 5 ms | `ab -n 100` |
| `<head>` bytes added | ~1.5–2 KB | Diff HTML |
| Lighthouse SEO score | 100/100 on a properly configured post | Lighthouse CI |

## Coexistence with Rank Math (cristiantala.com)

Rank Math Pro already ships IndexNow natively. Installing lean-seo v1.2.0 on cristiantala.com
to get **only llms.txt** requires disabling the lean-seo modules that overlap with Rank Math.

Two options (neither is implemented yet — design only):

**Option A — filter-based surgical disabling (preferred):** add to `functions.php` or a mu-plugin:
```php
// On sites with Rank Math: disable lean-seo's head output and canonical.
// Keep llms.txt and IndexNow active.
add_filter( 'lean_seo_emit_enabled', '__return_false' );         // suppress wp_head output
remove_action( 'wp_head', 'lean_seo_emit', 1 );                  // direct hook removal
remove_filter( 'pre_get_document_title', 'lean_seo_filter_title', 20 );
remove_filter( 'get_canonical_url', 'lean_seo_filter_canonical', 10 );
remove_filter( 'wp_robots', 'lean_seo_filter_robots', 20 );
remove_filter( 'wp_sitemaps_posts_entry', 'lean_seo_sitemap_lastmod', 10 );
```
IndexNow and llms.txt live in `template_redirect` and `transition_post_status` — they are
unaffected by removing the `wp_head` / filter hooks above. This is the minimal-code approach.

**Option B — per-module constants (future v1.3+):** add `define( 'LEAN_SEO_MODULE_HEAD', false );`
support so each module can be disabled at bootstrap without needing hook manipulation.
More user-friendly but adds ~30 LOC and a settings-page toggle surface.

**Recommendation for cristiantala.com today:** since Rank Math already handles IndexNow,
lean-seo there would only add llms.txt value. Use Option A via a snippet in functions.php.
Only migrate when lean-seo is mature enough to replace Rank Math entirely.

## Roadmap (post v1.2.0)

- v1.3: Per-module disable constants (`LEAN_SEO_MODULE_HEAD`, `LEAN_SEO_MODULE_INDEXNOW`, etc.)
- v1.3: Block bindings API integration (WP 6.5+) for FSE themes
- v1.3: Optional Gutenberg sidebar panel (PluginDocumentSettingPanel) as alternative to meta box
- v1.4: Per-site default OG image setting in admin (instead of relying on the filter)
- v1.4: Migration helper command — `wp lean-seo migrate-from-smartcrawl` (WP-CLI)
- v1.4: Migration helper command — `wp lean-seo migrate-from-rank-math` (WP-CLI)

## When iterating on this plugin

Read this file first. Then before adding any feature, ask:
1. Does this belong in `lean-seo` or in another lean plugin?
2. Will this break the performance budget?
3. Is there a WP-native API in 6.5+ that already solves this?
4. Could this be a filter/hook instead of new feature?

If unsure, default to **don't add**. Lean stays lean by saying no.
