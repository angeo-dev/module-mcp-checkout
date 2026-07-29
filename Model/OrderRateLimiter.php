<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Database-backed rate limiter for agent-placed orders.
 *
 * DESIGN NOTE (important): Claude and other cloud MCP clients connect from a
 * small pool of provider egress IPs, NOT from end-user devices. A strict
 * per-IP cap would therefore throttle *all* users of a public demo behind one
 * bucket. So:
 *   - the GLOBAL orders/hour cap is the primary safety valve;
 *   - the per-IP cap is a secondary abuse brake, defaulted loosely and meant
 *     mainly for direct (non-cloud) callers hitting the endpoint.
 * Tune both in admin per deployment.
 *
 * A plain table (not the cache backend) keeps counting transaction-safe on
 * shared hosting (open_basedir-safe) and doubles as an audit trail plus the
 * cleanup cron source.
 */
class OrderRateLimiter
{
    public const TABLE = 'angeo_mcp_order_log';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    /**
     * @throws \InvalidArgumentException When a configured limit is hit.
     */
    public function assertAllowed(string $remoteIp, ?int $storeId = null): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $oneHourAgo = gmdate('Y-m-d H:i:s', time() - 3600);

        $globalLimit = $this->config->getOrdersPerHour($storeId);
        if ($globalLimit > 0) {
            $globalCount = (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, 'COUNT(*)')
                    ->where('created_at >= ?', $oneHourAgo)
            );
            if ($globalCount >= $globalLimit) {
                throw new \InvalidArgumentException(
                    'The store has reached its hourly limit for agent-placed orders. Please try again later.'
                );
            }
        }

        $ipLimit = $this->config->getOrdersPerHourPerIp($storeId);
        if ($ipLimit > 0) {
            $ipCount = (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, 'COUNT(*)')
                    ->where('created_at >= ?', $oneHourAgo)
                    ->where('remote_ip = ?', substr($remoteIp, 0, 45))
            );
            if ($ipCount >= $ipLimit) {
                throw new \InvalidArgumentException(
                    'This connection has reached its hourly order limit. Please try again later.'
                );
            }
        }
    }

    public function record(string $remoteIp, int $orderId, int $quoteId): void
    {
        $connection = $this->resource->getConnection();
        $connection->insert(
            $this->resource->getTableName(self::TABLE),
            [
                'remote_ip' => substr($remoteIp, 0, 45),
                'order_id' => $orderId,
                'quote_id' => $quoteId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }
}
