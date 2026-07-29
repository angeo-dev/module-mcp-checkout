<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model\Tool;

use Angeo\McpCheckout\Model\Config;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Checkout\Api\GuestShippingInformationManagementInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

class SetShippingInformation extends AbstractTool
{
    public function __construct(
        Config $config,
        LoggerInterface $logger,
        private readonly GuestShippingInformationManagementInterface $shippingInformationManagement,
        private readonly ShippingInformationInterfaceFactory $shippingInformationFactory,
        private readonly AddressInterfaceFactory $addressFactory
    ) {
        parent::__construct($config, $logger);
    }

    public function getName(): string
    {
        return 'set_shipping_information';
    }

    public function getDescription(): string
    {
        return 'Set the shipping address, contact details and chosen shipping method on a guest cart. The billing address is set to the same address automatically.'
            . "\n\n"
            . 'USE THIS after the user has given their delivery details and picked a shipping method from get_shipping_methods. Returns the final order totals including shipping, which you should show the user before placing the order.';
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
                'email' => [
                    'type' => 'string',
                    'description' => 'Customer email for order confirmation. Ask the user for it; never invent one.',
                ],
                'firstname' => ['type' => 'string'],
                'lastname' => ['type' => 'string'],
                'street' => [
                    'type' => 'string',
                    'description' => 'Street and house number, e.g. "Keizersgracht 123".',
                ],
                'city' => ['type' => 'string'],
                'postcode' => ['type' => 'string'],
                'country_id' => [
                    'type' => 'string',
                    'description' => 'ISO 3166-1 alpha-2 country code, e.g. "NL".',
                ],
                'region' => [
                    'type' => 'string',
                    'description' => 'State/province/region name. Optional for most EU countries.',
                ],
                'telephone' => [
                    'type' => 'string',
                    'description' => 'Contact phone number. Ask the user for it; never invent one.',
                ],
                'carrier_code' => [
                    'type' => 'string',
                    'description' => 'carrier_code of the method chosen from get_shipping_methods.',
                ],
                'method_code' => [
                    'type' => 'string',
                    'description' => 'method_code of the method chosen from get_shipping_methods.',
                ],
            ],
            'required' => [
                'cart_id', 'email', 'firstname', 'lastname', 'street',
                'city', 'postcode', 'country_id', 'telephone',
                'carrier_code', 'method_code',
            ],
        ];
    }

    protected function doExecute(array $args, StoreInterface $store): array
    {
        $storeId = (int)$store->getId();

        $email = trim((string)$args['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid email address.', $email));
        }

        $countryId = strtoupper(trim((string)$args['country_id']));
        if (!$this->config->isCountryAllowed($countryId, $storeId)) {
            throw new \InvalidArgumentException(sprintf(
                'Shipping to "%s" is not available for agent orders. Allowed countries: %s.',
                $countryId,
                implode(', ', $this->config->getAllowedCountries($storeId))
            ));
        }

        $shippingAddress = $this->buildAddress($args, $email, $countryId);
        $billingAddress = $this->buildAddress($args, $email, $countryId);

        $shippingInformation = $this->shippingInformationFactory->create();
        $shippingInformation->setShippingAddress($shippingAddress);
        $shippingInformation->setBillingAddress($billingAddress);
        $shippingInformation->setShippingCarrierCode((string)$args['carrier_code']);
        $shippingInformation->setShippingMethodCode((string)$args['method_code']);

        $paymentDetails = $this->shippingInformationManagement->saveAddressInformation(
            (string)$args['cart_id'],
            $shippingInformation
        );

        $allowed = $this->config->getAllowedPaymentMethods($storeId);
        $paymentMethods = [];
        foreach ($paymentDetails->getPaymentMethods() as $method) {
            if ($allowed !== [] && !in_array($method->getCode(), $allowed, true)) {
                continue;
            }
            $paymentMethods[] = [
                'code' => $method->getCode(),
                'title' => (string)$method->getTitle(),
            ];
        }

        if ($paymentMethods === []) {
            throw new \InvalidArgumentException(
                'No payment methods are available for agent orders on this store. '
                . 'The store administrator must enable at least one allowed method.'
            );
        }

        $totals = $paymentDetails->getTotals();

        return [
            'payment_methods' => $paymentMethods,
            'totals' => [
                'currency' => (string)$totals->getQuoteCurrencyCode(),
                'subtotal' => round((float)$totals->getSubtotal(), 2),
                'shipping' => round((float)$totals->getShippingInclTax(), 2),
                'tax' => round((float)$totals->getTaxAmount(), 2),
                'grand_total' => round((float)$totals->getGrandTotal(), 2),
            ],
            'next_step' => 'Confirm the final total with the user, then call place_order with one of the payment_methods codes.',
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function buildAddress(array $args, string $email, string $countryId): AddressInterface
    {
        $address = $this->addressFactory->create();
        $address->setFirstname(trim((string)$args['firstname']));
        $address->setLastname(trim((string)$args['lastname']));
        $address->setStreet([trim((string)$args['street'])]);
        $address->setCity(trim((string)$args['city']));
        $address->setPostcode(trim((string)$args['postcode']));
        $address->setCountryId($countryId);
        $address->setTelephone(trim((string)$args['telephone']));
        $address->setEmail($email);
        if (!empty($args['region'])) {
            $address->setRegion(trim((string)$args['region']));
        }

        return $address;
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
            'title'           => 'Set shipping information',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ];
    }
}
