<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model\Tool;

use Angeo\McpCheckout\Model\CartResolver;
use Angeo\McpCheckout\Model\Config;
use Angeo\McpCheckout\Model\GuardrailValidator;
use Angeo\McpCheckout\Model\OrderRateLimiter;
use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\Data\PaymentInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * ENFORCEMENT MODEL (1.1.0). The checks in this class are a fast, agent-readable
 * pre-flight: they exist so the model gets a specific, recoverable message
 * before payment information is touched. They are NOT the security boundary.
 *
 * The boundary is Plugin\AgentOrderGuardrails, which re-runs the same
 * GuardrailValidator and claims the rate-limit slot inside
 * CartManagementInterface::placeOrder() — the one point every route to an order passes
 * through, including Magento's own anonymous guest-cart REST endpoints. Skipping
 * this tool does not skip the limits.
 */
class PlaceOrder extends AbstractTool
{
    public function __construct(
        Config $config,
        LoggerInterface $logger,
        private readonly GuestPaymentInformationManagementInterface $paymentInformationManagement,
        private readonly PaymentInterfaceFactory $paymentFactory,
        private readonly CartResolver $cartResolver,
        private readonly OrderRateLimiter $rateLimiter,
        private readonly GuardrailValidator $guardrailValidator,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RemoteAddress $remoteAddress
    ) {
        parent::__construct($config, $logger);
    }

    public function getName(): string
    {
        return 'place_order';
    }

    public function getDescription(): string
    {
        return 'Place the order for a fully prepared guest cart (items added, shipping information set).'
            . "\n\n"
            . 'IMPORTANT: this creates a REAL order and cannot be undone. Only call it after you have shown the user the final total and they have explicitly confirmed they want to place the order. Never call it speculatively or to "check" anything.'
            . "\n\n"
            . 'Returns the order number and, where the store uses pay-by-link, a payment link the user completes separately. No card details are ever handled here.';
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
                'payment_method' => [
                    'type' => 'string',
                    'description' => 'Payment method code from set_shipping_information response. '
                        . 'If omitted, the first allowed method is used.',
                ],
            ],
            'required' => ['cart_id'],
        ];
    }

    protected function doExecute(array $args, StoreInterface $store): array
    {
        $storeId = (int)$store->getId();
        $cartId = (string)$args['cart_id'];
        $remoteIp = (string)($this->remoteAddress->getRemoteAddress() ?: 'unknown');

        // Pre-flight 1: rate limits. Advisory read — the slot is actually
        // claimed by the guardrail plugin, atomically, further down the stack.
        $this->rateLimiter->assertAllowed($remoteIp, $storeId);

        $quote = $this->cartResolver->getQuoteByMaskedId($cartId, $storeId);

        // Pre-flight 2: payment method whitelist, resolved before validation so
        // the default-to-first-allowed behaviour stays observable to the agent.
        $allowed = $this->config->getAllowedPaymentMethods($storeId);
        if ($allowed === []) {
            throw new \InvalidArgumentException(
                'No payment methods are allowed for agent orders. The store administrator must configure at least one.'
            );
        }
        $methodCode = trim((string)($args['payment_method'] ?? '')) ?: $allowed[0];

        // Pre-flight 3: total cap against final totals (shipping and tax
        // included), quantity and line-item caps, destination country, and the
        // payment method.
        $this->guardrailValidator->validate($quote, $storeId, $methodCode);

        // Email must have been provided in set_shipping_information.
        $email = (string)($quote->getBillingAddress()?->getEmail()
            ?: $quote->getShippingAddress()?->getEmail());
        if ($email === '') {
            throw new \InvalidArgumentException(
                'No customer email is set on the cart. Call set_shipping_information first.'
            );
        }

        $payment = $this->paymentFactory->create();
        $payment->setMethod($methodCode);

        // Rate-limit reservation, order tagging and the audit row all happen
        // inside Plugin\AgentOrderGuardrails, on the far side of this call.
        $orderId = (int)$this->paymentInformationManagement->savePaymentInformationAndPlaceOrder(
            $cartId,
            $email,
            $payment
        );

        $order = $this->orderRepository->get($orderId);

        $this->logger->info(sprintf(
            '[Angeo_McpCheckout] Agent order placed: #%s, total %s %s',
            $order->getIncrementId(),
            $order->getGrandTotal(),
            $order->getOrderCurrencyCode()
        ));

        return [
            'order_number' => $order->getIncrementId(),
            'status' => $order->getStatus(),
            'grand_total' => round((float)$order->getGrandTotal(), 2),
            'currency' => (string)$order->getOrderCurrencyCode(),
            'message' => sprintf(
                'Order %s has been placed successfully. If the store has order emails configured, '
                . 'a confirmation will be sent to %s.',
                $order->getIncrementId(),
                $email
            ),
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
            'title'           => 'Place order',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'openWorldHint'   => true,
        ];
    }
}
