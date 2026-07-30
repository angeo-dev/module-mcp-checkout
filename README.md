[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-mcp-checkout?style=flat-square)](https://packagist.org/packages/angeo/module-mcp-checkout)
[![License](https://img.shields.io/packagist/l/angeo/module-mcp-checkout?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%20--%208.4-777bb4?style=flat-square)](composer.json)
[![Magento](https://img.shields.io/badge/Magento-2.4.6%2B-f26322?style=flat-square)](https://github.com/magento/magento2)

# MCP Checkout for Magento 2 — AI Agent Cart & Order Tools

**Six MCP tools that let an AI agent go from a product search to a placed order in a real Magento store — with every guardrail enforced server-side.**

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
- [The Angeo agentic stack](#the-angeo-agentic-stack)

## Tools

| Tool | Purpose |
| --- | --- |
| `create_cart` | Create an empty guest cart, returns `cart_id` |
| `add_to_cart` | Add a simple product by SKU; enforces qty / item-count / order-total caps |
| `get_cart` | Line items and totals |
| `get_shipping_methods` | Estimate shipping for a destination; enforces country whitelist |
| `set_shipping_information` | Address + email + shipping method; returns allowed payment methods and final totals |
| `place_order` | Places the order; enforces rate limits, total cap, payment whitelist; tags and audit-logs the order |

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

Disabled by default. Every guardrail is enforced server-side — an agent, or a malicious MCP client, cannot bypass them regardless of prompting. A limit written into a system prompt is advisory; a limit enforced in PHP before the order is placed is not.

- **Guest checkout only.** Never touches customer accounts or stored payment data. The blast radius of a compromised agent session is one guest cart.
- **Payment method whitelist**, default `checkmo` (offline). Online methods must be explicitly allowed.
- **Order total cap** (default 100, store currency) — enforced at `add_to_cart` with rollback of the offending item, and re-checked at `place_order`.
- **Qty / cart-size caps** (default 5 per item, 10 line items).
- **Rate limits** — global orders/hour (default 10) and per-IP orders/hour (default 10). *Important:* the server already rate-limits requests per client key (`REMOTE_ADDR|sha256(token)`), and cloud MCP clients like Claude connect from a small pool of provider egress IPs — so the **global** cap is the real safety valve here; the per-IP cap is a secondary brake for direct callers. Backed by a DB table (`angeo_mcp_order_log`), `open_basedir`-safe, doubling as an audit trail.
- **Country whitelist** for shipping (default `NL`). Independent of Magento's own allowed-countries setting — both must permit the destination.
- **Order tagging** — every agent order gets a status-history comment with the client IP.
- **Demo hygiene cron** — hourly job cancels unpaid agent orders older than 24h. Configurable; disable it for stores taking real agent orders.

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
Server-side caps: order total, quantity per item, line-item count, and orders per hour (global and per IP). All are re-checked at `place_order`, not only when the item is added.

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
