[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-mcp-checkout?style=flat-square)](https://packagist.org/packages/angeo/module-mcp-checkout)
[![License](https://img.shields.io/packagist/l/angeo/module-mcp-checkout?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%20--%208.4-777bb4?style=flat-square)](composer.json)
[![Magento](https://img.shields.io/badge/Magento-2.4.6%2B-f26322?style=flat-square)](https://github.com/magento/magento2)

# MCP Checkout for Magento 2 — AI Agent Cart & Order Tools

**Six MCP tools that let an AI agent go from a product search to a placed order in a real Magento store — with every guardrail enforced server-side, at the order choke point.**

`angeo/module-mcp-checkout` extends [`angeo/module-mcp-server`](https://github.com/angeo-dev/module-mcp-server) v1.0.0 with guest cart and checkout tools, so Claude or any MCP client can complete a full **discovery → cart → checkout** flow against Magento 2 / Adobe Commerce. No browser automation, no scraping, no headless driver that breaks on the next theme deploy.

> **Watch it happen:** [Claude places a real order in Magento 2 via MCP](https://angeo.dev/ai-agent-checkout-in-magento-2-claude-places-a-real-order-via-mcp/) — full tool sequence and a real `order_number` from a live store.

📖 Module page: [angeo.dev/modules/mcp-checkout](https://angeo.dev/modules/mcp-checkout/) · End-user docs: [angeo.dev/docs/mcp-checkout](https://angeo.dev/docs/mcp-checkout/)

> ⚠️ **This module lets an AI agent place real orders.** It is disabled by default. Read the [security model](#security-model) and set every limit deliberately before enabling it on a store with real payment methods.

## Contents

- [Tools](#tools)
- [How it plugs into module-mcp-server](#how-it-plugs-into-module-mcp-server)
- [Security model](#security-model)
- [Requirements](#requirements)
- [Installation](#installation)
- [Store prerequisites](#store-prerequisites-easy-to-miss)
- [Connecting Claude](#connecting-claude)
- [Design decisions](#design-decisions)
- [FAQ](#faq)
- [Changelog](CHANGELOG.md)
- [The Angeo agentic stack](#the-angeo-agentic-stack)

## Tools

| Tool | Purpose |
| --- | --- |
| `create_cart` | Create an empty guest cart, returns `cart_id` |
| `add_to_cart` | Add a simple product by SKU; enforces qty / item-count / order-total caps |
| `get_cart` | Line items and totals |
| `get_shipping_methods` | Estimate shipping for a destination; enforces country whitelist |
| `set_shipping_information` | Address + email + shipping method; returns allowed payment methods and final totals |
| `place_order` | Places the order; pre-flights every cap for a clear agent-facing message, then the guardrail plugin re-verifies and reserves the rate-limit slot; tags and audit-logs the order |

Canonical agent flow:

```
search_products → add_to_cart → get_shipping_methods
  → set_shipping_information → user confirms total → place_order
```

The SKU returned by the server's `search_products` / `get_product` feeds straight into `add_to_cart`.

## How it plugs into module-mcp-server

Tools implement `Angeo\McpServer\Api\ToolInterface` (verified against v1.0.0):

- **Availability gating** — `isAvailable()` returns the admin enable flag, so when checkout is disabled the tools vanish from `tools/list` entirely. They never appear to agents, rather than failing at call time.
- **Error model** — business errors are thrown as `\InvalidArgumentException`, which the server maps to an `isError` **tool result** (structured, agent-readable) rather than a protocol error, exactly as the server contract requires. Internal errors are logged in full and returned as a generic actionable message; no paths or SQL ever reach the agent.
- **Registration** — `etc/di.xml` injects all six tools into `Angeo\McpServer\Model\Tool\ToolRegistry` via its `tools` array argument.

No changes to `module-mcp-server` are required.

## Security model

Disabled by default. A limit written into a system prompt is advisory; a limit enforced in PHP before the order is placed is not.

### Where enforcement lives

Since **1.1.0** the guardrails are applied in `Plugin\AgentOrderGuardrails`, on `Magento\Quote\Model\CartManagementInterface::placeOrder()` — the single point every route to a placed order passes through. The checks inside the MCP tools remain as a fast pre-flight so the agent gets a specific, recoverable message, but they are not the boundary.

This matters more than it sounds. The masked `cart_id` handed to an agent is **also a valid credential for Magento's own anonymous guest-cart REST endpoints**:

```
POST /rest/V1/guest-carts/{cartId}/items
POST /rest/V1/guest-carts/{cartId}/shipping-information
PUT  /rest/V1/guest-carts/{cartId}/order
```

In 1.0.x every cap lived in the tool layer, so anything holding a `cart_id` — a compromised agent, a leaked transcript, a verbose log — could add 500 units, ship anywhere, pick any enabled payment method and place the order outside every limit and outside the audit log. Carts created via `create_cart` are now flagged in `angeo_mcp_agent_quote`, and the plugin applies the full guardrail set to them wherever the order request originates. Carts that were not created by an agent are untouched.

**Treat `cart_id` as a bearer token.** The caps are what bound the damage if it leaks; they are no longer avoidable by changing entry point.

### The guardrails

- **Guest checkout only** — enforced, not assumed. Carts carrying a `customer_id` are rejected, as are carts belonging to another store view and carts already ordered. The blast radius of a compromised agent session is one guest cart.
- **Payment method whitelist**, default `checkmo` (offline). Online methods must be explicitly allowed. Verified at `place_order` and again at the choke point.
- **Order total cap** (default 100, **base currency**) — enforced at `add_to_cart` with rollback of the offending item, and re-verified against final totals including shipping and tax when the order is placed.
- **Qty / cart-size caps** (default 5 per item, 10 line items). The quantity cap applies to the *resulting* quantity, so repeated additions of the same SKU cannot stack past it, and both are re-verified at order time.
- **Country whitelist** for shipping (default `NL`), checked at estimation, at address save, and again at order time. Independent of Magento's own allowed-countries setting — both must permit the destination.
- **Rate limits** — global orders/hour (default 10) and per-IP orders/hour (default 10), counted per store. Slots are reserved before the order and released if it fails, so concurrent calls cannot overshoot the cap and a failing payment method cannot drain the budget. Backed by a DB table (`angeo_mcp_order_log`), `open_basedir`-safe, doubling as an audit trail. *Important:* the server already rate-limits requests per client key (`REMOTE_ADDR|sha256(token)`), and cloud MCP clients like Claude connect from a small pool of provider egress IPs — so the **global** cap is the real safety valve; the per-IP cap is a best-effort secondary brake, and behind a proxy it is only as trustworthy as your forwarded-header configuration.
- **Order tagging** — every agent order gets a status-history comment identifying it as agent-placed. The client IP is kept in `angeo_mcp_order_log` under a seven-day retention rather than in the order history, which is retained indefinitely.
- **Demo hygiene cron** — **off by default.** When enabled it cancels unpaid agent orders older than 24h. Offline methods such as `checkmo` leave legitimate orders in exactly that state, so enable it on demo and staging only.

### Known limits of the model

- Guardrails bind to carts created through `create_cart`. A cart created by other means and then driven through the MCP tools is subject to the in-tool checks but is not flagged for the plugin.
- The per-IP cap is advisory in any deployment behind a CDN or load balancer. Rely on the global cap.
- `module-mcp-server` owns bearer-token validation and per-client-key rate limiting. This module's fail-safe depends on `angeo_mcp/general/require_token`.

Found a vulnerability? See [SECURITY.md](SECURITY.md) — please do not open a public issue.

## Requirements

- Magento Open Source / Adobe Commerce **2.4.6+**
- **PHP 8.1 – 8.4** (matches `module-mcp-server`)
- [`angeo/module-mcp-server`](https://github.com/angeo-dev/module-mcp-server) **^1.0**

## Installation

```bash
composer require angeo/module-mcp-checkout
bin/magento module:enable Angeo_McpCheckout
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Configure and enable at **Stores → Configuration → Angeo → MCP Checkout (AI Agents)**. Review every limit before enabling on a store with real payment methods.

### Store prerequisites (easy to miss)

1. **`angeo/module-mcp-server` enabled** (`angeo_mcp/general/enabled = 1`) and reachable over HTTPS.
2. **Guest checkout on** — Stores → Configuration → Sales → Checkout → *Allow Guest Checkout = Yes*. This module is guest-only.
3. **An offline payment method enabled** matching the whitelist — Sales → Payment Methods → *Check / Money Order* (`checkmo`). If none is enabled, `set_shipping_information` reports "no payment methods".
4. **A shipping method + carrier enabled** (e.g. Flat Rate) that serves your allowed countries.
5. **Cron running**, for the cleanup job.
6. Only **simple, salable products** for the demo catalog — configurable and bundle products need option payloads this module does not send. A deliberate v1.0 scope limit.

## Connecting Claude

`module-mcp-server` exposes `POST /mcp` (Streamable HTTP, single request/response — no SSE, which keeps Varnish and shared-hosting compatibility trivial). Two connection paths:

**Public demo (no auth)** — leave `angeo_mcp/general/require_token = No`. In Claude: Settings → Connectors → Add custom connector → URL `https://demo.angeo.dev/mcp` → Add, then enable it per conversation via the "+" menu. This is the frictionless "try it yourself" path for the demo store.

**Authenticated (recommended for real stores)** — set `require_token = Yes`. Create a Magento Integration (System → Extensions → Integrations) granting only the `Angeo_McpServer::agent_access` resource, activate it, and use its **Access Token** as a Bearer token. Revoking the integration instantly cuts agent access.

Note: the claude.ai custom-connector UI negotiates OAuth, not static bearer tokens. For static-token testing use the MCP Inspector or Claude Code:

```bash
claude mcp add --transport http my-store https://your-store.example/mcp \
  -H "Authorization: Bearer <token>"
```

For current connector steps see <https://support.claude.com>.

**Deployment note:** the server sets `Cache-Control: no-store` on `/mcp`, but add a Varnish VCL bypass for the route — full-page cache in front of `/mcp` is the number one Magento-specific pitfall.

Example prompt:

> "I need a gift — a ceramic vase under €60, neutral colours, shipped to the Netherlands. Find options, compare them, and place the order to [address]."

## Design decisions

- **Magento service contracts, not HTTP self-calls** — `GuestCartManagementInterface`, `GuestCartItemRepositoryInterface`, `GuestShipmentEstimationInterface`, `GuestShippingInformationManagementInterface`, `GuestPaymentInformationManagementInterface`. Same code paths as core REST, no extra hop, compatible with checkout extensions built on those contracts.
- **Compact tool outputs** with a `next_step` hint on each — verbose payloads measurably degrade agent decisions and waste context.
- **Fail-closed everywhere** — missing config, an empty payment whitelist, or a breached cap blocks the order rather than falling back to something permissive.

## FAQ

**Can an AI agent really place a live order?**
Yes — that is what the six tools do, and there is a [recorded demo with a real order number](https://angeo.dev/ai-agent-checkout-in-magento-2-claude-places-a-real-order-via-mcp/). Which is precisely why every guardrail is enforced in Magento rather than requested in a prompt, and why the module ships disabled.

**What stops an agent buying a thousand units?**
Server-side caps: order total, quantity per item, line-item count, and orders per hour (global and per IP). All of them are re-verified inside `CartManagementInterface::placeOrder()`, not only when the item is added — so they hold even if the order request never goes through the `place_order` tool. The quantity cap applies to the resulting line quantity, so repeated small additions cannot stack past it.

**Is this the same as ACP Instant Checkout?**
No. ACP Instant Checkout is OpenAI's purchase flow inside ChatGPT and requires merchant approval — see [`module-openai-instant-checkout`](https://github.com/angeo-dev/module-openai-instant-checkout). MCP checkout is an open tool surface any MCP client can drive, with no approval process. Different clients, different gatekeeping, both worth having.

**Why guest checkout only?**
So there are no stored credentials for an agent session to compromise, and no customer account to take over. It also keeps the module out of any flow that touches saved payment data.

**Can I use it with online payment methods?**
Technically yes — the whitelist is configurable. Do it only after you have exercised the flow on staging and reviewed the caps, and review it against your own PCI scope. The default is offline (`checkmo`) for a reason.

**Does it support configurable or bundle products?**
Not in v1.0. Those need option payloads the module does not send yet. Simple, salable products only.

**Do I need this to appear in ChatGPT or Claude?**
No. Discovery and transaction are separate problems. This closes the transaction loop once agents can already find you — start with an [AEO audit](https://angeo.dev/ai-magento-audit/) to see whether they can.

## The Angeo agentic stack

MIT-licensed, no paid tier, no licence key.

| Layer | Module |
| --- | --- |
| Crawler access | [`module-robots-txt-aeo`](https://github.com/angeo-dev/module-robots-txt-aeo) |
| Discovery files | [`module-llms-txt`](https://github.com/angeo-dev/module-llms-txt) |
| Structured data | [`module-rich-data`](https://github.com/angeo-dev/module-rich-data) |
| ChatGPT Shopping feed | [`module-openai-product-feed`](https://github.com/angeo-dev/module-openai-product-feed) |
| Live agent access | [`module-mcp-server`](https://github.com/angeo-dev/module-mcp-server) |
| **Agent checkout** | **`module-mcp-checkout`** ← you are here |
| UCP profile | [`module-ucp`](https://github.com/angeo-dev/module-ucp) · [`module-ucp-catalog`](https://github.com/angeo-dev/module-ucp-catalog) |
| Measurement | [`module-aeo-audit`](https://github.com/angeo-dev/module-aeo-audit) |

All thirteen modules: <https://angeo.dev/modules/>

## Support

Issues and feature requests: [GitHub Issues](https://github.com/angeo-dev/module-mcp-checkout/issues).
Security reports: [SECURITY.md](SECURITY.md) — not a public issue.
Implementation and audits: <info@angeo.dev>

## License

MIT — see [LICENSE](LICENSE).
