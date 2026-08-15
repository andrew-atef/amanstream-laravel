# Off-Page SEO Strategy — AmanPrice (amanprice.tech)

## 1. Current backlink profile summary

**DATA GAP (stated per skill rule — not fabricated):** A backlink profile audit requires a paid tool (Ahrefs/Semrush/Moz/Majestic) plus Google Search Console/GSC API access. Neither the CLI nor this repo provides those signals, so the following "current profile" items **could not be measured**:

- Total referring domains ❌ (needs Ahrefs/GSC)
- Domain rating / DR trend ❌ (needs Ahrefs/Moz)
- Top referring pages by traffic value ❌ (needs Ahrefs/GSC)
- Anchor-text distribution (exact-match risk) ❌ (needs Ahrefs/Semrush)
- Toxic-link inventory ❌ (needs Semrush/Majestic toxicity report)
- Lost-link list / DR of lost domains ❌ (needs Ahrefs "Lost backlinks" report)

**Gap impact:** the quarterly mix below assumes a *young authority profile* (the domain is new/rebranded — see Technical Summary). Paste the Ahrefs/Semrush export into the tracking sheet in step 7 once available; targets in step 4 are prioritized to never depend on a specific DR baseline.

**Confirmed from codebase (no tool needed):**
- Site serves: XML sitemap, `llms.txt`, OpenAPI, agent-skills, RFC 9727 catalog, canonical www host, Image XML sitemap, IndexNow key file + JSON submission. All crawlability/verify-ownership signals are in place.
- Rebrand to `amanprice.tech` is complete (on-page pass) so all external signals must point at the **one** canonical host: `https://www.amanprice.tech`.

## 2. Goal and target metrics

Primary: build entity authority for the **"أمان برايس / AmanPrice Egypt"** brand and earn editorial + professional links in the Egyptian consumer-tech / price-comparison space.

- **North star (3–6 months):** ≥ 15 referring domains (5 unique, using medium-high quality per the mix below).
- Supporting metrics:
  - ≥ 2 linkable assets publicly pitchable (exists today: installment calculator + price-history tracker; add 1 data report Q1).
  - 2 affiliation/partnership integrations (finance blogs, bank-adjacent buyer guides, YouTube tech channels) per quarter.
  - ≥ 1 Arabic tech/finance media mention per quarter (earned).
  - Anchor mix target at end of period: brand 50–60%, naked URL 10–15%, generic 15–20%, partial 10%, exact-match <10%.

## 3. Strategy mix (allocation across the 4 types)

Site phase: launched, rebranded, technically strong. First-year mix recommended by the skill: **70/20/10/0** (assets / citations / partnerships / earned).

| Strategy | Weight | Why |
|---|---|---|
| Owned assets | 70% | Calculators/trackers already built; cheap to pitch repeatedly; compounds |
| Citations/listings | 20% | Foundational entity legitimacy for a young domain |
| Partnerships | 10% | Affiliate-native: YouTube reviewers, comparison blogs, bank-finance explainers |
| Earned media | 0% (start Q3+) | Requires PR budget/proof; scale after DR shows a floor |

## 4. Prospecting lists

### Owned assets to promote (live in codebase today)
- **حاسبة التقسيط البنكية** — `resources/views/components/shortcodes/interactive-installment.blade.php` (live per product).
- **مؤشر تاريخ السعر "كان بكام"** — `resources/views/components/shortcodes/price-history.blade.php` (lowest/highest/current + chart).
- **جداول المقارنة** — `resources/views/components/shortcodes/comparison-table.blade.php`.
- **بيانات أسعار يومية** — sitemap + `llms.txt` + OpenAPI (pitchable to AI-tool directories and data directories).
- **Q1 build:** quarterly "متوسط أسعار التكييفات في مصر" data report (pull from existing price data).

### A. Citations/listings (setup, then refresh quarterly) — *verify criteria before submitting; only review-quality*
- Bing Places / Microsoft Bing Webmaster (already using IndexNow + BWT key).
- Google Business Profile IF the brand operates a physical/contactable table-stakes presence (service-area listing; verify address policy first).
- Wikidata + Wikipedia **only if** notability policy is met — do NOT add before it is; a failed candidate draft is worse than none.
- Arabic consumer-tech directories with editorial review ONLY (skip general web directories — spam risk).
- Bank/installment finance aggregator sites that list "حاسبة قسط" tools.

### B. Partnerships (target 50–100 candidates across)
- YouTube tech-review channels covering Egyptian appliance prices (linking video descriptions).
- Facebook groups / buyer communities that allow resource links (moderator-approved).
- Arabic finance/installment explainer blogs (بنوك-مصر comparisons) embedding the calculator.
- Price-deal aggregator sites (site-level integrations or attribution links back to article pages).

### C. Earned media (start Q3)
- Arabic tech/finance publications with price-reporting beats (e.g. typeak: "أسعار الأجهزة مصر 2025" report pitching). Verify beats + named journalists before outreach; no fabricated contact data.

## 5. Q1–Q4 tactical plan

**Q1**
- Shade inbound anchors: ensure nav/footer/canonical use the www host everywhere (DONE on-page).
- Submit citations (list A). Add `sameAs` to Organization schema when the first social/GBP profile exists.
- Build + publish the quarterly price-data report asset; open a "share/cite" landing with a stable URL.
- Outreach round 1: 20–30 personalized pitches to Egyptian price/deal + finance sites (batch 1).

**Q2**
- Outreach round 2 (next 30). Publish data-report #2 with a year-over-year number (first real "original research" hook).
- Start 5 partnership conversations (YouTube + deal aggregators). Aim for ≥2 live integrations.
- Refresh citations.

**Q3**
- Scale earned media: pitch the accumulated price dataset + consumer savings angle to Arabic tech/finance press.
- First podcast/expert-quote attempt (Egyptian consumer-tech shows).
- Quarterly toxic-link check; disavow **only** on confirmed spam attack or manual penalty.

**Q4**
- Lost-link recovery pass (needs Ahrefs Lost report — gap in §1 blocks automatic execution).
- Data-report #3 + year-in-review (biggest earned-media push).
- Re-pitch all asset pages; refresh everything; re-measure against §2.

## 6. Outreach templates (personalized, never generic)

Context line MUST reference the recipient's actual recent work and the specific asset being offered. Do not cold-send "Dear [name]".

**Calculator/asset pitch (finance/tech blog):**
> "سمعت/قريت مقالتك عن تقسيط الأجهزة المنزلية (day before date). عندك بالفعل حاسبة قسط؟ بنينا واحدة مفتوحة على أسعار أمازون مصر يومية — سطر الكود معاها على الصفحة مثال live: [article URL]. لو حابب نحط لينك بعنوان ثابت [canonical URL] ولارسمه في تقريرك — تمام."

**Data-report / earned-media pitch:**
> "تقريرك عن أسعار الأجهزة (recent piece) … بنينا بيانات يومية على 2,000+ منتج — عندي رقم حصري عن مدى تقلب أسعار التكييفات في مصر خلال ربع سنة. محتاج رأي/مراجعة قبل النشر [link to report]. تقدري تعتمدي عليه كمصدر."

**Partnership (YouTube/finance site)**
> targeted to their niche; offer readymade embed, attribution to article pages, no money.

## 7. Tracking and measurement plan

Tracking sheet: `offpage-tracking.csv` (this repo). Fields: prospect, type (citation/asset/partnership/earned), contact, value-proposition sent, first outreach date, reply (y/n), placement URL, DR (from tool), anchor text, domain added date, status.

Monthly cadence: fill sheet from GSC (query/referring-domain) + chosen tool. Measure vs §2 metrics. Quarterly: re-run §1 audit once the tool export exists; disavow only per the failure-patterns rule.

## Data-availability note
Anything marked ❌ in §1 requires external tool/sign-in access not available in this environment. Deliverable is complete with those gaps stated; no backlink numbers were assumed or invented. The buildable part of this plan (assets, citations checklist, outreach copy, tracking scaffold) is fully actionable today.