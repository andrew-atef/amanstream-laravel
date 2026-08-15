# Technical SEO Audit — www.amanprice.tech

Date: 2026-08-15
Method: seo-technical skill v2.2.4 (9 categories). Live responses + source inspection. No PSI/CrUX/DataForSEO/GSC credentials available in this environment.
Audited URLs: `https://www.amanprice.tech/`, `/about`, `/category/{slug}`, `/articles/{slug}`, `/robots.txt`, `/sitemap.xml`, `/cart`, `/?page=2`.

## Technical Score: 82/100

## Category Breakdown

| Category | Status | Score |
|----------|--------|-------|
| Crawlability | pass | 88/100 |
| Indexability | pass | 90/100 |
| Security | warn | 65/100 |
| URL Structure | pass | 95/100 |
| Mobile | pass | 90/100 |
| Core Web Vitals | warn | 70/100 |
| Structured Data | pass | 92/100 |
| JS Rendering | pass | 95/100 |
| IndexNow | pass | 95/100 |

## Evidence per category

### 1. Crawlability — 88/100
- robots.txt: served 200 `text/plain`; Googlebot/Bingbot unrestricted; `/admin` + `/cart` disallowed; `Sitemap:` present. **Note:** production appends Cloudflare's edge-managed AI-crawler block (GPTBot/ClaudeBot/Bytespider/CCBot/Google-Extended disallowed, ~3-5% of sites pattern). The previous app-level `GPTBot Allow` / `PerplexityBot Allow` lines were dead (first-match robots rule favors the earlier Cloudflare block) — removed to avoid confusion. AI visibility is now governed at the edge by Cloudflare managed rules.
- Sitemap: `sitemap.xml` 200, 27 URLs (`category` pages, 21 articles, `/about`). Homepage URL uses no trailing slash.
- Crawl depth: all key pages ≤2 clicks from homepage. No noindex on public pages except explicit per-view controls.
- HTML size well under Googlebot's 2MB fetch cap (homepage 85 KB, article 93 KB) — no die problem from oversized HTML/JSON-LD.
- AI crawler management noted; decision documented above.

### 2. Indexability — 90/100
- Canonicals self-referencing on homepage, about, article, category pages; `?page=2` canonicalizes back to the no-param page URL — consistent with Google 2025 canonical guidance (no conflicts between raw HTML and JS since page is SSR).
- Duplicate content: `/about` vs `/about/` both serve 200 but canonical points to `/about` (no trailing-slash canonical conflict). No parameter bloat. Old `?category=` remains 301-free backwards-compat; canonical keeps index clean.
- Hreflang not applicable (single-language Arabic, EG only).
- Pagination (12/article page): canonical dedupes param URLs; no `rel=next/prev` needed given canonicalization strategy.
- Thin content risk: category hubs carry unique title/meta + ItemList (10 items) — acceptable.

### 3. Security — 65/100 (warn)
- HTTPS enforced: http→301, bare→`www` 301, valid Cloudflare cert, no mixed content (0 `http://` refs on homepage).
- HSTS `max-age=31536000` present.
- **FIXED this audit:** added `SecurityHeaders` middleware (registered in `bootstrap/app.php`) emitting:
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()`
  - CSP: `default-src 'self'; script-src 'self' 'unsafe-inline' https://www.googletagmanager.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https://media.amanprice.tech; connect-src 'self' https://www.google-analytics.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'`
- Tests added: `tests/Feature/SecurityHeadersTest.php` (index + about).
- Back-button hijacking: no `history.pushState/replaceState` in app JS — clean (2026 spam-policy Critical rule not triggered).

### 4. URL Structure — 95/100
- Clean Arabic-slug + ASCII route slugs, hyphenated, descriptive (`/category/{slug}`, `/articles/{slug}`), no content in query strings.
- Redirects: single hop only (bare→www 301), no chains. Content routes don't 301 unexpectedly.
- URL lengths fine (<100 chars). Trailing slashes consistent via canonical.

### 5. Mobile & Page Experience — 90/100
- Responsive Tailwind layout, `<meta name="viewport" content="width=device-width, initial-scale=1">`, RTL Arabic.
- No mobile/desktop content parity loss (single SSR template). Top Deals grid, hero search, and primary article content are server-rendered (no lazy-load-gating of critical content).
- Touch targets/16px font: standard Tailwind defaults; not field-tested (would need Lighthouse/device). No intrusive interstitials observed.

### 6. Core Web Vitals — 70/100 (warn)
- No CrUX field data available (low-traffic site; PSI/CrUX API not configured). Lab proxy required.
- SSH-informed signals only: fonts preloaded + `display=optional` (avoids swap-CLS), GTAG deferred via `requestIdleCallback` (keeps TBT low), only ~85 KB HTML.
- Recommend: run `claude-seo run pagespeed_check.py https://www.amanprice.tech/ --json` with PSI creds, then re-test; revisit after traffic grows.

### 7. Structured Data — 92/100
- JSON-LD present and valid in SSR HTML: `Organization` (all pages via layout), `WebSite+SearchAction` (home), `BreadcrumbList` + `ItemList` (category hubs), `Product` + `Article` + `BreadcrumbList` + `ItemList` + `FAQPage` (article pages), `AboutPage` + `FAQPage` (`/about`).
- All Product markup is inside initial HTML (Google Dec-2025 guidance satisfied — no JS-injected schema). Article/official product page uses 5 JSON-LD blocks.
- No validation import possible offline; structure matches supported types.

### 8. JavaScript Rendering — 95/100
- Pure SSR (Blade); no SPA/CSR framework. Canonical, robots, title/description, and structured data all served in raw HTML (Google Dec-2025 JavaScript SEO guidance satisfied).
- No non-200-page JS pitfalls; `/cart` returns 404 (routed API-only), error pages inert.

### 9. IndexNow Protocol — 95/100
- Implemented: `InstantIndexingService::notifyIndexNow()` POSTs {host, key, keyLocation, urlList} JSON to IndexNow endpoints (Bing/Yandex/Naver); test covers exact payload.
- Key file `https://www.amanprice.tech/1bc2ec6150614ec9bf0a39c192f87f87.txt` serves 200; `/{{key}}.txt` route registered.
- Not a Google protocol — affects only non-Google engines; correct scope.

## Critical Issues (fix immediately)
- None.

## High Priority (fix within 1 week)
1. **Stricter CSP hardening (optional).** Current CSP uses `'unsafe-inline'` for scripts to keep the inline GTAG bootstrap working. If you want defense-in-depth, migrate inline scripts to a nonce/hash approach and remove `'unsafe-inline'`. Low index-impact (skill: HTTPS is the confirmed lightweight signal; don't over-weight), so purely a hardening choice.
2. **Confirm Cloudflare AI-crawler managed block matches desired policy.** Verify in Cloudflare Settings that the managed robots rules align with whether you want GPTBot/ClaudeBot to index; the app-level rules no longer claim allow.
3. **PSI/CrUX baseline.** Run a real Lighthouse/PSI run once and record LCP/INP/CLS; no field data today.

## Medium Priority (fix within 1 month)
- Add `Sitemap:` to the app robots **and** confirm Cloudflare edge serves it (live check today: Cloudflare block lists Sitemap — keep consistent).
- Consider `<link rel="alternate">` hreflang block if a multi-language version ever ships (single-lang now: N/A).

## Low Priority (backlog)
- After traffic: enable CrUX monitoring; re-score CWV with field percentile.
- Introduce `Section`-based JSON-LD validation tooling or a schema linter in CI if schema grows.
- Monitor back-button-hijacking compliance if third-party ad/library scripts are ever added (2026 policy).

## Recommended next steps
1. (Optional) Deploy security headers to production and re-verify with curl (expect the 5 new headers).
2. Run PSI once with credentials to capture a lab baseline for CWV.
3. Review Cloudflare robots managed rules against the AI-visibility strategy.

---

## Cloudflare AI Diagnostics follow-up (2026-08-15)

Source: Cloudflare Diagnostics dashboard dump for `amanprice.tech`.

### Dashboard scan result
- **Quick Wins (Level 1): 5/5** — robots.txt valid, sitemap, AI crawler rules, Content Signals, Markdown negotiation all pass.
- **Technical Groundwork (Level 2): 2/3** — Auth.md + API Catalog pass; **Link Headers was the miss**.
- **Advanced Integration (Level 3): 3/8** — MCP/agent surfaces incomplete.
- **Commerce (Optional): 0/5** — informational, not scored.
- Demand signals: 11 (47.62%) — `media.amanprice.tech/sitemap.xml` (10 req) + `/` (1 req) **fail**.
- AI answer retrievals: 94 (52.76%); top operator Anthropic Claude-SearchBot (62 req, 56.9%), ChatGPT-User (18), Applebot (14).
- Robots.txt: all three hostnames serve Cloudflare-managed robots with **Declared** Content Signals; no violations.
- Managed robots.txt instantiated Cloudflare AI-crawler block; `media`/`www`/bare all healthy (robots 21/12/9 successful).

### Root causes found & fixed in code
1. **Link Headers (Level 2).** The Cloudflare reference format expects `rel="describedby"` for the **MCP server card** in the `Link` header, not only api-catalog/sitemap/llms.txt. Fixed in `ServeMarkdownForAgents::withDiscoveryHeaders()` (added `/.well-known/mcp/server-card.json; rel="describedby"; type="application/json"`).
2. **MCP server card + Web Bot Auth served as `text/plain`.** Both static files under `public/.well-known/` were being mime-served by the web server with the wrong content type, so the `type="application/json"` reference in the Link header was false. Moved them to controller routes (`WellKnownController::mcpServerCard()`, `webBotAuthDirectory()`) with explicit `application/json; charset=utf-8`, removed the static shadow files, added routes `/.well-known/mcp/server-card.json` and `/.well-known/http-message-signatures-directory`.
3. **Advanced routes 404 in production (likely Level-3 culprit).** `/agent-card.json`, `/agent-skills/index.json`, `/agent-skills/{skill}/SKILL.md` return **404 on production today** (verified; not a cache issue) while the same routes work locally (`/api-catalog`, `/openapi.json`, `/docs`, `/auth.md` respond fine). **Conclusion: production is running a build older than the local routes. Deploy the current code** (well-known routes + above fixes) and re-run the scan. Local verification of all six `.well-known` endpoints returned 200 with correct content types.
4. **media host demand failures.** `media.amanprice.tech` is the R2 image origin; AI assistants probing `/sitemap.xml`, `/` and `/llms.txt` there get 404 since only `robots.txt` exists at the edge. This is **not** fixable in app code — needs a Cloudflare edge rule (redirect `media.amanprice.tech/*` sitemap/root paths → `https://www.amanprice.tech/...`) or an R2 object for `/sitemap.xml`. It is safe to ignore for ranking but inflates the demand-signal failure count.

### Code changes this pass
- `app\Http\Middleware\ServeMarkdownForAgents.php` — Link header now includes MCP server-card with `type="application/json"`.
- `app\Http\Controllers\WellKnownController.php` — new `mcpServerCard()` + `webBotAuthDirectory()`.
- `routes\web.php` — two new `.well-known` routes.
- `public\.well-known\mcp\server-card.json` + `public\.well-known\http-message-signatures-directory` **removed** (now served by routes).
- `tests\Feature\ProtocolDiscoveryTest.php` — +4 tests (MCP card, Web Bot Auth, agent card, skills index).
- Local verification (php artisan serve): all 6 `.well-known` endpoints 200 with correct content types; homepage Link header includes the server-card reference.

### Next Cloudflare actions (outside code)
1. **Deploy current code to production**; then re-run Diagnostics (expect Level 2 → 3/3 and more Level 3 items to flip once agent-card.json/index.json stop 404ing).
2. Add a **Cloudflare redirect rule** for `media.amanprice.tech` `/sitemap.xml` (and `/`, `/llms.txt`) → `https://www.amanprice.tech/...` to clear the 10 failed demand signals.
3. Optionally enable Cloudflare "Markdown for Agents" (Pro) — the app already implements equivalent content negotiation in-repo, so only do it if you want edge-level coverage without code.
4. Re-check PSI/CrUX once traffic scales; Document the `Content-Signal: search=yes,ai-train=no,use=reference` policy as an active choice (currently Cloudflare-managed).