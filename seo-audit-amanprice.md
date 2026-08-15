# SEO Audit — amanprice.tech (On-Page Pass)

## Page summary
- **URLs audited**: `/` (home), `/category/{slug}` (category hubs), `/articles/{slug}` (reviews), `/about`
- **Primary queries**: `سعر تكييف + brand في مصر` (articles), `أفضل {category} في مصر` (hubs), `مراجعات أسعار الأجهزة المنزلية مصر` (home)
- **Page roles**: informational/commercial (affiliate review + price hub)

## Score across 8 dimensions

| # | Dimension | Score | Note |
|---|-----------|-------|------|
| 1 | Title tag | Pass (after fix) | Category hubs now get unique keyword-targeted `<title>`; articles use `meta_title`/cleaned title |
| 2 | Meta description | Pass (after fix) | Per-hub descriptions + articles use `meta_description` |
| 3 | Header structure | Pass | Single `<h1>` per page; hero-search/`<h1>` + `<h2>` sections; cards use `<h2>`/`<h3>` correctly |
| 4 | Body content | Pass | Deep review content w/ FAQ, comparison tables, shortcodes; intent answered up top |
| 5 | Internal links | Pass | Header/footer nav, related articles sliders, deal-card title links, footer `sitemap.xml`/`llms.txt` |
| 6 | Images/media | Pass | Descriptive alt, width/height set, `loading="lazy"`, `fetchpriority` on hero imgs, WebP via R2 |
| 7 | URL slug | Pass | Lowercase hyphenated slugs, no dates, no params; filter/search noindexed |
| 8 | On-page schema | Pass (after fix) | Article+Product+FAQPage+BreadcrumbList+ItemList; About/WebSite/Organization |

## Critical fixes (done)
1. **Old brand/domain everywhere** — everything ("أمان ستريم", `amanstream.me`, `contact@amanstream.me`) was leaking into schema, meta, agent cards, error pages, config fallbacks. Rebranded to أمان برايس / `amanprice.tech`:
   - `resources/views/about.blade.php` — full rewrite: AboutPage + FAQPage schemas now `JSON_UNESCAPED` with canonical `amanprice.tech` URLs, correct brand/email, valid `@context`.
   - `app/Http/Controllers/WellKnownController.php` — OpenAPI title/desc, docs, auth.md, agent-card, agent-skills index.
   - `resources/agent-skills/browse-amanstream/SKILL.md` → `browse-amanprice/SKILL.md` (moved).
   - `public/.well-known/mcp/server-card.json`, `components/error-page.blade.php`, `layouts/app.blade.php` (comments, WebMCP tool name), `components/hero-search.blade.php` (tool attrs).
   - `config/app.php` + `config/filesystems.php` defaults, `.env.example`.
2. **Category hubs cannibalized the homepage `<title>`** — every `/category/{slug}` inherited `أمان برايس`. Now each hub gets `سعر {category} وأفضل أنواعه في مصر 2025 | مراجعات وأسعار | أمان برايس` + unique meta description.
3. **Missing ItemList on category hubs** — now emitted (top-10 hub articles) for SERP collection surfaces. BreadcrumbList already present.
4. **`/about` missing from sitemap** — added (changefreq monthly, priority 0.3).

## Important fixes (done)
- Home page default `<title>`/description upgraded to keyword-rich homepage copy.
- WebMCP/A2A/agent discovery consistent with the www canonical host.

## Nice-to-have polish (next)
- Add `sameAs` (social profiles) to Organization schema when profiles exist.
- Enable Cloudflare Turnstile on forms (spam) — out of on-page scope.
- Monitor Bing/Google for rich result eligibility after re-index.

## Verification
- Full suite: **103 passed (443 assertions)**.
- `it_serves_clean_indexable_category_hub_pages` now asserts unique `<title>`, meta description, and `"@type":"ItemList"`.