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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use Thelia\Domain\Catalog\Quantity\QuantityFormatter;
use Thelia\Domain\Catalog\Quantity\ReferenceUnitPrice;

/**
 * Writes a reference unit price the way a shelf label reads it: "13,32 € / l" for one
 * litre, "1,20 € / 100 g" for another reference quantity. The amount is the core's; this
 * only writes it.
 */
final readonly class ReferenceUnitPricePresenter
{
    public function __construct(
        private MoneyFormatter $money,
        private QuantityFormatter $quantities,
    ) {
    }

    public function format(ReferenceUnitPrice $price, string $locale, ?string $currencyCode = null): string
    {
        $amount = $this->money->format($price->price, $locale, $currencyCode);
        $written = rtrim(rtrim(number_format($price->referenceUnitQuantity, 3, '.', ''), '0'), '.');

        if ('1' === $written) {
            return $amount.' / '.$price->unitCode->symbol();
        }

        $decimals = str_contains($written, '.') ? \strlen($written) - strpos($written, '.') - 1 : 0;

        return $amount.' / '.$this->quantities->format($price->referenceUnitQuantity, $decimals, $price->unitCode, $locale);
    }
}
