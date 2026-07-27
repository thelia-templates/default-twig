<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

/**
 * Prices and stock of one product, as shown on the catalog list.
 *
 * Amounts come from the default sale element in the default currency; the taxed
 * values are computed with the shop default country. `quantity` sums every sale
 * element so a product with combinations reports its whole stock.
 */
final readonly class ProductPricingSnapshot
{
    public function __construct(
        public float $price = 0.0,
        public float $taxedPrice = 0.0,
        public float $promoPrice = 0.0,
        public float $taxedPromoPrice = 0.0,
        public bool $onSale = false,
        public float $quantity = 0.0,
        public int $saleElementCount = 0,
    ) {
    }
}
