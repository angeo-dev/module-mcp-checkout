<?php
/**
 * Copyright (c) Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Angeo\McpCheckout\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;

/**
 * Resolves a guest (masked) cart ID to the underlying quote.
 * Guest checkout only: tools in this module never touch customer carts.
 */
class CartResolver
{
    public function __construct(
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly CartRepositoryInterface $cartRepository
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getQuoteByMaskedId(string $maskedCartId): CartInterface
    {
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($maskedCartId, 'masked_id');
        $quoteId = (int)$quoteIdMask->getQuoteId();

        if ($quoteId === 0) {
            throw new NoSuchEntityException(__('Cart "%1" was not found. Call create_cart first.', $maskedCartId));
        }

        return $this->cartRepository->get($quoteId);
    }
}
