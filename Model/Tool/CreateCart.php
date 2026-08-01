<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model\Tool;

use Angeo\McpCheckout\Model\AgentQuoteRegistry;
use Angeo\McpCheckout\Model\CartResolver;
use Angeo\McpCheckout\Model\Config;
use Magento\Quote\Api\GuestCartManagementInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

class CreateCart extends AbstractTool
{
    public function __construct(
        Config $config,
        LoggerInterface $logger,
        private readonly GuestCartManagementInterface $guestCartManagement,
        private readonly CartResolver $cartResolver,
        private readonly AgentQuoteRegistry $agentQuoteRegistry
    ) {
        parent::__construct($config, $logger);
    }

    public function getName(): string
    {
        return 'create_cart';
    }

    public function getDescription(): string
    {
        return 'Create a new, empty guest shopping cart and return a cart_id.'
            . "\n\n"
            . 'USE THIS as the first step of any purchase, as soon as the user signals intent to buy ("add that to my cart", "I\'ll take it", "let\'s buy it"). Every other cart and checkout tool needs the cart_id it returns. Create the cart once and reuse the same cart_id for the whole conversation.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    protected function doExecute(array $args, StoreInterface $store): array
    {
        $cartId = $this->guestCartManagement->createEmptyCart();

        // Flag the quote as agent-created so Plugin\AgentOrderGuardrails
        // recognises it at CartManagementInterface::placeOrder(), whichever entry point
        // gets there. Without this the caps would only bind inside the MCP
        // tools, and the masked cart_id is equally valid against Magento's own
        // anonymous guest-cart endpoints.
        $quote = $this->cartResolver->getQuoteByMaskedId($cartId, (int)$store->getId());
        $this->agentQuoteRegistry->mark((int)$quote->getId(), (int)$store->getId());

        return [
            'cart_id' => $cartId,
            'currency' => (string)$store->getCurrentCurrencyCode(),
            'store_code' => (string)$store->getCode(),
            'next_step' => 'Add products with add_to_cart using this cart_id.',
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
            'title'           => 'Create cart',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ];
    }
}
