# Changelog

All notable changes to `angeo/module-mcp-checkout`.
This project follows [Semantic Versioning](https://semver.org/).

## [1.1.0] — 2026-08-01

Security-focused release. **Upgrading is recommended for every 1.0.x
installation**, in particular any deployment relying on the hourly order caps.

### Security

- **Rate limits no longer fail open under concurrent calls.**
  `OrderRateLimiter` counted orders before the order was placed and inserted the
  audit row afterwards, so overlapping requests all read the same pre-order count
  and all passed. With a cap of 5, ten concurrent `place_order` calls placed ten
  orders. MCP clients issue tool calls concurrently by default, so this was
  reachable in normal operation. Slots are now reserved *before* the order and
  admission is decided by rank against the reservation's own row id, so N
  simultaneous requests get N distinct ranks and exactly `limit` are admitted.
  Reservations are released when the order fails, so a declining payment method
  no longer drains the hourly budget — and failed attempts now consume a slot for
  the duration of the attempt instead of being free.

- **Guardrails moved to the order choke point.** All limits previously lived in
  the MCP tool layer only. The masked `cart_id` handed to an agent is equally
  valid against Magento's own anonymous guest-cart REST endpoints
  (`POST /V1/guest-carts/{id}/items`, `PUT /V1/guest-carts/{id}/order`, …), which
  knew nothing about them — so anything holding a `cart_id` could add 500 units,
  ship to an excluded country, pick an excluded payment method and place the
  order outside every cap and the audit log. Carts created through `create_cart`
  are now recorded in `angeo_mcp_agent_quote`, and `Plugin\AgentOrderGuardrails`
  enforces the full guardrail set plus the rate limit inside
  `CartManagementInterface::placeOrder()`, whichever route reaches it. Carts not created
  by an agent are untouched.

- **Per-item quantity cap can no longer be stacked.** `add_to_cart` validated the
  requested increment, but Magento merges same-SKU additions into one line item
  and sums the quantity — so ten calls of `qty=5` against a cap of 5 produced a
  line item of 50. The cap now applies to the resulting quantity, and quantity
  and line-item counts are re-verified at order time.

- **Cart resolution is bound.** `CartResolver` resolved on `masked_id` alone. It
  now rejects carts belonging to another store view (which would otherwise be
  judged against the wrong store's limits), carts that already carry a
  `customer_id` (the "guest checkout only" promise was a comment, not a check),
  and — for mutating tools — carts that have already been ordered.

- **Country whitelist re-verified at `place_order`.** It was checked only in
  `get_shipping_methods` and `set_shipping_information`, so any later address
  change that did not come back through the MCP tool bypassed it.

- **Order total cap is currency-correct.** The cap was compared against
  `getGrandTotal()`, which is expressed in the quote currency and follows the
  shopper's currency selection — on a multi-currency store the effective cap
  moved with it. It is now compared against `getBaseGrandTotal()` in the store's
  base currency.

- **Rate-limit counters are per store.** `angeo_mcp_order_log` gained `store_id`,
  and both caps now count within the store. Previously the limits were configured
  per store but counted globally, so one store view consumed another's budget.

- **Client IP removed from order status history.** It is personal data and order
  comments are retained for the life of the order; the IP remains in
  `angeo_mcp_order_log` under the existing seven-day retention. The
  "placed by an AI agent via MCP" tag is unchanged.

### Changed

- **The demo cleanup cron ships disabled** (`angeo_mcp_checkout/cleanup/enabled`
  now defaults to `0`). It cancels orders in `new` / `pending_payment`, and the
  default allowed method `checkmo` leaves legitimate orders in exactly that state
  until the merchant marks them paid — so the previous default silently cancelled
  real bank-transfer orders 24 hours after they were placed. Log pruning still
  runs regardless of the switch, and now also clears reservations abandoned by a
  fatal error.
- `Config::isCleanupEnabled()` and `getCleanupOrderAgeHours()` are store-scoped,
  consistent with every other getter.
- The item rollback in `add_to_cart` logs when it cannot undo an over-cap
  addition instead of failing silently.
- The audit row is written by the guardrail plugin and can no longer turn a
  successfully placed order into an error response.

### Added

- `Model\GuardrailValidator` — one place where every non-rate guardrail is
  expressed, shared by the tool layer and the plugin so they cannot drift.
- `Model\AgentQuoteRegistry` and the `angeo_mcp_agent_quote` table.
- `Plugin\AgentOrderGuardrails`.
- `OrderRateLimiter::reserve()`, `confirm()`, `release()`.

### Internal API changes

- `CartResolver::getQuoteByMaskedId()` takes two new optional arguments,
  `?int $storeId` and `bool $requireActive`. Existing calls keep working, but
  omitting `$storeId` skips the store binding — pass it.
- `PlaceOrder`, `AddToCart`, `CreateCart` and `SetShippingInformation`
  constructors take additional dependencies. If you have `preference` or
  constructor-argument overrides for these classes, update them and run
  `bin/magento setup:di:compile`.
- `OrderRateLimiter::record()` was removed; use `reserve()` / `confirm()`.
- `assertAllowed()` remains, now documented as an advisory pre-check rather than
  the enforcement point.

### Upgrade notes

```bash
composer require angeo/module-mcp-checkout:^1.1
bin/magento setup:upgrade          # adds store_id + angeo_mcp_agent_quote
bin/magento setup:di:compile
bin/magento cache:flush
```

Carts created before the upgrade are not in `angeo_mcp_agent_quote` and will not
be recognised by the plugin — they remain covered by the in-tool checks only.
They expire with normal quote lifetime; no action is needed.

If you had deliberately enabled the cleanup cron, re-enable it after upgrading:
**Stores → Configuration → Angeo → MCP Checkout → Demo Cleanup**.

## [1.0.1]

- Initial public release: six MCP tools (`create_cart`, `add_to_cart`,
  `get_cart`, `get_shipping_methods`, `set_shipping_information`, `place_order`),
  server-side caps, payment and country whitelists, audit log, and the fail-safe
  that hides checkout tools when the MCP endpoint does not require a bearer
  token.
