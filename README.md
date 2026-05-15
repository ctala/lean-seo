# Lean SEO

**The SEO plugin that does the essentials and nothing else.**

Canonical, meta description, Open Graph, Twitter Cards, JSON-LD `@graph` (Organization + WebSite + Person + Article + BreadcrumbList), breadcrumbs, automatic `noindex` for thin contexts, advanced robots directives, and `<lastmod>` augment to WP-native sitemap. **Zero JavaScript on frontend. No bloat. ~780 LOC.**

## Why?

Most SEO plugins (Yoast, Rank Math, SmartCrawl, AIOSEO) load thousands of lines of code, JavaScript, admin analytics, and upsell banners on every page load. Some even keep telemetry callbacks active in the frontend. They want to be your "SEO suite" with score widgets, internal linking analyzers, redirects, schema builders, and breadcrumb editors all bundled.

**Lean SEO does the essentials and lets specialized plugins do everything else.** Pair it with:

- [`lean-redirects`](https://github.com/ctala/lean-redirects) — 301/302/307 redirects with one indexed query
- [`lean-autolinks`](https://github.com/ctala/lean-autolinks) — keyword auto-linking (glossary, affiliates)

Same family, same lean philosophy. Each plugin is independent — switch any of them without losing data from the others.

| Plugin | LOC (est.) | Frontend JS | Frontend CSS | Telemetry | Upsell |
|---|---:|:---:|:---:|:---:|:---:|
| Yoast SEO | ~50,000+ | ✗ Yes | ✗ Yes | ✗ Yes | ✗ Yes |
| Rank Math | ~30,000+ | ✗ Yes | ✗ Yes | ✗ Yes | ✗ Yes |
| SmartCrawl Pro | ~25,000+ | ✗ Yes | ✗ Yes | ✗ Yes | ✗ Yes |
| AIOSEO | ~40,000+ | ✗ Yes | ✗ Yes | ✗ Yes | ✗ Yes |
| Slim SEO | ~3,000 | ✓ None | ✓ None | ✓ None | ✗ Some |
| **Lean SEO** | **~780** | **✓ None** | **✓ None** | **✓ None** | **✓ None** |

## Features

- ✅ Canonical URL (per-post override + correct handling of paginated/archives/search/404)
- ✅ Meta description with smart fallback (custom → excerpt → trimmed content)
- ✅ Open Graph (`og:type` dynamic: article/website/profile)
- ✅ Twitter Cards (`summary_large_image` auto if og:image present)
- ✅ JSON-LD `@graph` with cross-referenced `@id`s: Organization, WebSite + SearchAction, Person, Article, BreadcrumbList
- ✅ Per-post SEO title override (with `wp_get_document_title` filter)
- ✅ Per-post `noindex` / `nofollow` toggles
- ✅ Automatic `noindex` for search results, attachment pages, `?replytocom=`, 404
- ✅ Advanced robots directives by default (`max-snippet:-1`, `max-image-preview:large`, `max-video-preview:-1`)
- ✅ OG image fallback chain: meta → featured → first content `<img>` → site default
- ✅ Breadcrumbs HTML via `lean_seo_breadcrumbs()` + JSON-LD BreadcrumbList
- ✅ `<lastmod>` augment to native `wp-sitemap.xml` (WP 5.5+)
- ✅ REST API exposure of all SEO meta keys
- ✅ Conflict warning notice if Yoast/Rank Math/SmartCrawl/AIOSEO/SEOPress is also active
- ✅ Clean uninstall (removes all post meta + options)

## What's intentionally NOT included

- ❌ Content analyzer / SEO score widget (write well, don't chase the green light)
- ❌ Redirects (use `lean-redirects`)
- ❌ 404 monitor (server logs + GSC cover it)
- ❌ Internal linking analyzer (use `lean-autolinks`)
- ❌ Visual social preview editor (meta fields + Rich Results Test are enough)
- ❌ Local SEO / WooCommerce schema / News schema (YAGNI — extend via `lean_seo_jsonld_graph` filter when needed)
- ❌ Sitemap generation (WP-native `wp-sitemap.xml` is excellent — we just add `<lastmod>`)

## Installation

1. Download the latest ZIP from [Releases](https://github.com/ctala/lean-seo/releases)
2. WordPress admin → **Plugins → Add New → Upload Plugin** → select ZIP
3. **Activate**
4. (Optional) Deactivate your existing SEO plugin once you've verified output

## Usage

### Meta box (post editor)

A "Lean SEO" meta box appears at the bottom of every post/CPT editor with five fields: SEO title, meta description, canonical URL, OG image URL, `og:type`, JSON-LD `Article` type, and `noindex`/`nofollow` toggles. All optional — fallbacks kick in if empty.

### Breadcrumbs in your theme

```php
<?php if ( function_exists( 'lean_seo_breadcrumbs' ) ) : ?>
    <?php lean_seo_breadcrumbs(); ?>
<?php endif; ?>
```

Optional args: `array( 'separator' => '/', 'class' => 'my-breadcrumbs' )`.

### REST API

All eight meta keys are exposed via REST under the `meta` field of any post. Example payload to update from a script:

```json
POST /wp-json/wp/v2/posts/123
{
  "meta": {
    "_lean_seo_canonical": "https://example.com/original-source/",
    "_lean_seo_title": "Custom SEO title",
    "_lean_seo_description": "Custom meta description.",
    "_lean_seo_og_image": "https://cdn.example.com/og.jpg",
    "_lean_seo_og_type": "article",
    "_lean_seo_noindex": true
  }
}
```

Authentication: any user with `edit_posts` capability (e.g., Application Passwords).

### Filters

| Filter | Purpose | Default |
|---|---|---|
| `lean_seo_post_types` | Post types where Lean SEO is active | All public post types |
| `lean_seo_robots_directives` | Advanced robots directives | `max-snippet:-1, max-image-preview:large, max-video-preview:-1` |
| `lean_seo_default_og_image` | Fallback OG image when no featured/content image | `''` (none) |
| `lean_seo_organization_logo` | Logo URL for Organization JSON-LD | `''` (none) |
| `lean_seo_jsonld_graph` | The full `@graph` array before emission | `array(...)` |
| `lean_seo_breadcrumbs_items` | Breadcrumb items array | Auto from current request |
| `lean_seo_breadcrumbs_html` | Final breadcrumbs HTML | Auto |

## Verification after install

Open the frontend HTML source of a post and check that you see:

```html
<!-- Lean SEO 1.0.0 -->
<link rel="canonical" ... />
<meta name="description" ... />
<meta property="og:type" content="article" />
...
<meta name="twitter:card" content="summary_large_image" />
...
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[...]}</script>
<!-- /Lean SEO -->
```

Validate the JSON-LD:
- https://validator.schema.org/
- https://search.google.com/test/rich-results

Validate Lighthouse SEO score should be **100/100** on a properly configured post.

## Requirements

- WordPress **6.2+**
- PHP **7.4+**

## License

GPL v2 or later. See [LICENSE](LICENSE).

## Author

[Cristian Tala](https://cristiantala.com) — building the [Lean](https://github.com/ctala?tab=repositories&q=lean) family of WordPress plugins.
