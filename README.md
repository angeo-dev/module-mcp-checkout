# Angeo_McpCheckout

Guest cart and checkout **MCP tools** for Magento 2 / Adobe Commerce.

Extends [`angeo/module-mcp-server`](https://packagist.org/packages/angeo/module-mcp-server) v1.0.0 with six write tools that let an AI agent (Claude or any MCP client) complete a full **discovery → cart → checkout** flow against a real Magento store — with hard, server-side guardrails on every step.

> This is the transactional layer behind the "Watch an AI agent buy from Magento" demo at angeo.dev. The `module-mcp-server` read tools (`search_products`, `get_product`, `list_categories`, `get_store_info`) provide discovery; this module adds the cart and order tools that close the loop.

## Tools

| Tool | Purpose |
|---|---|
| `create_cart` | Create an empty guest cart, returns `cart_id` |
| `add_to_cart` | Add a simple product by SKU; enforces qty / item-count / order-total caps |
| `get_cart` | Line items and totals |
| `get_shipping_methods` | Estimate shipping for a destination; enforces country whitelist |
| `set_shipping_information` | Address + email + shipping method; returns allowed payment methods and final totals |
| `place_order` | Places the order; enforces rate limits, total cap, payment whitelist; tags and audit-logs the order |

Canonical agent flow: `search_products` → `add_to_cart` → `get_shipping_methods` → `set_shipping_information` → *user confirms total* → `place_order`. The SKU returned by the server's `search_products` / `get_product` feeds straight into `add_to_cart`.

## How it plugs into module-mcp-server

Tools implement `Angeo\McpServer\Api\ToolInterface` (verified against v1.0.0):

- **Availability gating**: `isAvailable()` returns the admin enable flag, so when checkout is disabled the tools vanish from `tools/list` entirely — they never appear to agents, rather than failing at call time.
- **Error model**: business errors are thrown as `\InvalidArgumentException`, which the server maps to an `isError` **tool result** (structured, agent-readable) rather than a protocol error — exactly as the server contract requires. Internal errors are logged in full and returned as a generic actionable message; no paths or SQL ever reach the agent.
- **Registration**: `etc/di.xml` injects all six tools into `Angeo\McpServer\Model\Tool\ToolRegistry` via its `tools` array argument.

No changes to `module-mcp-server` are required.

## Security model

Disabled by default; every guardrail is enforced server-side — an agent (or a malicious MCP client) cannot bypass them regardless of prompting:

- **Guest checkout only.** Never touches customer accounts or stored payment data.
- **Payment method whitelist**, default `checkmo` (offline). Online methods must be explicitly allowed.
- **Order total cap** (default 100, store currency) — enforced at `add_to_cart` (with rollback of the offending item) and re-checked at `place_order`.
- **Qty / cart-size caps** (default 5 per item, 10 line items).
- **Rate limits**: global orders/hour (default 10) and per-IP orders/hour (default 10). **Important:** the server already rate-limits requests per client key (`REMOTE_ADDR|sha256(token)`), and cloud MCP clients like Claude connect from a *small pool of provider egress IPs* — so the **global** cap is the real safety valve here; the per-IP cap is a secondary brake for direct callers. Backed by a DB table (`angeo_mcp_order_log`), `open_basedir`-safe, doubling as an audit trail.
- **Country whitelist** for shipping (default `NL`). Note this is independent of Magento's own allowed-countries setting — both must permit the destination.
- **Order tagging**: every agent order gets a status-history comment with the client IP.
- **Demo hygiene cron**: hourly job cancels unpaid agent orders older than 24h (configurable; disable for stores taking real agent orders).

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
2. **Guest checkout on**: Stores → Configuration → Sales → Checkout → *Allow Guest Checkout = Yes*. This module is guest-only.
3. **An offline payment method enabled** matching the whitelist: Sales → Payment Methods → *Check / Money Order* (`checkmo`). If none is enabled, `set_shipping_information` reports "no payment methods".
4. **A shipping method + carrier enabled** (e.g. Flat Rate) that serves your allowed countries.
5. **Cron running** (for the cleanup job).
6. Only **simple, salable products** for the demo catalog — configurable/bundle products need option payloads this module does not send (a deliberate v1.0 scope limit).

## Connecting Claude

`module-mcp-server` exposes `POST /mcp` (Streamable HTTP, single request/response — no SSE, which keeps Varnish/shared-hosting compatibility trivial). Two connection paths:

**Public demo (no auth)** — leave `angeo_mcp/general/require_token = No`. In Claude: Settings → Connectors → Add custom connector → URL `https://demo.angeo.dev/mcp` → Add, then enable it per conversation via the "+" menu. This is the frictionless "Try it yourself" path for the demo page.

**Authenticated (recommended for real stores)** — set `require_token = Yes`. Create a Magento Integration (System → Extensions → Integrations) granting only the `Angeo_McpServer::agent_access` resource, activate it, and use its **Access Token** as a Bearer token. Revoking the integration instantly cuts agent access. Note: the claude.ai custom-connector UI negotiates OAuth, not static bearer tokens; for static-token testing use the MCP Inspector or Claude Code (`claude mcp add --transport http ... -H "Authorization: Bearer <token>"`). For current connector steps see https://support.claude.com.

Deployment note: the server sets `Cache-Control: no-store` on `/mcp`, but add a Varnish VCL bypass for the route — full-page cache in front of `/mcp` is the #1 Magento-specific pitfall.

Example demo prompt:

> "I need a gift — a ceramic vase under €60, neutral colours, shipped to the Netherlands. Find options, compare them, and place the order to [address]."

## Design decisions

- **Magento service contracts, not HTTP self-calls**: `GuestCartManagementInterface`, `GuestCartItemRepositoryInterface`, `GuestShipmentEstimationInterface`, `GuestShippingInformationManagementInterface`, `GuestPaymentInformationManagementInterface`. Same code paths as core REST, no extra hop, compatible with checkout extensions on those contracts.
- **Compact tool outputs** with a `next_step` hint on each — verbose payloads measurably degrade agent decisions and waste context.
- **Fail-closed everywhere**: missing config, empty payment whitelist, or a breached cap block the order rather than falling back to something permissive.

## Requirements

- PHP 8.1–8.4 (matches `module-mcp-server`)
- Magento Open Source / Adobe Commerce 2.4.6+
- `angeo/module-mcp-server` ^1.0

## License

MIT — see [LICENSE](LICENSE).
