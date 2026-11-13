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

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\OrderProduct;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The quantity rules and units of a combination on the combinations tab: saved row by row
 * without one row's values landing on another, refused row by row with the reason, given
 * to every combination of the product and to no other, previewed as a price per reference
 * unit, and out of reach of an administrator who may not update products.
 */
final class ProductCombinationQuantityRulesTest extends WebIntegrationTestCase
{
    private const GRID_URL = '/admin/product/combinations/update';
    private const APPLY_TO_ALL_URL = '/admin/product/combinations/quantity-rules/add-to-all';
    private const DEFAULT_URL = '/admin/product/default-price/update';
    private const PREVIEW_URL = '/admin/product/calculate-reference-price';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        if (!class_exists('Thelia\\Domain\\Catalog\\Quantity\\QuantityRule')) {
            self::markTestSkipped('The installed core predates the quantity rules of a combination.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testAProductSoldByThePieceShowsTheTabAsBeforeWithTheSettingsOutOfSight(): void
    {
        $this->loginFullAdmin();
        [$product, $first] = $this->productWithCombinations(1);

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($crawler->filter('[data-testid="combinations-quantity-rules-switch"]')->attr('checked'), 'The switch is off.');
        self::assertNotNull($crawler->filter(\sprintf('[data-testid="combinations-quantity-rules-toggle-%d"]', $first->getId()))->attr('hidden'));
        self::assertNotNull($crawler->filter('#pse-quantity-rules-'.$first->getId())->attr('hidden'), 'The panel is closed.');
        self::assertSame('1', $crawler->filter(\sprintf('input[name="min_quantity[%d]"]', $first->getId()))->attr('value'));
        self::assertSame('1', $crawler->filter(\sprintf('input[name="quantity_step[%d]"]', $first->getId()))->attr('value'));
        self::assertSame('0', $crawler->filter(\sprintf('select[name="quantity_decimals[%d]"] option[selected]', $first->getId()))->attr('value'));
        self::assertSame('', $crawler->filter(\sprintf('input[name="unit_quantity[%d]"]', $first->getId()))->attr('value'));
    }

    public function testAProductWithRulesShowsTheSettingsAndTheirValues(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithCombinations(1);
        $bottle = $this->combinationOf($product, ['minQuantity' => 2, 'quantityStep' => 6, 'unitQuantity' => 0.75, 'referenceUnitQuantity' => 1, 'unitCode' => 'l']);

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertNotNull($crawler->filter('[data-testid="combinations-quantity-rules-switch"]')->attr('checked'), 'The switch is on.');
        self::assertSame('2', $crawler->filter(\sprintf('input[name="min_quantity[%d]"]', $bottle->getId()))->attr('value'));
        self::assertSame('6', $crawler->filter(\sprintf('input[name="quantity_step[%d]"]', $bottle->getId()))->attr('value'));
        self::assertMatchesRegularExpression('/^0[.,]75$/', (string) $crawler->filter(\sprintf('input[name="unit_quantity[%d]"]', $bottle->getId()))->attr('value'));
        self::assertSame('l', $crawler->filter(\sprintf('select[name="unit_code[%d]"] option[selected]', $bottle->getId()))->attr('value'));
        self::assertCount(9, $crawler->filter(\sprintf('select[name="unit_code[%d]"] option[value!=""]', $bottle->getId())), 'Every unit the core knows.');
    }

    /**
     * Switching "sold by measure" off puts the fields back to a sale by the piece (the
     * controller bo-combination-quantity-rules posts these values): saved, the tab finds no
     * rule left and renders the switch off.
     */
    public function testSavingTheDefaultsOfASaleByThePieceOverARuleBringsTheSwitchBackOff(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithCombinations(1);
        $bottle = $this->combinationOf($product, ['minQuantity' => 2, 'quantityStep' => 6, 'unitQuantity' => 0.75, 'referenceUnitQuantity' => 1, 'unitCode' => 'l']);

        $this->postGrid($product, [[$bottle, ['min_quantity' => '1', 'quantity_step' => '1', 'quantity_decimals' => '0', 'unit_quantity' => '', 'reference_unit_quantity' => '', 'unit_code' => '']]]);

        self::assertSame([], $this->flashes()['danger']);
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertNull($crawler->filter('[data-testid="combinations-quantity-rules-switch"]')->attr('checked'));
    }

    public function testTheGridSavesTheRulesOfEachRowWithoutShiftingThemToAnotherRow(): void
    {
        $this->loginFullAdmin();
        [$product, $kept, $byWeight, $byPack] = $this->productWithCombinations(3);
        $kept->setMinQuantity('3.000000')->save($this->getPropelConnection());

        // The first row posts none of its rule fields: a module's form, an older page.
        $this->postGrid($product, [
            [$kept, null],
            [$byWeight, ['min_quantity' => '0,5', 'quantity_step' => '0,25', 'quantity_decimals' => '2', 'unit_quantity' => '', 'reference_unit_quantity' => '1', 'unit_code' => 'kg']],
            [$byPack, ['min_quantity' => '2', 'quantity_step' => '6', 'quantity_decimals' => '0', 'unit_quantity' => '0,75', 'reference_unit_quantity' => '1', 'unit_code' => 'l']],
        ]);

        self::assertSame([], $this->flashes()['danger']);

        $kept = $this->fresh($kept);
        self::assertSame('3.000000', $kept->getMinQuantity(), 'A row that posts no rule keeps its own.');

        $byWeight = $this->fresh($byWeight);
        self::assertSame(['0.500000', '0.250000', 2, null, '1.000000', 'kg'], $this->ruleOf($byWeight));

        $byPack = $this->fresh($byPack);
        self::assertSame(['2.000000', '6.000000', 0, '0.750000', '1.000000', 'l'], $this->ruleOf($byPack));
    }

    public function testARuleTheCoreRefusesCostsItsRowOnlyAndNamesIt(): void
    {
        $this->loginFullAdmin();
        [$product, $refused, $saved] = $this->productWithCombinations(2);

        $this->postGrid($product, [
            // Half a piece cannot be sold by the piece.
            [$refused, ['min_quantity' => '1', 'quantity_step' => '0,5', 'quantity_decimals' => '0', 'quantity' => 40]],
            [$saved, ['min_quantity' => '1', 'quantity_step' => '6', 'quantity_decimals' => '0', 'quantity' => 41]],
        ]);

        $refusals = $this->flashes()['danger'];
        self::assertCount(1, $refusals, implode(' | ', $refusals));
        self::assertStringContainsString($refused->getRef(), $refusals[0]);
        self::assertSame('1.000000', $this->fresh($refused)->getQuantityStep(), 'The refused row is not saved at all.');
        self::assertSame(7.0, (float) $this->fresh($refused)->getQuantity());
        self::assertSame('6.000000', $this->fresh($saved)->getQuantityStep());
        self::assertSame(41.0, (float) $this->fresh($saved)->getQuantity());
    }

    public function testAnUnknownUnitAndAnUnreadableNumberAreRefusedWithTheirRow(): void
    {
        $this->loginFullAdmin();
        [$product, $unknownUnit, $unreadable] = $this->productWithCombinations(2);

        $this->postGrid($product, [
            [$unknownUnit, ['unit_code' => 'oz']],
            [$unreadable, ['quantity_step' => 'six']],
        ]);

        $refusals = $this->flashes()['danger'];
        self::assertCount(2, $refusals, implode(' | ', $refusals));
        self::assertStringContainsString($unknownUnit->getRef(), $refusals[0]);
        self::assertStringContainsString('oz', $refusals[0]);
        self::assertStringContainsString($unreadable->getRef(), $refusals[1]);
        self::assertNull($this->fresh($unknownUnit)->getUnitCode());
    }

    public function testAStockWithMoreDecimalsThanTheCombinationIsSoldWithIsRefusedWithItsRow(): void
    {
        $this->loginFullAdmin();
        [$product, $combination] = $this->productWithCombinations(1);

        $this->postGrid($product, [[$combination, ['quantity_decimals' => '0', 'quantity' => '2.5']]]);

        $refusals = $this->flashes()['danger'];
        self::assertCount(1, $refusals);
        self::assertStringContainsString($combination->getRef(), $refusals[0]);
        self::assertSame(7.0, (float) $this->fresh($combination)->getQuantity());
    }

    public function testApplyingToEveryCombinationGivesTheTypedRulesToTheProductsCombinationsOnly(): void
    {
        $this->loginFullAdmin();
        [$product, $source, $other] = $this->productWithCombinations(2);
        [, $elsewhere] = $this->productWithCombinations(1);

        $this->postGrid($product, [
            [$source, ['min_quantity' => '2', 'quantity_step' => '6', 'unit_quantity' => '0,75', 'reference_unit_quantity' => '1', 'unit_code' => 'l']],
            [$other, ['quantity' => 12]],
            // An id of another product posted with the grid.
            [$elsewhere, ['quantity' => 99]],
        ], self::APPLY_TO_ALL_URL, ['quantity_rules_source' => $source->getId()]);

        self::assertSame([], $this->flashes()['danger']);
        self::assertSame(['2.000000', '6.000000', 0, '0.750000', '1.000000', 'l'], $this->ruleOf($this->fresh($source)));
        self::assertSame(['2.000000', '6.000000', 0, '0.750000', '1.000000', 'l'], $this->ruleOf($this->fresh($other)));
        self::assertSame(12.0, (float) $this->fresh($other)->getQuantity(), 'The rest of the grid is saved as typed.');

        $elsewhere = $this->fresh($elsewhere);
        self::assertSame(['1.000000', '1.000000', 0, null, null, null], $this->ruleOf($elsewhere), 'A combination of another product is left alone.');
        self::assertSame(7.0, (float) $elsewhere->getQuantity());
    }

    public function testApplyingRulesTheCoreRefusesSavesNothing(): void
    {
        $this->loginFullAdmin();
        [$product, $source, $other] = $this->productWithCombinations(2);

        $this->postGrid($product, [
            [$source, ['quantity_step' => '0', 'quantity' => 30]],
            [$other, ['quantity' => 31]],
        ], self::APPLY_TO_ALL_URL, ['quantity_rules_source' => $source->getId()]);

        $refusals = $this->flashes()['danger'];
        self::assertCount(1, $refusals);
        self::assertStringContainsString($source->getRef(), $refusals[0]);
        self::assertSame(7.0, (float) $this->fresh($other)->getQuantity());
    }

    public function testApplyingFromACombinationOfAnotherProductWritesNothing(): void
    {
        $this->loginFullAdmin();
        [$product, $combination] = $this->productWithCombinations(1);
        [, $elsewhere] = $this->productWithCombinations(1);

        $this->postGrid($product, [[$combination, ['quantity_step' => '6', 'quantity' => 30]]], self::APPLY_TO_ALL_URL, ['quantity_rules_source' => $elsewhere->getId()]);

        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertSame('1.000000', $this->fresh($combination)->getQuantityStep());
        self::assertSame(7.0, (float) $this->fresh($combination)->getQuantity());
    }

    public function testApplyingWithoutTheTokenWritesNothing(): void
    {
        $this->loginFullAdmin();
        [$product, $source, $other] = $this->productWithCombinations(2);

        $this->postGrid($product, [
            [$source, ['quantity_step' => '6']],
            [$other, []],
        ], self::APPLY_TO_ALL_URL, ['quantity_rules_source' => $source->getId(), '_token' => 'forged']);

        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('1.000000', $this->fresh($other)->getQuantityStep());
    }

    public function testTheDefaultFormSavesTheRulesAndRefusesAnInvalidOne(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();

        $this->postDefault($product, $pse, ['min_quantity' => '0,25', 'quantity_step' => '0,25', 'quantity_decimals' => '2', 'unit_code' => 'kg', 'reference_unit_quantity' => '1', 'unit_quantity' => '']);
        self::assertSame(['0.250000', '0.250000', 2, null, '1.000000', 'kg'], $this->ruleOf($this->fresh($pse)));

        $this->postDefault($product, $pse, ['quantity_decimals' => '4', 'quantity' => 99]);
        $refusals = $this->flashes()['danger'];
        self::assertCount(1, $refusals);
        self::assertStringContainsString($pse->getRef(), $refusals[0]);
        self::assertSame(2, $this->fresh($pse)->getQuantityDecimals());
        self::assertSame(7.0, (float) $this->fresh($pse)->getQuantity());
    }

    public function testThePreviewGivesThePricePerReferenceUnitComputedByTheCore(): void
    {
        $this->loginFullAdmin();

        $this->client->request('GET', self::PREVIEW_URL, [
            'price' => '9.99',
            'tax_rule_id' => 0,
            'currency_id' => $this->defaultCurrency()->getId(),
            'unit_quantity' => '0,75',
            'reference_unit_quantity' => '1',
            'unit_code' => 'l',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(13.32, $payload['result']);
        self::assertStringContainsString('13', (string) $payload['formatted']);
        self::assertStringEndsWith(' / l', (string) $payload['formatted']);
    }

    public function testThePreviewWritesTheUnitOfAReferenceQuantityOfAnArticleSoldByThePiece(): void
    {
        $this->loginFullAdmin();

        // A 250 g jar sold by the piece (no decimals): its reference quantity still is a measure.
        $this->client->request('GET', self::PREVIEW_URL, [
            'price' => '3',
            'tax_rule_id' => 0,
            'currency_id' => $this->defaultCurrency()->getId(),
            'quantity_decimals' => '0',
            'unit_quantity' => '250',
            'reference_unit_quantity' => '100',
            'unit_code' => 'g',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1.2, $payload['result']);
        self::assertStringEndsWith(' / 100 g', (string) $payload['formatted']);
    }

    public function testThePreviewIsEmptyWithoutAUnitAndRefusesAnUnreadableNumber(): void
    {
        $this->loginFullAdmin();

        $this->client->request('GET', self::PREVIEW_URL, ['price' => '9.99', 'unit_quantity' => '0,75', 'reference_unit_quantity' => '1', 'unit_code' => '']);
        self::assertSame(['result' => null, 'formatted' => null], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('GET', self::PREVIEW_URL, ['price' => '9.99', 'unit_quantity' => 'abc', 'reference_unit_quantity' => '1', 'unit_code' => 'l']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAdministratorWhoMayOnlyViewProductsIsRefusedEveryWrite(): void
    {
        [$product, $source] = $this->productWithCombinations(1);
        [, $default] = $this->productWithoutCombination();
        $this->loginFullAdmin();
        $gridToken = $this->tokenOf($product, 'combinations-form');

        $admin = $this->factory->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $grid = [
            '_token' => $gridToken,
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$source->getId()],
            'reference' => [$source->getRef()],
            'quantity' => [7],
            'quantity_step' => [$source->getId() => '6'],
            'quantity_rules_source' => $source->getId(),
        ];

        foreach ([self::GRID_URL, self::APPLY_TO_ALL_URL] as $url) {
            $this->client->request('POST', $url, $grid);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }

        $this->client->request('POST', self::DEFAULT_URL, ['_token' => $gridToken, 'product_id' => $default->getProductId(), 'product_sale_element_id' => $default->getId(), 'quantity_step' => '6', 'quantity' => 7]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        self::assertSame('1.000000', $this->fresh($source)->getQuantityStep());
        self::assertSame('1.000000', $this->fresh($default)->getQuantityStep());
    }

    public function testAnAdministratorWithoutTheProductResourceIsRefusedTheTabAndThePreview(): void
    {
        [$product] = $this->productWithCombinations(1);
        $admin = $this->factory->restrictedAdmin([AdminResources::CATEGORY => [AccessManager::VIEW]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->client->request('GET', self::PREVIEW_URL, ['price' => '9.99', 'unit_quantity' => '0.75', 'reference_unit_quantity' => '1', 'unit_code' => 'l']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheOrderSheetShowsTheQuantityInTheUnitItWasSoldIn(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        (new OrderProduct())
            ->setOrderId($order->getId())
            ->setProductRef('SOLD-REF')
            ->setProductSaleElementsRef('SOLD-PSE-REF')
            ->setTitle('Sold by weight')
            ->setQuantity(1.25)
            ->setQuantityDecimals(2)
            ->setUnitCode('kg')
            ->setPrice('14.820000')
            ->setWasNew(0)
            ->setWasInPromo(0)
            ->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertMatchesRegularExpression('/1[.,]25\x{a0}?\s?kg/u', $crawler->filter('tr[data-order-product-id]')->text());
    }

    /**
     * @return list<string|int|null>
     */
    private function ruleOf(ProductSaleElements $pse): array
    {
        return [
            $pse->getMinQuantity(),
            $pse->getQuantityStep(),
            $pse->getQuantityDecimals(),
            $pse->getUnitQuantity(),
            $pse->getReferenceUnitQuantity(),
            $pse->getUnitCode(),
        ];
    }

    /**
     * Posts the grid as the page posts it: the columns of the grid as parallel arrays, the
     * quantity rules keyed by the id of their combination. A row given null posts no rule.
     *
     * @param list<array{ProductSaleElements, array<string, mixed>|null}> $rows
     * @param array<string, mixed>                                        $extra
     */
    private function postGrid(Product $product, array $rows, string $url = self::GRID_URL, array $extra = []): void
    {
        $payload = $extra + [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
        ];

        $ruleFields = ['min_quantity', 'quantity_step', 'quantity_decimals', 'unit_quantity', 'reference_unit_quantity', 'unit_code'];
        foreach ($rows as [$pse, $values]) {
            $id = (int) $pse->getId();
            $payload['product_sale_element_id'][] = $id;
            $payload['reference'][] = $pse->getRef();
            $payload['quantity'][] = $values['quantity'] ?? $pse->getQuantity();
            $payload['ean_code'][] = '';
            $payload['mpn'][] = '';
            $payload['manufacturer_brand_id'][] = 0;

            if ($values === null) {
                continue;
            }

            foreach ($ruleFields as $field) {
                $payload[$field][$id] = $values[$field] ?? ['min_quantity' => '1', 'quantity_step' => '1', 'quantity_decimals' => '0'][$field] ?? '';
            }
        }

        $this->client->request('POST', $url, $payload);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function postDefault(Product $product, ProductSaleElements $pse, array $values): void
    {
        $this->client->request('POST', self::DEFAULT_URL, $values + [
            '_token' => $this->tokenOf($product, 'default-pse-form'),
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'reference' => $pse->getRef(),
            'tax_rule' => $product->getTaxRuleId(),
            'quantity' => 7,
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array{danger: list<string>, warning: list<string>}
     */
    private function flashes(): array
    {
        self::assertTrue($this->client->getResponse()->isRedirection(), 'The save redirects to the product sheet. '.$this->client->getResponse()->getStatusCode().' '.substr((string) $this->client->getResponse()->getContent(), 0, 800));

        $crawler = $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));

        $read = static fn (string $type): array => $crawler->filter(\sprintf('[data-testid="bo-flash-%s"]', $type))->each(static fn ($alert): string => trim($alert->text()));

        return ['danger' => $read('danger'), 'warning' => $read('warning')];
    }

    private function loginFullAdmin(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithoutCombination(): array
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->defaultCurrency(),
            ['basePrice' => 25.0, 'baseQuantity' => 7],
        );
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($pse);

        return [$product, $pse];
    }

    /**
     * A product whose default combination and `$count - 1` others carry an attribute value
     * each, so that the tab shows the grid; every one priced and with a stock of 7.
     *
     * @return list<Product|ProductSaleElements> the product, then its combinations
     */
    private function productWithCombinations(int $count): array
    {
        [$product, $default] = $this->productWithoutCombination();
        $attribute = $this->factory->attribute(['title' => 'Size']);
        $this->factory->attributeCombination($default, $this->factory->attributeAv($attribute, ['title' => 'Size 0']));

        $combinations = [$default];
        for ($index = 1; $index < $count; ++$index) {
            $combination = $this->combinationOf($product);
            $this->factory->attributeCombination($combination, $this->factory->attributeAv($attribute, ['title' => 'Size '.$index]));
            $combinations[] = $combination;
        }

        return [$product, ...$combinations];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function combinationOf(Product $product, array $overrides = []): ProductSaleElements
    {
        $combination = $this->factory->productSaleElement($product, $overrides + ['quantity' => 7]);
        $this->factory->productPrice($combination, $this->defaultCurrency());

        return $combination;
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }

    private function tokenOf(Product $product, string $formTestId): string
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        $token = $crawler->filter(\sprintf('form[data-testid="%s"] input[name="_token"]', $formTestId))->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('The "%s" form renders no token field. %d %s', $formTestId, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 1500)));

        return (string) $token->attr('value');
    }

    private function fresh(ProductSaleElements $pse): ProductSaleElements
    {
        ProductSaleElementsTableMap::clearInstancePool();
        $fresh = ProductSaleElementsQuery::create()->findPk($pse->getId(), $this->getPropelConnection());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
