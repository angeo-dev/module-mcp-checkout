<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model\Tool;

use Angeo\McpCheckout\Model\CartFormatter;
use Angeo\McpCheckout\Model\CartResolver;
use Angeo\McpCheckout\Model\Config;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;
use Magento\Quote\Api\GuestCartItemRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

class AddToCart extends AbstractTool
{
    public function __construct(
        Config $config,
        LoggerInterface $logger,
        private readonly GuestCartItemRepositoryInterface $guestCartItemRepository,
        private readonly CartItemInterfaceFactory $cartItemFactory,
        private readonly CartResolver $cartResolver,
        private readonly CartFormatter $cartFormatter
    ) {
        parent::__construct($config, $logger);
    }

    public function getName(): string
    {
        return 'add_to_cart';
    }

    public function getDescription(): string
    {
        return 'Add a product to an existing guest cart by SKU, using the sku field from search_products or get_product.'
            . "\n\n"
            . 'USE THIS when the user wants to buy something or add it to their cart. For configurable products (clothing with sizes and colours), first call get_product to see the variants, then add the specific variant SKU the user chose — if they have not chosen yet, ask which size and colour before calling this.'
            . "\n\n"
            . 'Returns the updated cart with line items and totals. Fails with a clear message if the SKU does not exist, is out of stock, or if adding it would exceed the store\'s order limits.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cart_id' => [
                    'type' => 'string',
                    'description' => 'Cart ID returned by create_cart.',
                ],
                'sku' => [
                    'type' => 'string',
                    'description' => 'Exact product SKU from search_products / get_product results.',
                ],
                'qty' => [
                    'type' => 'integer',
                    'description' => 'Quantity to add. Defaults to 1.',
                    'minimum' => 1,
                    'default' => 1,
                ],
            ],
            'required' => ['cart_id', 'sku'],
        ];
    }

    protected function doExecute(array $args, StoreInterface $store): array
    {
        $storeId = (int)$store->getId();
        $cartId = (string)$args['cart_id'];
        $sku = trim((string)$args['sku']);
        $qty = max(1, (int)($args['qty'] ?? 1));

        $maxQty = $this->config->getMaxQtyPerItem($storeId);
        if ($qty > $maxQty) {
            throw new \InvalidArgumentException(sprintf(
                'Quantity %d exceeds the per-item limit of %d for agent orders.',
                $qty,
                $maxQty
            ));
        }

        $quote = $this->cartResolver->getQuoteByMaskedId($cartId);

        $maxItems = $this->config->getMaxCartItems($storeId);
        if (count($quote->getAllVisibleItems()) >= $maxItems) {
            throw new \InvalidArgumentException(sprintf(
                'The cart already contains the maximum of %d line items allowed for agent orders.',
                $maxItems
            ));
        }

        $cartItem = $this->cartItemFactory->create();
        $cartItem->setQuoteId($cartId);
        $cartItem->setSku($sku);
        $cartItem->setQty($qty);

        $savedItem = $this->guestCartItemRepository->save($cartItem);

        // Re-load with fresh totals and enforce the order-total cap. If the cap
        // is breached, roll the add back so the cart never enters a state that
        // place_order would reject anyway.
        $quote = $this->cartResolver->getQuoteByMaskedId($cartId);
        $maxTotal = $this->config->getMaxOrderTotal($storeId);
        if ($maxTotal > 0 && (float)$quote->getGrandTotal() > $maxTotal) {
            $this->guestCartItemRepository->deleteById($cartId, (int)$savedItem->getItemId());

            throw new \InvalidArgumentException(sprintf(
                'Adding "%s" (qty %d) would push the order total above the %s %s limit for agent '
                . 'orders. The item was not added. Choose a cheaper alternative or reduce quantities.',
                $sku,
                $qty,
                number_format($maxTotal, 2),
                (string)$quote->getQuoteCurrencyCode()
            ));
        }

        return [
            'added' => ['sku' => $sku, 'qty' => $qty],
            'cart' => $this->cartFormatter->format($quote),
            'next_step' => 'Add more items, or call get_shipping_methods when the cart is complete.',
        ];
    }

    /**
     * MCP behavioural hints. These MUST match actual behaviour — Anthropic's
     * Software Directory Policy requires descriptions and hints to reflect what
     * the tool really does, and clients use destructiveHint to decide whether to
     * ask the user for confirmation.
     */
    public function getAnnotations(): array
    {
        return [
            'title'           => 'Add item to cart',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ];
    }
}
