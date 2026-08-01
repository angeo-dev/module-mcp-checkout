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
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdMaskFactory;

/**
 * Resolves a guest (masked) cart ID to the underlying quote.
 * Guest checkout only: tools in this module never touch customer carts.
 *
 * HARDENED IN 1.1.0. Resolving on masked_id alone bound the cart to nothing:
 * a cart from another store could be driven through this store's endpoint (and
 * be judged against this store's limits), an already-converted quote stayed
 * readable indefinitely, and the "guest only" promise in this docblock was a
 * comment rather than a check. All three are now enforced.
 */
class CartResolver
{
    public function __construct(
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly CartRepositoryInterface $cartRepository
    ) {
    }

    /**
     * @param int|null $storeId Store the request arrived on. Always pass it;
     *                          null is accepted only for backward compatibility
     *                          with 1.0.x callers outside this module.
     * @param bool $requireActive False for read-only inspection of a cart that
     *                            may already have been ordered.
     * @throws NoSuchEntityException
     * @throws \InvalidArgumentException
     */
    public function getQuoteByMaskedId(
        string $maskedCartId,
        ?int $storeId = null,
        bool $requireActive = true
    ): CartInterface {
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($maskedCartId, 'masked_id');
        $quoteId = (int)$quoteIdMask->getQuoteId();

        if ($quoteId === 0) {
            throw new NoSuchEntityException(__('Cart "%1" was not found. Call create_cart first.', $maskedCartId));
        }

        /** @var Quote $quote */
        $quote = $this->cartRepository->get($quoteId);

        // Guest carts only. A mask row surviving a guest-to-customer merge must
        // not become a way into a customer's quote.
        if ((int)$quote->getCustomerId() > 0) {
            throw new NoSuchEntityException(
                __('Cart "%1" is not a guest cart and cannot be used by agent checkout tools.', $maskedCartId)
            );
        }

        // The cart must belong to the store the MCP request arrived on, or the
        // limits applied would be the wrong store's.
        if ($storeId !== null && (int)$quote->getStoreId() !== $storeId) {
            throw new NoSuchEntityException(
                __('Cart "%1" belongs to a different store view. Create a new cart on this store.', $maskedCartId)
            );
        }

        if ($requireActive && !$quote->getIsActive()) {
            throw new \InvalidArgumentException(sprintf(
                'Cart "%s" has already been ordered and can no longer be changed. Call create_cart to start a new one.',
                $maskedCartId
            ));
        }

        return $quote;
    }
}
