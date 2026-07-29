<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model\Tool;

use Angeo\McpCheckout\Model\Config;
use Angeo\McpServer\Api\ToolAnnotationsInterface;
use Angeo\McpServer\Api\ToolInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Template for all checkout tools, implementing the Angeo_McpServer v1.0.0
 * tool contract.
 *
 * Error model (per Angeo\McpServer\Api\ToolInterface):
 *  - business errors  -> \InvalidArgumentException, which McpServer maps to an
 *    isError TOOL RESULT (agents read the message and recover);
 *  - internal errors  -> logged with full detail, re-thrown as a generic
 *    \InvalidArgumentException so the agent gets actionable text and the
 *    protocol layer never leaks paths/SQL.
 *
 * Availability: gated per store via isAvailable(), so when the admin toggle
 * is off these tools disappear from tools/list entirely instead of failing
 * at call time.
 */
abstract class AbstractTool implements ToolInterface, ToolAnnotationsInterface
{
    public function __construct(
        protected readonly Config $config,
        protected readonly LoggerInterface $logger
    ) {
    }

    public function isAvailable(StoreInterface $store): bool
    {
        return $this->config->isEnabled((int)$store->getId());
    }

    final public function execute(array $arguments, StoreInterface $store): array
    {
        // Defence in depth: ToolRegistry::get() already filters by
        // isAvailable(), but never trust the caller.
        if (!$this->isAvailable($store)) {
            throw new \InvalidArgumentException(
                'Checkout tools are currently disabled by the store administrator.'
            );
        }

        try {
            $this->validateRequired($arguments);

            return $this->doExecute($arguments, $store);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (NoSuchEntityException | LocalizedException $e) {
            // Magento business messages are user-safe; hand them to the agent.
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('[Angeo_McpCheckout] %s failed: %s', $this->getName(), $e->getMessage()),
                ['exception' => $e]
            );

            throw new \InvalidArgumentException(
                'An internal error occurred and the operation was not completed. Retry once; '
                . 'if it fails again, report the issue to the store owner.',
                0,
                $e
            );
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed> JSON-serialisable payload (McpServer wraps
     *                              it into structuredContent + text fallback)
     */
    abstract protected function doExecute(array $args, StoreInterface $store): array;

    /**
     * @param array<string, mixed> $args
     */
    protected function validateRequired(array $args): void
    {
        foreach ($this->getInputSchema()['required'] ?? [] as $field) {
            if (!array_key_exists($field, $args) || $args[$field] === '' || $args[$field] === null) {
                throw new \InvalidArgumentException(sprintf('Missing required argument: "%s".', $field));
            }
        }
    }
}
