# auth.md

AmanStream (amanstream.me) — an independent Egyptian guide to appliance price reviews, comparisons, and bank-installment calculators for Amazon Egypt. All public review content is open, free, and readable by AI agents and crawlers without authentication or API keys.

## Agent registration

Agents that want to identify themselves and obtain credentials use these discovery endpoints:
- OAuth Protected Resource Metadata: https://amanstream.me/.well-known/oauth-protected-resource
- OAuth Authorization Server Metadata: https://amanstream.me/.well-known/oauth-authorization-server
- Registration endpoint: POST https://amanstream.me/agent/register
- Supported identity types: `verified_email`, `anonymous`
- Credential types: OAuth access tokens presented as `Authorization: Bearer <token>`
- Claim URI (prove an identity/email): https://amanstream.me/agent/claim

## Identity signatures

AmanStream signs its outbound bot/agent requests using Web Bot Auth (HTTP Message Signatures). Verify outbound requests with the public JWKS at https://amanstream.me/.well-known/http-message-signatures-directory.

## Public endpoints (no auth required)
- Product reviews and articles: https://amanstream.me/articles/{slug}
- AI specifications: https://amanstream.me/llms.txt
- API catalog: https://amanstream.me/.well-known/api-catalog
- OpenAPI document: https://amanstream.me/openapi.json