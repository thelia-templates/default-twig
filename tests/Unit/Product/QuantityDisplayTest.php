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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Product;

use BackOfficeDefaultTwigBundle\Service\Catalog\ProductPricingPresenter;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductPricingSnapshot;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\Service\Product\ReferenceUnitPricePresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Thelia\Domain\Catalog\Quantity\QuantityFormatter;
use Thelia\Domain\Catalog\Quantity\ReferenceUnitPrice;
use Thelia\Domain\Catalog\Quantity\UnitCode;

/**
 * Quantities and reference prices as the back office writes them: the stock of the product
 * list in the unit its combinations are sold in, the price per reference unit as a shelf
 * label reads it.
 */
final class QuantityDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(QuantityFormatter::class)) {
            self::markTestSkipped('The installed core predates the quantity formatter.');
        }
    }

    public function testAStockSoldByMeasureReadsWithItsDecimalsAndUnit(): void
    {
        self::assertSame('1,25 kg', $this->stock(new ProductPricingSnapshot(quantity: 1.25, saleElementCount: 1, quantityDecimals: 2, unitCode: 'kg'), 'fr_FR'));
        self::assertSame('1.25 kg', $this->stock(new ProductPricingSnapshot(quantity: 1.25, saleElementCount: 1, quantityDecimals: 2, unitCode: 'kg'), 'en_US'));
    }

    public function testAStockSoldByThePieceReadsAsAWholeNumber(): void
    {
        self::assertSame('3', $this->stock(new ProductPricingSnapshot(quantity: 3.0, saleElementCount: 2), 'fr_FR'));
    }

    public function testAStockWrittenBeforeTheRulesKeepsTheDecimalsItCarries(): void
    {
        self::assertSame('12,50', $this->stock(new ProductPricingSnapshot(quantity: 12.5, saleElementCount: 1), 'fr_FR'));
    }

    public function testAStockOfCombinationsSoldInDifferentUnitsHasNoUnit(): void
    {
        // The provider gives no unit when the combinations do not share one.
        self::assertSame('4,50', $this->stock(new ProductPricingSnapshot(quantity: 4.5, saleElementCount: 2, quantityDecimals: 2), 'fr_FR'));
    }

    public function testAReferencePriceReadsPerUnitOrPerReferenceQuantity(): void
    {
        $presenter = new ReferenceUnitPricePresenter(new MoneyFormatter(), new QuantityFormatter());

        self::assertMatchesRegularExpression('/^13,32\s€ \/ l$/u', $presenter->format(new ReferenceUnitPrice(13.32, 1.0, UnitCode::LITRE), 'fr_FR', 'EUR'));
        self::assertMatchesRegularExpression('/^1,20\s€ \/ 100 g$/u', $presenter->format(new ReferenceUnitPrice(1.2, 100.0, UnitCode::GRAM), 'fr_FR', 'EUR'));
        self::assertMatchesRegularExpression('/^€2\.50 \/ 0\.5 m²$/u', $presenter->format(new ReferenceUnitPrice(2.5, 0.5, UnitCode::SQUARE_METRE), 'en_US', 'EUR'));
    }

    private function stock(ProductPricingSnapshot $snapshot, string $locale): string
    {
        $presenter = new ProductPricingPresenter(new IdentityTranslator(), new MoneyFormatter(), new QuantityFormatter());

        return strip_tags($presenter->stock($snapshot, $locale));
    }
}
