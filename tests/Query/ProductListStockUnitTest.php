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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Service\Catalog\ProductPricingProvider;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The stock column of the product list: the sum of the combinations' stocks, with the most
 * decimals they are sold with, and their unit when they all share one, read by the query
 * that already sums the stocks of the page.
 */
final class ProductListStockUnitTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (!method_exists(\Thelia\Model\ProductSaleElements::class, 'getQuantityDecimals')) {
            self::markTestSkipped('The installed core predates the quantity rules of a combination.');
        }

        $this->factory = $this->createFixtureFactory();
    }

    public function testCombinationsSoldInOneUnitGiveTheirUnitAndDecimals(): void
    {
        $product = $this->productSoldIn('kg', 2, 0.5);
        $this->factory->productSaleElement($product, ['quantity' => 1.25, 'quantityDecimals' => 2, 'quantityStep' => 0.25, 'unitCode' => 'kg']);
        $this->factory->productSaleElement($product, ['quantity' => 2.5, 'quantityDecimals' => 1, 'quantityStep' => 0.5, 'unitCode' => 'kg']);

        $snapshot = (new ProductPricingProvider())->forProducts([(int) $product->getId()])[(int) $product->getId()];

        self::assertSame(4.25, $snapshot->quantity);
        self::assertSame(2, $snapshot->quantityDecimals);
        self::assertSame('kg', $snapshot->unitCode);
        self::assertSame(3, $snapshot->saleElementCount);
    }

    public function testCombinationsSoldInDifferentUnitsGiveNoUnit(): void
    {
        $product = $this->productSoldIn('kg', 2, 1.5);
        $this->factory->productSaleElement($product, ['quantity' => 3]);

        $snapshot = (new ProductPricingProvider())->forProducts([(int) $product->getId()])[(int) $product->getId()];

        self::assertNull($snapshot->unitCode);
        self::assertSame(2, $snapshot->quantityDecimals);
    }

    private function productSoldIn(string $unitCode, int $decimals, float $stock): Product
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());
        $default = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($default);
        $default->setUnitCode($unitCode)->setQuantityDecimals($decimals)->setQuantity($stock)->save($this->getPropelConnection());

        return $product;
    }
}
