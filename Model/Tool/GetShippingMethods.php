<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model\Tool;

use Angeo\McpCheckout\Model\Config;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Api\GuestShipmentEstimationInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

class GetShippingMethods extends AbstractTool
{
    public function __construct(
        Config $config,
        LoggerInterface $logger,
        private readonly GuestShipmentEstimationInterface $shipmentEstimation,
        private readonly AddressInterfaceFactory $addressFactory
    ) {
        parent::__construct($config, $logger);
    }

    public function getName(): string
    {
        return 'get_shipping_methods';
    }

    public function getDescription(): string
    {
        return 'Get the real shipping options and their costs for a cart, given a destination country and postcode.'
            . "\n\n"
            . 'USE THIS once the cart is complete and you know where the user wants it delivered. Returns carrier_code and method_code pairs that must be passed to set_shipping_information. Never guess or invent shipping prices — always quote from this tool.';
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
                'country_id' => [
                    'type' => 'string',
                    'description' => 'Destination country as ISO 3166-1 alpha-2 code, e.g. "NL".',
                ],
                'postcode' => [
                    'type' => 'string',
                    'description' => 'Destination postal code.',
                ],
                'city' => [
                    'type' => 'string',
                    'description' => 'Destination city (optional, improves accuracy for some carriers).',
                ],
                'region' => [
                    'type' => 'string',
                    'description' => 'State/province/region name (optional; required by some countries).',
                ],
            ],
            'required' => ['cart_id', 'country_id', 'postcode'],
        ];
    }

    protected function doExecute(array $args, StoreInterface $store): array
    {
        $storeId = (int)$store->getId();
        $countryId = strtoupper(trim((string)$args['country_id']));

        if (!preg_match('/^[A-Z]{2}$/', $countryId)) {
            throw new \InvalidArgumentException(
                'country_id must be a two-letter ISO 3166-1 alpha-2 code, e.g. "NL".'
            );
        }

        if (!$this->config->isCountryAllowed($countryId, $storeId)) {
            throw new \InvalidArgumentException(sprintf(
                'Shipping to "%s" is not available for agent orders. Allowed countries: %s.',
                $countryId,
                implode(', ', $this->config->getAllowedCountries($storeId))
            ));
        }

        $address = $this->addressFactory->create();
        $address->setCountryId($countryId);
        $address->setPostcode((string)$args['postcode']);
        if (!empty($args['city'])) {
            $address->setCity((string)$args['city']);
        }
        if (!empty($args['region'])) {
            $address->setRegion((string)$args['region']);
        }

        $methods = $this->shipmentEstimation->estimateByExtendedAddress(
            (string)$args['cart_id'],
            $address
        );

        $result = [];
        foreach ($methods as $method) {
            if (!$method->getAvailable()) {
                continue;
            }
            $result[] = [
                'carrier_code' => $method->getCarrierCode(),
                'method_code' => $method->getMethodCode(),
                'title' => trim($method->getCarrierTitle() . ' — ' . $method->getMethodTitle(), ' —'),
                'price' => round((float)$method->getPriceInclTax(), 2),
            ];
        }

        if ($result === []) {
            throw new \InvalidArgumentException(sprintf(
                'No shipping methods are available for %s %s. Verify the postcode or try another destination.',
                $countryId,
                (string)$args['postcode']
            ));
        }

        return [
            'shipping_methods' => $result,
            'next_step' => 'Pick one method and call set_shipping_information with its carrier_code and method_code.',
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
            'title'           => 'Get shipping methods',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ];
    }
}
