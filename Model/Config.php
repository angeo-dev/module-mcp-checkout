<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'angeo_mcp_checkout/general/enabled';
    private const XML_PATH_MAX_ORDER_TOTAL = 'angeo_mcp_checkout/limits/max_order_total';
    private const XML_PATH_MAX_QTY_PER_ITEM = 'angeo_mcp_checkout/limits/max_qty_per_item';
    private const XML_PATH_MAX_CART_ITEMS = 'angeo_mcp_checkout/limits/max_cart_items';
    private const XML_PATH_ORDERS_PER_HOUR = 'angeo_mcp_checkout/limits/orders_per_hour';
    private const XML_PATH_ORDERS_PER_HOUR_PER_IP = 'angeo_mcp_checkout/limits/orders_per_hour_per_ip';
    private const XML_PATH_ALLOWED_PAYMENT_METHODS = 'angeo_mcp_checkout/payment/allowed_methods';
    private const XML_PATH_ALLOWED_COUNTRIES = 'angeo_mcp_checkout/shipping/allowed_countries';
    private const XML_PATH_CLEANUP_ENABLED = 'angeo_mcp_checkout/cleanup/enabled';
    private const XML_PATH_CLEANUP_ORDER_AGE_HOURS = 'angeo_mcp_checkout/cleanup/order_age_hours';

    /**
     * McpServer's token requirement. Read by path rather than injecting
     * McpServer\Model\Config, to keep this class's constructor unchanged.
     */
    private const XML_PATH_SERVER_REQUIRE_TOKEN = 'angeo_mcp/general/require_token';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Checkout tools are available only when they are enabled AND the MCP
     * server requires a bearer token.
     *
     * SECURITY (fail-safe): checkout tools place real orders. With the endpoint
     * unauthenticated, anyone on the internet could place orders anonymously.
     * Rather than trust an administrator to notice this, an unauthenticated
     * server hides the checkout tools entirely — the read-only catalogue tools
     * keep working. See isMisconfigured() for the admin-facing warning.
     *
     * @since 1.2.0 token requirement added
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isCheckoutFlagOn($storeId)
            && $this->isServerTokenRequired($storeId);
    }

    /** The raw "enable checkout tools" flag, ignoring the token requirement. */
    public function isCheckoutFlagOn(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /** Whether Angeo_McpServer requires a bearer token on /mcp. */
    public function isServerTokenRequired(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SERVER_REQUIRE_TOKEN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * True when checkout is switched on but the endpoint is unauthenticated —
     * the dangerous combination. The tools are suppressed; this flag exists so
     * the admin can be told why.
     *
     * @since 1.2.0
     */
    public function isMisconfigured(?int $storeId = null): bool
    {
        return $this->isCheckoutFlagOn($storeId)
            && !$this->isServerTokenRequired($storeId);
    }

    /**
     * Hard cap on quote grand total, in the store's BASE currency. 0 disables
     * the cap.
     *
     * @since 1.1.0 compared against getBaseGrandTotal(). Until 1.0.1 this was
     *        compared against the quote currency total, so on a multi-currency
     *        store the effective cap moved with the shopper's currency choice.
     */
    public function getMaxOrderTotal(?int $storeId = null): float
    {
        return max(0.0, (float)$this->scopeConfig->getValue(
            self::XML_PATH_MAX_ORDER_TOTAL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getMaxQtyPerItem(?int $storeId = null): int
    {
        return max(1, (int)$this->scopeConfig->getValue(
            self::XML_PATH_MAX_QTY_PER_ITEM,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getMaxCartItems(?int $storeId = null): int
    {
        return max(1, (int)$this->scopeConfig->getValue(
            self::XML_PATH_MAX_CART_ITEMS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Global cap across all agents. 0 disables the cap.
     */
    public function getOrdersPerHour(?int $storeId = null): int
    {
        return max(0, (int)$this->scopeConfig->getValue(
            self::XML_PATH_ORDERS_PER_HOUR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Per-remote-IP cap. 0 disables the cap.
     */
    public function getOrdersPerHourPerIp(?int $storeId = null): int
    {
        return max(0, (int)$this->scopeConfig->getValue(
            self::XML_PATH_ORDERS_PER_HOUR_PER_IP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * @return string[] Payment method codes agents are allowed to use.
     */
    public function getAllowedPaymentMethods(?int $storeId = null): array
    {
        $raw = (string)$this->scopeConfig->getValue(
            self::XML_PATH_ALLOWED_PAYMENT_METHODS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @return string[] ISO 3166-1 alpha-2 country codes. Empty array = all allowed.
     */
    public function getAllowedCountries(?int $storeId = null): array
    {
        $raw = (string)$this->scopeConfig->getValue(
            self::XML_PATH_ALLOWED_COUNTRIES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return array_values(array_filter(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            explode(',', $raw)
        )));
    }

    public function isCountryAllowed(string $countryId, ?int $storeId = null): bool
    {
        $allowed = $this->getAllowedCountries($storeId);

        return $allowed === [] || in_array(strtoupper($countryId), $allowed, true);
    }

    public function isCleanupEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CLEANUP_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getCleanupOrderAgeHours(?int $storeId = null): int
    {
        return max(1, (int)$this->scopeConfig->getValue(
            self::XML_PATH_CLEANUP_ORDER_AGE_HOURS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }
}
