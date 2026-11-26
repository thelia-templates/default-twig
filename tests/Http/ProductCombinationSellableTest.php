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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use BackOfficeDefaultTwigBundle\Service\Product\CombinationSellableReader;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Catalog\Stock\OutOfStockPolicy;
use Thelia\Domain\Catalog\Stock\ReservationDetailReader;
use Thelia\Domain\Catalog\Stock\StockSettings;
use Thelia\Model\CartItem;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * Under the stock field of a combination, what it may still sell and, in a tooltip, what the carts hold
 * of it. The figures of the whole tab are read in two queries, and the grid gains neither a line nor a column.
 */
final class ProductCombinationSellableTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!class_exists(ReservationDetailReader::class)) {
            self::markTestSkipped('The core of this checkout predates the detail of the stock reservations.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
        $this->reserve(true);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheSellableQuantityIsTheStockLessWhatTheCartsHold(): void
    {
        [$product, $combination] = $this->productWithACombination(10);
        $near = $this->hold($product, $combination, 3, '+ INTERVAL 5 MINUTE');
        $this->hold($product, $combination, 2, '+ INTERVAL 20 MINUTE');

        $crawler = $this->tab($product);

        $icon = $crawler->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]');
        self::assertCount(1, $icon);
        self::assertSame('held', $icon->attr('data-state'), 'What may be sold differs from the stock: the icon says so.');
        self::assertSame('Sellable: 5 (stock 10)', $icon->attr('aria-label'));
        $tooltip = $icon->attr('title');
        self::assertStringContainsString('Held by the carts: 5 in 2 cart(s)', (string) $tooltip);
        self::assertStringContainsString(substr($this->untilOf($near), 0, 16), (string) $tooltip, 'The nearest expiry is the one of the first cart.');
        self::assertStringContainsString('10', $crawler->filter('#combination-'.$combination->getId().'-quantity')->attr('value') ?? '', 'The stock field still edits the physical stock.');
    }

    public function testACombinationThatSellsWithoutLimitSaysSo(): void
    {
        [$product, $combination] = $this->productWithACombination(10);
        $combination->setOutOfStockPolicy(OutOfStockPolicy::Unlimited->value)->save($this->getPropelConnection());
        $this->hold($product, $combination, 4, '+ INTERVAL 5 MINUTE');

        $icon = $this->tab($product)->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]');

        self::assertSame('unlimited', $icon->attr('data-state'));
        self::assertSame('Sellable without limit', $icon->attr('aria-label'));
        self::assertStringContainsString('Sales go on at zero stock.', (string) $icon->attr('title'));
    }

    public function testTheCombinationOfABundleShowsNoSellableFigure(): void
    {
        if (!BundleCore::present()) {
            self::markTestSkipped('The core of this checkout predates the product bundles.');
        }

        [$product, $combination] = $this->productWithACombination(10);
        $product->setIsBundle(1)->save($this->getPropelConnection());

        $crawler = $this->tab($product);

        self::assertCount(0, $crawler->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]'), 'The stock of its components decides: no figure for the bundle.');
        self::assertStringNotContainsString('Sellable', $crawler->text());
    }

    public function testACombinationNoCartHoldsSaysItInTheTooltip(): void
    {
        [$product, $combination] = $this->productWithACombination(7);

        $crawler = $this->tab($product);

        $icon = $crawler->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]');
        self::assertSame('sync', $icon->attr('data-state'), 'The same as the stock: the icon stays grey.');
        self::assertSame('Sellable: 7, like the stock', $icon->attr('aria-label'));
        self::assertStringContainsString('No cart holds any unit', (string) $icon->attr('title'));
    }

    public function testTheIconIsAtTheEndOfTheStockLineAndTheGridGainsNoColumn(): void
    {
        [$product, $combination] = $this->productWithACombination(10);
        $other = $this->factory->productSaleElement($product, ['quantity' => 4]);
        $this->factory->attributeCombination($other, $this->factory->attributeAv($this->factory->attribute(['title' => 'Color']), ['title' => 'Red']));

        $grid = $this->tab($product)->filter('table[data-testid="combinations-table"]');

        $columns = array_sum($grid->filter('thead > tr > th')->each(static fn (Crawler $cell): int => (int) ($cell->attr('colspan') ?? 1)));
        self::assertSame(8, $columns, 'The 8 columns of the grid.');
        foreach ([$combination, $other] as $pse) {
            $row = \sprintf('tbody[data-row-id="%d"] > tr', $pse->getId());
            self::assertCount(7, $grid->filter($row), 'The three lines of a combination, its two lines shown when they have something to say, its fold and the air under it.');
            self::assertCount(1, $grid->filter($row.' > td.bo-combinations-grid__logistics .bo-combinations-grid__stock [data-testid="combinations-sellable-'.$pse->getId().'"]'), 'At the end of the stock field.');
        }
    }

    public function testTheIconSaysWhenNothingIsLeftAndWhenThePreorderSells(): void
    {
        [$product, $combination] = $this->productWithACombination(0);

        self::assertSame('none', $this->tab($product)->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]')->attr('data-state'));

        $combination->setOutOfStockPolicy(OutOfStockPolicy::Limited->value)->setPreorderLimit(4.0)->save($this->getPropelConnection());
        $icon = $this->tab($product)->filter('[data-testid="combinations-sellable-'.$combination->getId().'"]');
        self::assertSame('pre', $icon->attr('data-state'));
        self::assertSame('Sellable: 4 on preorder', $icon->attr('aria-label'));
    }

    public function testTheLowStockLineShowsAtOrUnderTheThresholdWithItsWording(): void
    {
        ConfigQuery::write('low_stock_threshold', '5');
        [$product, $combination] = $this->productWithACombination(5);
        $id = $combination->getId();

        $crawler = $this->tab($product);
        $line = $crawler->filter('[data-testid="combinations-low-stock-'.$id.'"]');
        self::assertNull($line->attr('hidden'), 'At the threshold of the shop, as the core alerts.');
        self::assertSame('Low stock: 5, threshold 5 (shop).', $crawler->filter('[data-testid="combinations-low-stock-text-'.$id.'"]')->text());
        self::assertCount(1, $line->filter('label[for="combination-'.$id.'-restock-date"]'), 'The restock date is offered in the line.');

        $combination->setLowStockThreshold(2.0)->save($this->getPropelConnection());
        $crawler = $this->tab($product);
        self::assertNotNull($crawler->filter('[data-testid="combinations-low-stock-'.$id.'"]')->attr('hidden'), 'Its own threshold wins over the one of the shop.');
        $note = $crawler->filter('[data-testid="combinations-row-'.$id.'"] [data-bo-combination-band-target="restockMoved"]');
        self::assertNotNull($note->attr('hidden'), 'The note on the moved restock date waits for a low stock.');
        self::assertStringNotContainsString('d-', (string) $note->attr('class'), 'No display utility: its !important would beat [hidden].');

        $combination->setQuantity(0)->save($this->getPropelConnection());
        self::assertSame('Out of stock, sales are blocked.', $this->tab($product)->filter('[data-testid="combinations-low-stock-text-'.$id.'"]')->text());
    }

    public function testTheFiguresOfAnyNumberOfCombinationsCostTheSameQueries(): void
    {
        [$product, $combination] = $this->productWithACombination(10);
        $reader = $this->getService(CombinationSellableReader::class);
        $few = [$combination];
        $many = [$combination];
        for ($i = 0; $i < 5; ++$i) {
            $many[] = $this->factory->productSaleElement($product, ['quantity' => 3]);
        }

        $fewReads = $this->cartReads(static fn () => $reader->of($few));
        $manyReads = $this->cartReads(static fn () => $reader->of($many));

        self::assertSame($fewReads, $manyReads, 'No query by combination.');
        self::assertLessThanOrEqual(2, $manyReads, 'The sellable answers and the reservation detail.');
        self::assertGreaterThan(0, $manyReads, 'The reservations are read.');
    }

    private function cartReads(callable $work): int
    {
        return \count(array_filter(QueryCounter::statements($work), static fn (string $sql): bool => str_contains($sql, 'cart_item')));
    }

    private function reserve(bool $enabled): void
    {
        ConfigQuery::write(StockSettings::RESERVATION_ENABLED, $enabled ? '1' : '0');
        ConfigQuery::resetCache();
    }

    private function hold(Product $product, ProductSaleElements $combination, int $quantity, string $interval): CartItem
    {
        $cart = $this->factory->cart(null, ['currency' => $this->defaultCurrency()]);
        $line = $this->factory->cartItem($cart, $product, $combination, ['quantity' => (float) $quantity]);
        $this->getPropelConnection()->exec('UPDATE cart_item SET reserved_until = NOW() '.$interval.' WHERE id = '.(int) $line->getId());

        return $line;
    }

    private function untilOf(CartItem $line): string
    {
        return (string) $this->getPropelConnection()->query('SELECT reserved_until FROM cart_item WHERE id = '.(int) $line->getId())->fetchColumn();
    }

    private function tab(Product $product): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithACombination(int $quantity): array
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->defaultCurrency(), ['basePrice' => 25.0]);
        $combination = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($combination);
        $combination->setQuantity($quantity)->save($this->getPropelConnection());

        $this->factory->attributeCombination($combination, $this->factory->attributeAv($this->factory->attribute(['title' => 'Size']), ['title' => 'Large']));

        return [$product, $combination];
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }
}
