<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Cron;

use Angeo\McpCheckout\Model\Config;
use Angeo\McpCheckout\Model\OrderRateLimiter;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Demo hygiene: cancels agent-placed orders that were never paid (offline
 * methods stay in "new"/pending) after a configurable age, and prunes audit
 * log rows older than 7 days. Runs hourly; safe to disable in production
 * deployments that keep real agent orders.
 */
class CleanupAgentOrders
{
    private const LOG_RETENTION_DAYS = 7;

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isCleanupEnabled()) {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(OrderRateLimiter::TABLE);

        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->config->getCleanupOrderAgeHours() * 3600);
        $orderIds = $connection->fetchCol(
            $connection->select()
                ->from($table, 'order_id')
                ->where('created_at < ?', $cutoff)
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

        $connection->delete(
            $table,
            ['created_at < ?' => gmdate('Y-m-d H:i:s', time() - self::LOG_RETENTION_DAYS * 86400)]
        );
    }
}
