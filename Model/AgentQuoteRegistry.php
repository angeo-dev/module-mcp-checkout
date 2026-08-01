<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Records which quotes were created by an AI agent through the MCP tools.
 *
 * WHY THIS EXISTS (1.1.0): the masked cart_id handed to an agent is also a valid
 * credential for Magento's own anonymous guest-cart REST endpoints
 * (POST /V1/guest-carts/{id}/items, PUT /V1/guest-carts/{id}/order, ...). Before
 * 1.1.0 every guardrail lived in the MCP tool layer, so anything holding a
 * cart_id could sidestep all of them with one plain HTTP call.
 *
 * Flagging the quote at create_cart lets the guardrail plugin recognise an agent
 * cart at the CartManagementInterface::placeOrder() choke point, whichever entry point
 * reached it, and apply the same limits there.
 */
class AgentQuoteRegistry
{
    public const TABLE = 'angeo_mcp_agent_quote';

    /** @var array<int, bool> Per-request memo; placeOrder is called once but plugins may stack. */
    private array $memo = [];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function mark(int $quoteId, int $storeId): void
    {
        if ($quoteId <= 0) {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'quote_id' => $quoteId,
                'store_id' => $storeId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['store_id']
        );

        $this->memo[$quoteId] = true;
    }

    public function isAgentQuote(int $quoteId): bool
    {
        if ($quoteId <= 0) {
            return false;
        }
        if (isset($this->memo[$quoteId])) {
            return $this->memo[$quoteId];
        }

        $connection = $this->resource->getConnection();
        $found = (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), 'COUNT(*)')
                ->where('quote_id = ?', $quoteId)
        );

        return $this->memo[$quoteId] = $found > 0;
    }

    /** Housekeeping for the cleanup cron. */
    public function pruneOlderThan(int $days): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }
}
