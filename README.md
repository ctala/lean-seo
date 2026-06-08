# Lean SEO

**The SEO plugin that does the essentials and nothing else.**

Canonical, meta description, Open Graph, Twitter Cards, JSON-LD `@graph` (12 schema types), breadcrumbs, FAQ/HowTo schema, AI crawlers control, `llms.txt`, `llms-full.txt`, IndexNow pings, image sitemap, Organization enrichment, sameAs — automated and REST-accessible. **Zero JavaScript on frontend. No bloat. ~2,450 LOC.**

## Why?

Most SEO plugins (Yoast, Rank Math, SmartCrawl, AIOSEO) load thousands of lines of code, JavaScript, admin analytics, and upsell banners on every page load. Some keep telemetry callbacks active in the frontend. They want to be your "SEO suite" with score widgets, internal linking analyzers, redirects, schema builders, and breadcrumb editors all bundled.

**Lean SEO does the essentials and lets specialized plugins do everything else.** Pair it with:

- [`lean-redirects`](https://github.com/ctala/lean-redirects) — 301/302/307 redirects with one indexed query
- [`lean-autolinks`](https://github.com/ctala/lean-autolinks) — keyword auto-linking (glossary, affiliates)

Same family, same lean philosophy. Each plugin is independent — switch any of them without losing data from the others.

| Plugin | LOC (est.) | Frontend JS | Frontend CSS | Telemetry | Upsell |
|---|---:|:---:|:---:|:---:|:---:|
| Yoast SEO | ~50,000+ | No | No | Yes | Yes |
| Rank Math | ~30,000+ | No | No | Yes | Yes |
| SmartCrawl Pro | ~25,000+ | No | No | Yes | Yes |
| AIOSEO | ~40,000+ | No | No | Yes | Yes |
| Slim SEO | ~3,000 | None | None | None | Some |
| **Lean SEO** | **~2,450** | **None** | **None** | **None** | **None** |

## Features

### Core SEO (every page)

- Canonical URL (per-post override + correct handling of paginated/archives/search/404)
- Meta description with smart fallback (custom → excerpt → trimmed content)
- Open Graph (`og:type` dynamic: article/website/profile)
- Twitter Cards (`summary_large_image` auto if og:image present)
- Per-post SEO title override (via `wp_get_document_title` filter)
- Per-post `noindex` / `nofollow` toggles
- Automatic `noindex` for search results, attachment pages, `?replytocom=`, 404
- Advanced robots directives by default (`max-snippet:-1`, `max-image-preview:large`, `max-video-preview:-1`)
- OG image fallback chain: custom meta → featured → first content `<img>` → site default

### JSON-LD @graph (12 schema types)

All nodes are cross-referenced via `@id` — one clean `<script>` block per page.

| Node | When emitted |
|---|---|
| `Organization` / `NewsMediaOrganization` | Every page — site identity, logo, sameAs, founder, contactPoint |
| `WebSite` + `SearchAction` | Every page — enables sitelinks search box |
| `Person` | Every page — author identity (single-author sites) |
| `Article` / `NewsArticle` / `BlogPosting` / `TechArticle` | Posts/CPTs where `article_type` is set |
| `BreadcrumbList` | Any page with breadcrumbs (archives, categories, single posts) |
| `VideoObject` | Posts with `_lean_seo_video_object` meta |
| `PodcastEpisode` + `PodcastSeries` | Posts with `_lean_seo_podcast` meta |
| `Event` | Posts with `_lean_seo_event` meta |
| `JobPosting` | Posts with `_lean_seo_jobposting` meta |
| `DefinedTerm` | Posts with `_lean_seo_definedterm` meta |
| `ItemList` | Archive/category pages (opt-in via filter) |
| `FAQPage` | Posts with `_lean_seo_faq` meta (JSON array) |
| `HowTo` | Posts with `_lean_seo_howto` meta (JSON object) |

### Content discoverability

- `llms.txt` served at `/llms.txt` — structured site summary for AI crawlers
- `llms-full.txt` served at `/llms-full.txt` — full post content export (opt-in)
- `/sitemap-images.xml` — Google image sitemap (separate from WP-native sitemap)
- `<lastmod>` augment to native `wp-sitemap.xml` (WP 5.5+)
- IndexNow pings on publish (non-blocking, supports Bing/Yandex/Naver)
- AI crawlers control via `robots.txt` (default: allow all; per-bot Disallow opt-in)

### Organization

- `@type`: `Organization` or `NewsMediaOrganization`
- `logo`, `description`, `foundingDate`, `founder` (Person node with sameAs), `contactPoint` (customer support email), `sameAs` (social profiles)

### Breadcrumbs

- HTML via `lean_seo_breadcrumbs()` + JSON-LD `BreadcrumbList` (same data, zero duplication)

### Admin

- Meta box on every post/CPT (vanilla PHP, no JS framework, no CSS file)
- Live character counters (green/orange/red) for title and description fields
- Conflict warning if Yoast/Rank Math/SmartCrawl/AIOSEO/SEOPress is also active
- Clean uninstall — removes all `_lean_seo_*` post meta + all plugin options

### Migration / compatibility

- Rank Math fallback: reads `rank_math_*` meta when `lean_seo_*` fields are empty
- Migration script: `migration/migrate-from-rank-math.php` (WP-CLI, dry-run safe)

### Length guidelines (defaults)

| Field | Optimal | Hard limit | Source |
|---|---|---|---|
| SEO title | 30–60 chars | 70 chars | Google desktop truncates ~580px (≈60 chars) |
| Meta description | 120–155 chars | 160 chars | Google shows up to 155 desktop, ~120 mobile |
| OG image | 1200×630 (1.91:1) | — | Twitter/X + LinkedIn card preview |

Customize via the `lean_seo_length_guidelines` filter.

## What is intentionally NOT included

- Redirects → use `lean-redirects`
- 404 monitor → server logs + GSC cover it
- Internal linking analyzer → use `lean-autolinks`
- Content analyzer / SEO score widget (write well, do not chase the green light)
- Visual social preview editor (meta fields + Rich Results Test are enough)
- Sitemap generation (WP-native `wp-sitemap.xml` handles it — we only add `<lastmod>`)

## Installation

1. Download the latest ZIP from [Releases](https://github.com/ctala/lean-seo/releases)
2. WordPress admin → **Plugins → Add New → Upload Plugin** → select ZIP
3. Activate
4. (Optional) Deactivate your existing SEO plugin once you have verified output

## Configuration

All settings live at **Settings → Lean SEO**.

### IndexNow

| Field | Description |
|---|---|
| IndexNow API key | 32–128 hex string. Lean SEO serves `/{key}.txt` at root automatically. Get yours at [IndexNow.org](https://www.indexnow.org/). For eco: key `dc2ebb5760ac4dcd9c71c030fea11768` (set manually — never auto-generated). |

### Organization (datos estructurados)

| Field / option key | Description |
|---|---|
| `lean_seo_org_type` | `Organization` (default) or `NewsMediaOrganization`. Changes `@type` in JSON-LD; `@id` stays `#organization` so all publisher/author cross-references are unaffected. |
| `lean_seo_org_logo` | Absolute URL to logo image → `logo: {ImageObject}`. Takes precedence over the `lean_seo_organization_logo` filter. |
| `lean_seo_org_description` | Short description string → `description`. |
| `lean_seo_org_founding_date` | ISO date (YYYY, YYYY-MM, or YYYY-MM-DD) → `foundingDate`. |
| `lean_seo_org_founder_name` | Founder full name → `founder: {Person, name}`. Not emitted if empty. |
| `lean_seo_org_founder_sameas` | Founder social URLs, one per line → `founder.sameAs[]`. Only emitted when `founder_name` is set. |
| `lean_seo_org_contact_email` | Support email → `contactPoint: {ContactPoint, contactType: "customer support", email}`. |
| `lean_seo_org_same_as` | Organization social profiles, one per line → `Organization.sameAs[]`. Use this on multi-author sites. |

### Person (single-author sites)

| Field / option key | Description |
|---|---|
| `lean_seo_same_as` | Author personal URLs, one per line → `Person.sameAs[]`. On multi-author sites use `lean_seo_org_same_as` instead — this field attaches to every post's author node. |

### llms.txt / llms-full.txt

| Field / option key | Description |
|---|---|
| `lean_seo_llmstxt_enabled` | Toggle `/llms.txt` (default on). Serves a structured site summary following the [llmstxt.org](https://llmstxt.org) spec. |
| `lean_seo_llmsfull_enabled` | Toggle `/llms-full.txt` (default off). Includes post body content (strip_tags + markdown-ish). Configure post count and chars-per-post in settings. |

### Image sitemap

| Field / option key | Description |
|---|---|
| `lean_seo_image_sitemap_enabled` | Toggle `/sitemap-images.xml` (default on). Separate from `wp-sitemap.xml`. Add this URL to Google Search Console alongside the WP-native sitemap. |

### AI crawlers (robots.txt)

Nine bots tracked: `GPTBot`, `Claude-Web`, `ClaudeBot`, `CCBot`, `anthropic-ai`, `Google-Extended`, `FacebookBot`, `Bytespider`, `cohere-ai`. Default: **allow all** (no Disallow lines added). Set any bot to "Disallow" in settings to emit `Disallow: /` for that agent in `robots.txt`. This appends to WP's native `robots.txt` filter — does not write any file.

### Rank Math fallback

| Option key | Description |
|---|---|
| `lean_seo_rank_math_fallback` | When `1`, reads `rank_math_title`, `rank_math_description`, `rank_math_canonical_url`, `rank_math_facebook_image`, `rank_math_robots` as fallback when the corresponding `_lean_seo_*` field is empty. Useful during gradual migration. |

## Usage

### Meta box (post editor)

A "Lean SEO" meta box appears at the bottom of every post/CPT editor with fields: SEO title, meta description, canonical URL, OG image URL, `og:type`, `article_type`, FAQ (JSON), HowTo (JSON), and `noindex`/`nofollow` toggles. All optional — fallbacks apply when empty.

### Breadcrumbs in your theme

```php
<?php if ( function_exists( 'lean_seo_breadcrumbs' ) ) : ?>
    <?php lean_seo_breadcrumbs(); ?>
<?php endif; ?>
```

Optional args: `array( 'separator' => '/', 'class' => 'my-breadcrumbs' )`.

---

## REST API — automating SEO per-post

This is the primary integration point for pipelines (n8n, Python, Node). All `_lean_seo_*` meta keys are registered with `show_in_rest: true` — they are readable and writable via the standard WP REST API without any custom endpoints.

### Authentication

Use [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) (WP 5.6+). Requires a user with `edit_posts` capability.

```
Authorization: Basic base64(username:app-password)
```

### Meta keys reference

| Meta key | Type | Description |
|---|---|---|
| `_lean_seo_title` | string | SEO `<title>` override. Falls back to WP-native if empty. |
| `_lean_seo_description` | string | `<meta name="description">`. Falls back to excerpt → trimmed content. |
| `_lean_seo_canonical` | string (URL) | Canonical URL override. Falls back to WP-native permalink. |
| `_lean_seo_og_image` | string (URL) | OG/Twitter image. Falls back to featured → first content `<img>`. |
| `_lean_seo_og_type` | string | `article`, `website`, `profile`. Default: `article` for posts. |
| `_lean_seo_article_type` | string | `Article`, `NewsArticle`, `BlogPosting`, `TechArticle`. Used in JSON-LD. |
| `_lean_seo_noindex` | boolean | `true` → `<meta name="robots" content="noindex">`. |
| `_lean_seo_nofollow` | boolean | `true` → adds `nofollow` to robots meta. |
| `_lean_seo_faq` | string (JSON) | FAQ schema. Array of `{q, a}` objects. See example below. |
| `_lean_seo_howto` | string (JSON) | HowTo schema. Object with `name`, `desc`, `steps[]`. See example below. |

### Endpoint

```
POST /wp-json/wp/v2/{post_type}/{id}
```

Replace `{post_type}` with the REST base of your post type (`posts`, `pages`, or a CPT slug). Replace `{id}` with the post ID.

### curl example — set SEO on a news article

```bash
curl -s -X POST \
  "https://example.com/wp-json/wp/v2/posts/4521" \
  -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "meta": {
      "_lean_seo_title": "Startup raises $5M Series A — Ecosistema Startup",
      "_lean_seo_description": "The round was led by Kaszek Ventures. The company plans to expand to three new markets in 2026.",
      "_lean_seo_article_type": "NewsArticle",
      "_lean_seo_og_image": "https://cdn.example.com/images/series-a-og.jpg",
      "_lean_seo_noindex": false
    }
  }'
```

### curl example — set FAQ schema

```bash
curl -s -X POST \
  "https://example.com/wp-json/wp/v2/posts/4521" \
  -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "meta": {
      "_lean_seo_faq": "[{\"q\":\"What is a Series A?\",\"a\":\"A Series A is the first significant round of venture capital financing.\"},{\"q\":\"Who led this round?\",\"a\":\"Kaszek Ventures led the round.\"}]"
    }
  }'
```

### curl example — read current SEO meta

```bash
curl -s \
  "https://example.com/wp-json/wp/v2/posts/4521?context=edit" \
  -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  | python3 -m json.tool | grep _lean_seo
```

### n8n pipeline pattern

Create or update a post and set SEO in a single request. In the "Edit Fields" node of the HTTP Request:

```json
{
  "title": "{{ $json.post_title }}",
  "content": "{{ $json.post_content }}",
  "status": "publish",
  "meta": {
    "_lean_seo_title": "{{ $json.seo_title }}",
    "_lean_seo_description": "{{ $json.seo_description }}",
    "_lean_seo_article_type": "NewsArticle",
    "_lean_seo_og_image": "{{ $json.featured_image_url }}"
  }
}
```

This sets the SEO fields atomically with the post creation — no second request needed. The `meta` field in WP REST accepts only keys that are registered as REST-exposed, so no risk of accidental pollution from unregistered keys.

---

## Virtual routes served

Routes are registered as WP rewrite rules (v1.4.2) mapping to the `lean_seo_route` query var. This ensures LiteSpeed, Nginx, and Apache pass the request through `index.php` instead of returning 404 from the filesystem layer for non-existent `.txt`/`.xml` files. Each handler also calls `status_header(200)` explicitly (v1.4.1) since WP sets `is_404()` before `template_redirect` for unmatched queries.

| Route | Content-Type | Toggle | Notes |
|---|---|---|---|
| `/llms.txt` | `text/plain` | `lean_seo_llmstxt_enabled` (default on) | Site summary following llmstxt.org spec. Cached 12h via transient. |
| `/llms-full.txt` | `text/plain` | `lean_seo_llmsfull_enabled` (default off) | Full post content. Configurable post count + chars per post. Cached 12h. |
| `/sitemap-images.xml` | `application/xml` | `lean_seo_image_sitemap_enabled` (default on) | Google image sitemap namespace. Up to 1,000 posts (filterable). Cached 6h. |
| `/{key}.txt` | `text/plain` | IndexNow key must be set | Serves the IndexNow verification key. Required by Bing/Yandex to validate domain ownership. |

Disabled features (`lean_seo_llmstxt_enabled = 0`, etc.) return immediately without setting any status — WP's native 404 continues normally.

### After uploading v1.4.2 — required flush step

The activation hook fires on first install. For a **re-upload / manual ZIP install** of an already-active plugin, WordPress does not re-fire the activation hook, so the rewrite table is not automatically updated.

The plugin detects this via the `lean_seo_db_version` option and flushes automatically on the next page load after upload. If for any reason the auto-flush does not trigger (cached `init`, object cache holding stale data), flush manually:

```
WordPress admin → Settings → Permalinks → Save Changes
```

Or via WP-CLI:

```bash
wp rewrite flush
```

Verify the routes are registered:

```bash
wp rewrite list | grep lean_seo
```

---

## Migration from Rank Math

### Option 1 — Fallback (read-only, zero risk)

Enable **Settings → Lean SEO → Rank Math Fallback**. When a `_lean_seo_*` field is empty, Lean SEO reads the equivalent Rank Math meta key instead. This means Lean SEO works immediately post-install with zero data migration. Migrate fields to `_lean_seo_*` gradually at your own pace.

Fallback map:

| Rank Math key | Lean SEO key |
|---|---|
| `rank_math_title` | `_lean_seo_title` |
| `rank_math_description` | `_lean_seo_description` |
| `rank_math_canonical_url` | `_lean_seo_canonical` |
| `rank_math_facebook_image` | `_lean_seo_og_image` |
| `rank_math_robots` | `_lean_seo_noindex` / `_lean_seo_nofollow` |

### Option 2 — Batch migration script (WP-CLI)

```bash
# Dry run first — shows what would change, touches nothing
wp eval-file migration/migrate-from-rank-math.php

# Apply
wp eval-file migration/migrate-from-rank-math.php --apply
```

The script is idempotent: it never overwrites an existing `_lean_seo_*` value. Run it as many times as needed. It also reports the count of Rank Math redirects found in `wp_rank_math_redirections` — those must be migrated separately to `lean-redirects` (out of scope of this script).

### Coexistence with Rank Math (cristiantala.com pattern)

If you want Lean SEO for only specific features (e.g., `llms.txt` + `IndexNow`) while keeping Rank Math for canonical/meta/schema, add this to `functions.php` or a mu-plugin:

```php
// Keep Rank Math for <head> output. Use Lean SEO only for llms.txt + IndexNow.
add_filter( 'lean_seo_emit_enabled', '__return_false' );
remove_action( 'wp_head', 'lean_seo_emit', 1 );
remove_filter( 'pre_get_document_title', 'lean_seo_filter_title', 20 );
remove_filter( 'get_canonical_url', 'lean_seo_filter_canonical', 10 );
remove_filter( 'wp_robots', 'lean_seo_filter_robots', 20 );
remove_filter( 'wp_sitemaps_posts_entry', 'lean_seo_sitemap_lastmod', 10 );
```

`template_redirect` handlers (llms.txt, IndexNow key file) and the `transition_post_status` ping hook are unaffected by the above.

---

## Real-world config example (ecosistemastartup.com)

```bash
# Set via WP-CLI or REST — NewsMediaOrganization for a news/media site
wp option update lean_seo_org_type 'NewsMediaOrganization'
wp option update lean_seo_org_logo 'https://ecosistemastartup.com/wp-content/uploads/logo.png'
wp option update lean_seo_org_description 'El medio de referencia del ecosistema startup latinoamericano.'
wp option update lean_seo_org_founding_date '2018'
wp option update lean_seo_org_founder_name 'Cristian Tala'
wp option update lean_seo_org_founder_sameas 'https://linkedin.com/in/ctala
https://cristiantala.com
https://twitter.com/ctala'
wp option update lean_seo_org_contact_email 'hola@ecosistemastartup.com'
wp option update lean_seo_org_same_as 'https://linkedin.com/company/ecosistema-startup
https://twitter.com/EcoSistemaStart
https://instagram.com/ecosistemastartup'
wp option update lean_seo_indexnow_key 'dc2ebb5760ac4dcd9c71c030fea11768'
wp option update lean_seo_llmstxt_enabled '1'
wp option update lean_seo_image_sitemap_enabled '1'
```

---

## Filters

| Filter | Purpose | Default |
|---|---|---|
| `lean_seo_post_types` | Post types where Lean SEO is active | All public post types |
| `lean_seo_robots_directives` | Advanced robots meta directives | `max-snippet:-1, max-image-preview:large, max-video-preview:-1` |
| `lean_seo_default_og_image` | Fallback OG image when no featured/content image found | `''` |
| `lean_seo_organization_logo` | Logo URL fallback (overridden by `lean_seo_org_logo` option) | `''` |
| `lean_seo_jsonld_graph` | The full `@graph` array before emission — add/remove nodes | `array(...)` |
| `lean_seo_breadcrumbs_items` | Breadcrumb items array | Auto from current request |
| `lean_seo_breadcrumbs_html` | Final breadcrumbs HTML | Auto |
| `lean_seo_image_sitemap_limit` | Max posts in image sitemap | `1000` |
| `lean_seo_length_guidelines` | Title/description char limits for admin counter | `array(...)` |

## Verification after install

Open the frontend HTML source of a post and confirm:

```html
<!-- Lean SEO 1.4.1 -->
<link rel="canonical" ... />
<meta name="description" ... />
<meta property="og:type" content="article" />
<meta name="twitter:card" content="summary_large_image" />
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[...]}</script>
<!-- /Lean SEO -->
```

Then validate:

- JSON-LD: https://validator.schema.org/
- Rich results: https://search.google.com/test/rich-results
- llms.txt: `curl -I https://yoursite.com/llms.txt` → expect `HTTP/2 200` (not 404)
- IndexNow key: `curl -I https://yoursite.com/{key}.txt` → expect `HTTP/2 200`
- Image sitemap: `curl -I https://yoursite.com/sitemap-images.xml` → expect `HTTP/2 200`

Lighthouse SEO score should be **100/100** on a properly configured post.

## Requirements

- WordPress **6.2+**
- PHP **7.4+**

## License

GPL v2 or later. See [LICENSE](LICENSE).

## Author

**Cristian Tala** — [cristiantala.com](https://cristiantala.com) · [GitHub](https://github.com/ctala) · [LinkedIn](https://www.linkedin.com/in/ctala/)

Building the [Lean family](https://github.com/ctala?tab=repositories&q=lean) of WordPress plugins — independent, single-responsibility, no bloat.

> Original work by Cristian Tala. Forks and commercial derivatives are welcome under GPL terms, but **must retain the copyright notice and link back to this repository** (`https://github.com/ctala/lean-seo`). See [LICENSE](LICENSE) for full attribution requirements.
