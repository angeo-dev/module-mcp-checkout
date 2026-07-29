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
 * Produces the compact cart representation returned by every cart tool.
 * Kept deliberately small: verbose payloads degrade agent decision quality
 * and burn context tokens for nothing.
 */
class CartFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function format(CartInterface $quote): array
    {
        /** @var Quote $quote */
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'item_id' => (int)$item->getItemId(),
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (float)$item->getQty(),
                'price' => round((float)$item->getPrice(), 2),
                'row_total' => round((float)$item->getRowTotal(), 2),
            ];
        }

        return [
            'items' => $items,
            'items_count' => count($items),
            'currency' => (string)$quote->getQuoteCurrencyCode(),
            'subtotal' => round((float)$quote->getSubtotal(), 2),
            'grand_total' => round((float)$quote->getGrandTotal(), 2),
        ];
    }
}
