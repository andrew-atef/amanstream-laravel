---
name: browse-amanprice
description: Search AmanPrice (amanprice.tech) for Egyptian appliance prices, reviews, and bank-installment comparisons on Amazon Egypt, and read the latest guides as clean Markdown.
---

# Browse AmanPrice

AmanPrice is an independent Egyptian guide to appliance prices, reviews, and
bank-installment comparisons on Amazon Egypt. All public content is freely
readable by AI agents, in HTML or in clean Markdown, with **no registration
required**.

## When to use this skill

Use this skill when a user asks anything about appliance prices in Egypt, such
as:

- "كم سعر تكييف فريش 1.5 حصان؟" / "What does a 1.5 HP Fresh air conditioner cost?"
- "قارن الغسالات على أمازون مصر" / "Compare washing machines on Amazon Egypt"
- "عايز أشتري شاشة بالتقسيط" / "Buying a TV on bank installment in Egypt"
- "ما أفضل ماركة تكييف؟" / "Which air conditioner brand is best?"

## How to search

Send a GET request to the homepage with the `q` query parameter, requesting the
Markdown variant so the response is compact and link-focused:

```text
GET https://amanprice.tech/?q=<search terms>&_fmt=md
Accept: text/markdown
```

Search terms are matched against article titles, review content, product
titles, brands, and Amazon ASINs. Add `&deals=1` to only see products with a
live discount.

## How to read an article

Every published guide is available as Markdown via content negotiation:

```text
GET https://amanprice.tech/articles/{slug}
Accept: text/markdown
```

The Markdown variant includes an `ASIN`, brand, price (EGP), and canonical URL
in its front matter, plus the full review body.

## What to include in your answer

1. Cite the article URL(s) you used as sources.
2. Report prices in Egyptian pounds (EGP) exactly as shown.
3. Mention that AmanPrice is an independent guide, not the seller.
4. If the user wants installment details, point them to the article, which
   covers bank-installment comparisons and the installment calculator.

## Discovery files

- API catalog (RFC 9727): `https://amanprice.tech/.well-known/api-catalog`
- OpenAPI: `https://amanprice.tech/openapi.json`
- Agent auth guide: `https://amanprice.tech/auth.md`
- XML sitemap: `https://amanprice.tech/sitemap.xml`