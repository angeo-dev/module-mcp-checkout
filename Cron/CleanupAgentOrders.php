<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Cron;

use Angeo\McpCheckout\Model\AgentQuoteRegistry;
use Angeo\McpCheckout\Model\Config;
use Angeo\McpCheckout\Model\OrderRateLimiter;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Demo hygiene: cancels agent-placed orders that were never paid (offline
 * methods stay in "new"/pending) after a configurable age, and prunes the audit
 * log and the agent-quote registry.
 *
 * OFF BY DEFAULT since 1.1.0. Cancellation is destructive and the default
 * allowed payment method (checkmo) leaves legitimate orders sitting in "new"
 * until the merchant marks them paid — so shipping this enabled meant a store
 * taking real bank-transfer orders would have them silently auto-cancelled.
 * Turn it on only for demo and staging deployments.
 *
 * Log pruning runs regardless of the cancellation switch: the rate limiter only
 * looks at a one-hour window and the table is retained for seven days.
 */
class CleanupAgentOrders
{
    private const LOG_RETENTION_DAYS = 7;
    private const QUOTE_REGISTRY_RETENTION_DAYS = 30;

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly AgentQuoteRegistry $agentQuoteRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if ($this->config->isCleanupEnabled()) {
            $this->cancelStaleOrders();
        }

        $this->pruneLogs();
    }

    private function cancelStaleOrders(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(OrderRateLimiter::TABLE);

        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->config->getCleanupOrderAgeHours() * 3600);
        $orderIds = $connection->fetchCol(
            $connection->select()
                ->from($table, 'order_id')
                ->where('created_at < ?', $cutoff)
                ->where('order_id > ?', 0)   // skip unconfirmed reservations
        );

        foreach ($orderIds as $orderId) {
            try {
                $order = $this->orderRepository->get((int)$orderId);
                if (in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true)) {
                    $this->orderManagement->cancel((int)$orderId);
                    $this->logger->info(sprintf(
                        '[Angeo_McpCheckout] Cancelled stale agent order #%s',
                        $order->getIncrementId()
                    ));
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[Angeo_McpCheckout] Cleanup skipped order %d: %s',
                    (int)$orderId,
                    $e->getMessage()
                ));
            }
        }
    }

    private function pruneLogs(): void
    {
        $connection = $this->resource->getConnection();

        // Audit rows. Client IPs live here, so retention is deliberate and short.
        $connection->delete(
            $this->resource->getTableName(OrderRateLimiter::TABLE),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', time() - self::LOG_RETENTION_DAYS * 86400)]
        );

        // Abandoned reservations: a fatal error between reserve() and the
        // release/confirm pair would otherwise hold a slot for the full hour.
        $connection->delete(
            $this->resource->getTableName(OrderRateLimiter::TABLE),
            [
                'order_id = ?' => 0,
                'created_at < ?' => gmdate('Y-m-d H:i:s', time() - 3600),
            ]
        );

        $this->agentQuoteRegistry->pruneOlderThan(self::QUOTE_REGISTRY_RETENTION_DAYS);
    }
}
