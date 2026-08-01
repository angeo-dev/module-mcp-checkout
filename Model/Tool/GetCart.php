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
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

class GetCart extends AbstractTool
{
    public function __construct(
        Config $config,
        LoggerInterface $logger,
        private readonly CartResolver $cartResolver,
        private readonly CartFormatter $cartFormatter
    ) {
        parent::__construct($config, $logger);
    }

    public function getName(): string
    {
        return 'get_cart';
    }

    public function getDescription(): string
    {
        return 'Get the current contents and totals of a guest cart: line items with SKU, name, quantity and price, plus subtotal and grand total.'
            . "\n\n"
            . 'USE THIS whenever the user asks what is in their cart, and always before moving on to shipping — so you can show them exactly what they are about to buy.';
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
            ],
            'required' => ['cart_id'],
        ];
    }

    protected function doExecute(array $args, StoreInterface $store): array
    {
        // Read-only: an already-ordered cart may still be inspected, but it
        // must belong to this store and must be a guest cart.
        $quote = $this->cartResolver->getQuoteByMaskedId(
            (string)$args['cart_id'],
            (int)$store->getId(),
            false
        );

        return [
            'cart' => $this->cartFormatter->format($quote),
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
            'title'           => 'Get cart contents',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ];
    }
}
