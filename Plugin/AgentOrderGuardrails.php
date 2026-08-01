<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Plugin;

use Angeo\McpCheckout\Model\AgentQuoteRegistry;
use Angeo\McpCheckout\Model\GuardrailValidator;
use Angeo\McpCheckout\Model\OrderRateLimiter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * The authoritative guardrail choke point for agent-created carts.
 *
 * Every route to a placed order — the place_order MCP tool, Magento's anonymous
 * guest-cart REST endpoint, GraphQL, or any third-party code built on the
 * service contracts — funnels through CartManagementInterface::placeOrder(). Enforcing
 * here, rather than only inside the MCP tools, is what makes "an agent, or a
 * malicious MCP client, cannot bypass the limits" a true statement instead of an
 * aspiration: holding a cart_id is no longer enough to escape the caps.
 *
 * Carts not created by an agent are untouched — the plugin returns immediately.
 */
class AgentOrderGuardrails
{
    public function __construct(
        private readonly AgentQuoteRegistry $agentQuoteRegistry,
        private readonly GuardrailValidator $guardrailValidator,
        private readonly OrderRateLimiter $rateLimiter,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RemoteAddress $remoteAddress,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int|string $cartId Quote entity id.
     * @return int Order id.
     * @throws LocalizedException
     */
    public function aroundPlaceOrder(
        CartManagementInterface $subject,
        callable $proceed,
        $cartId,
        ?PaymentInterface $paymentMethod = null
    ) {
        $quoteId = (int)$cartId;

        if (!$this->agentQuoteRegistry->isAgentQuote($quoteId)) {
            return $proceed($cartId, $paymentMethod);
        }

        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (\Throwable $e) {
            // Cannot inspect the cart; let core deal with it rather than
            // masking a genuine "cart not found" behind a guardrail error.
            return $proceed($cartId, $paymentMethod);
        }

        $storeId = (int)$quote->getStoreId();
        $methodCode = $paymentMethod?->getMethod() ?: (string)$quote->getPayment()?->getMethod();
        $remoteIp = (string)($this->remoteAddress->getRemoteAddress() ?: 'unknown');

        try {
            $this->guardrailValidator->validate($quote, $storeId, $methodCode ?: null);
            $reservationId = $this->rateLimiter->reserve($remoteIp, $storeId);
        } catch (\InvalidArgumentException $e) {
            throw new LocalizedException(__($e->getMessage()), $e);
        }

        try {
            $orderId = $proceed($cartId, $paymentMethod);
        } catch (\Throwable $e) {
            // The order was not placed, so the slot must go back — otherwise a
            // failing payment method would drain the hourly budget.
            $this->rateLimiter->release($reservationId);

            throw $e;
        }

        $this->confirmAndTag($reservationId, (int)$orderId, $quoteId);

        return $orderId;
    }

    /**
     * Book-keeping only. The order exists at this point, so nothing in here is
     * ever allowed to turn into a failed response.
     */
    private function confirmAndTag(int $reservationId, int $orderId, int $quoteId): void
    {
        try {
            $this->rateLimiter->confirm($reservationId, $orderId, $quoteId);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[Angeo_McpCheckout] Order %d placed but the audit row could not be confirmed: %s',
                $orderId,
                $e->getMessage()
            ), ['exception' => $e]);
        }

        try {
            $order = $this->orderRepository->get($orderId);
            if ($order instanceof Order) {
                // No client IP here: it is personal data, it is already held in
                // angeo_mcp_order_log under a 7-day retention, and order comments
                // are kept for the life of the order.
                $order->addCommentToStatusHistory(
                    'Order placed by an AI agent via MCP (Angeo_McpCheckout).',
                    false,
                    false
                );
                $this->orderRepository->save($order);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[Angeo_McpCheckout] Could not tag agent order %d: %s',
                $orderId,
                $e->getMessage()
            ));
        }
    }
}
