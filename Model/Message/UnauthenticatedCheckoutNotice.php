<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model\Message;

use Angeo\McpCheckout\Model\Config;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;

/**
 * Admin notice for the one dangerous configuration: checkout tools switched on
 * while the MCP endpoint is unauthenticated.
 *
 * In that state the tools are suppressed (see Config::isEnabled) — this notice
 * explains why, so the merchant does not conclude the module is broken.
 *
 * @since 1.2.0
 */
class UnauthenticatedCheckoutNotice implements MessageInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function getIdentity(): string
    {
        return 'angeo_mcp_checkout_unauthenticated';
    }

    public function isDisplayed(): bool
    {
        return $this->config->isMisconfigured();
    }

    public function getText(): string
    {
        $url = $this->urlBuilder->getUrl(
            'adminhtml/system_config/edit',
            ['section' => 'angeo_mcp']
        );

        return (string) __(
            'Angeo MCP Checkout is enabled, but the MCP server does not require a bearer token. '
            . 'Checkout tools are disabled until you fix this: without authentication, anyone could '
            . 'place orders on your store anonymously. Set "Require Bearer Token" to Yes in '
            . '<a href="%1">MCP Server configuration</a>.',
            $url
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_CRITICAL;
    }
}
