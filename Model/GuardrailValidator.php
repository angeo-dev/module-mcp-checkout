<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;

/**
 * The single place every non-rate guardrail is expressed.
 *
 * Called twice on the tool path — once by place_order for a fast, agent-readable
 * refusal, and once by Plugin\AgentOrderGuardrails at the authoritative choke
 * point — and once on any other path that reaches CartManagementInterface::placeOrder().
 * Keeping the rules here means the tool layer and the plugin can never drift.
 *
 * All monetary comparison is done in BASE currency (1.1.0). getGrandTotal() is
 * expressed in the quote's currency, which follows the shopper's currency
 * selection, so comparing it against a bare configured number silently changed
 * the effective cap on multi-currency stores.
 */
class GuardrailValidator
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @param string|null $paymentMethodCode Null skips the payment check (cart is not being ordered yet).
     * @throws \InvalidArgumentException
     */
    public function validate(CartInterface $quote, int $storeId, ?string $paymentMethodCode = null): void
    {
        $this->assertOrderTotal($quote, $storeId);
        $this->assertItemLimits($quote, $storeId);
        $this->assertDestination($quote, $storeId);

        if ($paymentMethodCode !== null) {
            $this->assertPaymentMethod($paymentMethodCode, $storeId);
        }
    }

    public function assertOrderTotal(CartInterface $quote, int $storeId): void
    {
        $maxTotal = $this->config->getMaxOrderTotal($storeId);
        if ($maxTotal <= 0.0) {
            return;
        }

        /** @var Quote $quote */
        $baseTotal = (float)$quote->getBaseGrandTotal();
        if ($baseTotal > $maxTotal) {
            throw new \InvalidArgumentException(sprintf(
                'The order total %s %s exceeds the %s %s limit for agent orders. '
                . 'Remove items or reduce quantities, then try again.',
                number_format($baseTotal, 2),
                $this->baseCurrency($quote),
                number_format($maxTotal, 2),
                $this->baseCurrency($quote)
            ));
        }
    }

    /**
     * Line-item count and per-item quantity, re-verified against the cart as it
     * actually stands. add_to_cart validates the increment for a good error
     * message; this validates the result, which is what the cap is about.
     */
    public function assertItemLimits(CartInterface $quote, int $storeId): void
    {
        /** @var Quote $quote */
        $items = $quote->getAllVisibleItems();

        $maxItems = $this->config->getMaxCartItems($storeId);
        if (count($items) > $maxItems) {
            throw new \InvalidArgumentException(sprintf(
                'The cart contains %d line items; agent orders are limited to %d.',
                count($items),
                $maxItems
            ));
        }

        $maxQty = $this->config->getMaxQtyPerItem($storeId);
        foreach ($items as $item) {
            $qty = (float)$item->getQty();
            if ($qty > $maxQty) {
                throw new \InvalidArgumentException(sprintf(
                    'The cart contains %s × "%s"; agent orders are limited to %d per line item.',
                    rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'),
                    (string)$item->getSku(),
                    $maxQty
                ));
            }
        }
    }

    /**
     * Country whitelist, re-verified at order time. Checking it only at
     * set_shipping_information left it bypassable by any later address change
     * that did not come back through the MCP tool.
     */
    public function assertDestination(CartInterface $quote, int $storeId): void
    {
        /** @var Quote $quote */
        $countryId = (string)($quote->getShippingAddress()?->getCountryId()
            ?: $quote->getBillingAddress()?->getCountryId()
            ?: '');

        if ($countryId === '') {
            return;
        }

        if (!$this->config->isCountryAllowed($countryId, $storeId)) {
            throw new \InvalidArgumentException(sprintf(
                'Shipping to "%s" is not available for agent orders. Allowed countries: %s.',
                strtoupper($countryId),
                implode(', ', $this->config->getAllowedCountries($storeId))
            ));
        }
    }

    public function assertPaymentMethod(string $methodCode, int $storeId): void
    {
        $allowed = $this->config->getAllowedPaymentMethods($storeId);
        if ($allowed === []) {
            throw new \InvalidArgumentException(
                'No payment methods are allowed for agent orders. The store administrator must configure at least one.'
            );
        }

        if (!in_array($methodCode, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Payment method "%s" is not allowed for agent orders. Allowed: %s.',
                $methodCode,
                implode(', ', $allowed)
            ));
        }
    }

    private function baseCurrency(CartInterface $quote): string
    {
        /** @var Quote $quote */
        return (string)($quote->getBaseCurrencyCode() ?: $quote->getQuoteCurrencyCode());
    }
}
