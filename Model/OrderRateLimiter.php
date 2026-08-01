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
 * CONCURRENCY (1.1.0): the previous check-then-act pair — COUNT(*) before the
 * order, INSERT after it — failed open whenever calls overlapped, because every
 * concurrent request read the same pre-order count. MCP clients issue tool calls
 * concurrently by default, so this was reachable in normal operation, not only
 * under attack.
 *
 * The slot is now RESERVED before the slow operation and confirmed or released
 * afterwards, and admission is decided by rank rather than by a prior count:
 *
 *   1. INSERT a reservation row (order_id = 0);
 *   2. count in-window rows with entity_id <= the reservation's own id;
 *   3. that count IS the reservation's position in the queue — if it exceeds the
 *      limit, the reservation is released and the order is refused.
 *
 * Because each request ranks itself against its own auto-increment id, N
 * simultaneous reservations get N distinct ranks and exactly `limit` of them are
 * admitted, with no lock, no gap lock and no deadlock surface. Released
 * reservations are deleted, so they do not consume rank for later callers.
 *
 * DESIGN NOTE: Claude and other cloud MCP clients connect from a small pool of
 * provider egress IPs, NOT from end-user devices. A strict per-IP cap would
 * therefore throttle *all* users of a public deployment behind one bucket. So:
 *   - the GLOBAL orders/hour cap is the primary safety valve;
 *   - the per-IP cap is a secondary abuse brake, defaulted loosely and meant
 *     mainly for direct (non-cloud) callers hitting the endpoint.
 * The per-IP value is also only as trustworthy as the store's proxy
 * configuration — see README, "Rate limits".
 *
 * A plain table (not the cache backend) keeps counting transaction-safe on
 * shared hosting (open_basedir-safe) and doubles as an audit trail plus the
 * cleanup cron source.
 */
class OrderRateLimiter
{
    public const TABLE = 'angeo_mcp_order_log';

    /** Reservation placeholder until the order id is known. */
    private const PENDING = 0;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    /**
     * Advisory pre-check, used by the place_order tool so the agent gets a clear
     * message before payment information is touched.
     *
     * NOT authoritative: it is a plain read and races under concurrency by
     * design. Admission is decided by reserve(), which the guardrail plugin
     * calls on every path into CartManagementInterface::placeOrder().
     *
     * @throws \InvalidArgumentException When a configured limit is already hit.
     */
    public function assertAllowed(string $remoteIp, ?int $storeId = null): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $windowStart = $this->windowStart();

        $globalLimit = $this->config->getOrdersPerHour($storeId);
        if ($globalLimit > 0) {
            $select = $connection->select()
                ->from($table, 'COUNT(*)')
                ->where('created_at >= ?', $windowStart);
            if ($storeId !== null) {
                $select->where('store_id = ?', $storeId);
            }
            if ((int)$connection->fetchOne($select) >= $globalLimit) {
                throw new \InvalidArgumentException($this->globalLimitMessage());
            }
        }

        $ipLimit = $this->config->getOrdersPerHourPerIp($storeId);
        if ($ipLimit > 0) {
            $select = $connection->select()
                ->from($table, 'COUNT(*)')
                ->where('created_at >= ?', $windowStart)
                ->where('remote_ip = ?', $this->normaliseIp($remoteIp));
            if ($storeId !== null) {
                $select->where('store_id = ?', $storeId);
            }
            if ((int)$connection->fetchOne($select) >= $ipLimit) {
                throw new \InvalidArgumentException($this->ipLimitMessage());
            }
        }
    }

    /**
     * Claim a slot before the order is placed. Authoritative.
     *
     * @return int Reservation id, to be passed to confirm() or release().
     * @throws \InvalidArgumentException When a configured limit is hit.
     */
    public function reserve(string $remoteIp, int $storeId): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $remoteIp = $this->normaliseIp($remoteIp);

        $connection->insert($table, [
            'remote_ip' => $remoteIp,
            'store_id' => $storeId,
            'order_id' => self::PENDING,
            'quote_id' => self::PENDING,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $reservationId = (int)$connection->lastInsertId($table);

        $windowStart = $this->windowStart();

        $globalLimit = $this->config->getOrdersPerHour($storeId);
        if ($globalLimit > 0) {
            $rank = (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, 'COUNT(*)')
                    ->where('created_at >= ?', $windowStart)
                    ->where('store_id = ?', $storeId)
                    ->where('entity_id <= ?', $reservationId)
            );
            if ($rank > $globalLimit) {
                $this->release($reservationId);

                throw new \InvalidArgumentException($this->globalLimitMessage());
            }
        }

        $ipLimit = $this->config->getOrdersPerHourPerIp($storeId);
        if ($ipLimit > 0) {
            $rank = (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, 'COUNT(*)')
                    ->where('created_at >= ?', $windowStart)
                    ->where('store_id = ?', $storeId)
                    ->where('remote_ip = ?', $remoteIp)
                    ->where('entity_id <= ?', $reservationId)
            );
            if ($rank > $ipLimit) {
                $this->release($reservationId);

                throw new \InvalidArgumentException($this->ipLimitMessage());
            }
        }

        return $reservationId;
    }

    /**
     * Turn a reservation into an audit row once the order exists.
     * Never allowed to fail the request: the money has already moved.
     */
    public function confirm(int $reservationId, int $orderId, int $quoteId): void
    {
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            ['order_id' => $orderId, 'quote_id' => $quoteId],
            ['entity_id = ?' => $reservationId]
        );
    }

    /** Give the slot back when the order was not placed. */
    public function release(int $reservationId): void
    {
        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName(self::TABLE),
            ['entity_id = ?' => $reservationId, 'order_id = ?' => self::PENDING]
        );
    }

    private function windowStart(): string
    {
        return gmdate('Y-m-d H:i:s', time() - 3600);
    }

    private function normaliseIp(string $remoteIp): string
    {
        return substr($remoteIp, 0, 45);
    }

    private function globalLimitMessage(): string
    {
        return 'The store has reached its hourly limit for agent-placed orders. Please try again later.';
    }

    private function ipLimitMessage(): string
    {
        return 'This connection has reached its hourly order limit. Please try again later.';
    }
}
