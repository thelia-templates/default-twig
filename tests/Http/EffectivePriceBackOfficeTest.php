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

use BackOfficeDefaultTwigBundle\Service\Product\CombinationLabeler;
use BackOfficeDefaultTwigBundle\Service\Product\EffectivePricePresenter;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Customer\Service\CustomerGroupResolver;
use Thelia\Domain\Pricing\CustomerPrice\CustomerPriceContextInterface;
use Thelia\Domain\Pricing\PriceList\PriceListResolverInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerGroup;
use Thelia\Model\PriceList;
use Thelia\Model\PriceListAssignment;
use Thelia\Model\PriceListItem;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The effective prices of a product for a customer, on the combinations tab: what the single
 * resolver of the shop answers, laid out with its source, whether it can be bought and the tiers.
 */
final class EffectivePriceBackOfficeTest extends WebIntegrationTestCase
{
    private const PANEL = '/admin/products/effective-prices/panel';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheCustomerOfAPriceListSeesTheListPriceAndAnotherCustomerTheCatalogPrice(): void
    {
        [$product, $pse] = $this->product();
        $group = $this->factory->customerGroup(title: 'Wholesalers');
        $member = $this->memberOf($group);
        $outsider = $this->memberOf($this->factory->customerGroup());
        $list = $this->priceList('PRO');
        $this->assignToGroup($list, $group);
        $this->item($list, $pse, '80');

        $crawler = $this->panel($product, $member);

        self::assertSame('120.00 € incl. tax', $this->text($crawler, 'effective-prices-catalog-'.$pse->getId()));
        self::assertSame('96.00 € incl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId()));
        self::assertStringContainsString('Price list PRO', $this->text($crawler, 'effective-prices-source-'.$pse->getId()));
        self::assertSame('Yes', $this->text($crawler, 'effective-prices-purchasable-'.$pse->getId()));
        self::assertSame('Wholesalers', $this->text($crawler, 'effective-prices-group'));

        $crawler = $this->panel($product, $outsider);

        self::assertSame('120.00 € incl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId()));
        self::assertStringContainsString('Catalog price', $this->text($crawler, 'effective-prices-source-'.$pse->getId()));
        self::assertStringNotContainsString('Price list', $this->text($crawler, 'effective-prices-source-'.$pse->getId()));
    }

    public function testThePanelShowsWhatTheSingleResolverAnswers(): void
    {
        [$product, $pse] = $this->product();
        $group = $this->factory->customerGroup(discount: 10.0);
        $member = $this->memberOf($group);
        $list = $this->priceList('PRO', priority: 50);
        $this->assignToCustomer($list, $member);
        $this->item($list, $pse, '63.5');

        $resolved = $this->getService(CustomerPriceContextInterface::class)->resolve([(int) $pse->getId()], Currency::getDefaultCurrency(), $member);
        $this->getService('services_resetter')->reset();

        $crawler = $this->panel($product, $member);

        self::assertSame(number_format($resolved[(int) $pse->getId()]->chargedPrice(), 2, '.', '').' € excl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId().'-untaxed'), 'Above the price with taxes, the answer of the resolver, without taxes.');
        self::assertSame('76.20 € incl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId()));
    }

    public function testThePriceOfAGroupWithoutAListComesFromTheGroupDiscount(): void
    {
        [$product, $pse] = $this->product();
        $member = $this->memberOf($this->factory->customerGroup(discount: 10.0));

        $crawler = $this->panel($product, $member);

        self::assertSame('108.00 € incl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId()));
        self::assertSame('120.00 € incl. tax', $this->text($crawler, 'effective-prices-catalog-'.$pse->getId()));
    }

    public function testTheTiersOfTheListAreListedWithTheirMinimumQuantity(): void
    {
        [$product, $pse] = $this->product();
        $group = $this->factory->customerGroup();
        $member = $this->memberOf($group);
        $list = $this->priceList('PRO');
        $this->assignToGroup($list, $group);
        $this->item($list, $pse, '10', 1);
        $this->item($list, $pse, '9', 10);

        $crawler = $this->panel($product, $member);

        $tiers = $this->text($crawler, 'effective-prices-tiers-'.$pse->getId());
        self::assertStringContainsString('1+ : 10.00 € excl. tax · 12.00 € incl. tax', $tiers, 'Without then with taxes.');
        self::assertStringContainsString('10+ : 9.00 € excl. tax · 10.80 € incl. tax', $tiers);
        self::assertSame('12.00 € incl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId()), 'The price is the one of one unit.');
    }

    /**
     * The tiers of a list are read from the resolver of the lists, which answers them whatever
     * the quantity: the panel resolves the price of one unit, once, and never prices a quantity
     * no tier can reach to find them.
     */
    public function testTheTiersAreReadFromTheTiersReaderAndNoQuantityIsProbed(): void
    {
        [$product, $pse] = $this->product();
        $group = $this->factory->customerGroup();
        $member = $this->memberOf($group);
        $list = $this->priceList('PRO');
        $this->assignToGroup($list, $group);
        $this->item($list, $pse, '10', 1);
        $this->item($list, $pse, '9', 10);
        $this->item($list, $pse, '7', 100);

        $spy = new class($this->getService(CustomerPriceContextInterface::class)) implements CustomerPriceContextInterface {
            /** @var list<array<int, int>> */
            public array $quantities = [];

            public function __construct(private readonly CustomerPriceContextInterface $inner)
            {
            }

            public function resolve(array $productSaleElementsIds, Currency $currency, ?Customer $customer, array $quantities = []): array
            {
                $this->quantities[] = $quantities;

                return $this->inner->resolve($productSaleElementsIds, $currency, $customer, $quantities);
            }

            public function resolveBundlePrices(array $bundlePrices, ?Customer $customer): array
            {
                return $this->inner->resolveBundlePrices($bundlePrices, $customer);
            }

            public function customerGroupOf(?Customer $customer): CustomerGroup
            {
                return $this->inner->customerGroupOf($customer);
            }

            public function displaysTaxedPrices(?Customer $customer): bool
            {
                return $this->inner->displaysTaxedPrices($customer);
            }
        };
        $presenter = new EffectivePricePresenter(
            $spy,
            $this->getService(PriceListResolverInterface::class),
            $this->getService(CombinationLabeler::class),
            $this->getService(\Thelia\Domain\Pricing\PriceVisibility\PriceVisibilityResolver::class),
            $this->getService(\Thelia\Domain\Catalog\Restriction\CatalogRestrictionRule::class),
            $this->getService(\Thelia\Domain\Catalog\Product\ClosedCategoryProductRule::class),
            $this->getService(TranslatorInterface::class),
        );

        $panel = $presenter->present($product, Currency::getDefaultCurrency(), $member, 'en_US');

        self::assertSame([['quantity' => 1, 'price' => '10.00', 'price_with_tax' => '12.00'], ['quantity' => 10, 'price' => '9.00', 'price_with_tax' => '10.80'], ['quantity' => 100, 'price' => '7.00', 'price_with_tax' => '8.40']], $panel['rows'][0]['tiers']);
        self::assertSame('10.00', $panel['rows'][0]['price']);
        self::assertSame([[]], $spy->quantities, 'One resolution, at the default quantity.');
    }

    public function testTheTiersShowEvenWhenOneUnitIsBelowTheFirstTier(): void
    {
        [$product, $pse] = $this->product();
        $group = $this->factory->customerGroup();
        $member = $this->memberOf($group);
        $list = $this->priceList('PRO');
        $this->assignToGroup($list, $group);
        $this->item($list, $pse, '70', 5);

        $crawler = $this->panel($product, $member);

        self::assertSame('120.00 € incl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId()), 'One unit is under the first tier: the catalog price applies.');
        self::assertStringContainsString('84.00 € incl. tax', $this->text($crawler, 'effective-prices-tiers-'.$pse->getId()));
    }

    public function testACombinationAnExclusiveListDoesNotPriceCannotBeBought(): void
    {
        [$product, $pse] = $this->product();
        $group = $this->factory->customerGroup();
        $member = $this->memberOf($group);
        $list = $this->priceList('EXCL', exclusive: true);
        $this->assignToGroup($list, $group);
        $other = $this->secondCombination($product);
        $this->item($list, $pse, '80');

        $crawler = $this->panel($product, $member);

        self::assertSame('Yes', $this->text($crawler, 'effective-prices-purchasable-'.$pse->getId()));
        self::assertSame('No', $this->text($crawler, 'effective-prices-purchasable-'.$other->getId()));
        self::assertStringContainsString('closed by the exclusive price list EXCL', $this->text($crawler, 'effective-prices-source-'.$other->getId()));
    }

    public function testAProductSoldOnRequestShowsNoPriceAndCannotBeBoughtWhateverTheResolverSays(): void
    {
        [$product, $pse] = $this->product();
        $product->setPriceDisplayMode('on_request')->save($this->getPropelConnection());
        $member = $this->memberOf($this->factory->customerGroup());

        foreach ([$this->panel($product, $member), $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_visitor=1')] as $crawler) {
            self::assertSame('on_request', $crawler->filter('[data-testid="effective-prices-status"]')->attr('data-status'));
            self::assertSame('-', $this->text($crawler, 'effective-prices-price-'.$pse->getId()), 'A price the customer is not shown is not resolved here.');
            self::assertSame('No', $this->text($crawler, 'effective-prices-purchasable-'.$pse->getId()));
        }
    }

    public function testAShopThatShowsThePricesToSignedInCustomersOnlyIsReadAsAVisitorAndAsACustomer(): void
    {
        [$product, $pse] = $this->product();
        $member = $this->memberOf($this->factory->customerGroup());
        $previous = ConfigQuery::read('price_display_mode');
        ConfigQuery::write('price_display_mode', 'logged_in');

        try {
            $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_visitor=1');
            self::assertSame('logged_in', $crawler->filter('[data-testid="effective-prices-status"]')->attr('data-status'));
            self::assertSame('-', $this->text($crawler, 'effective-prices-price-'.$pse->getId()));
            self::assertSame('No', $this->text($crawler, 'effective-prices-purchasable-'.$pse->getId()));

            $crawler = $this->panel($product, $member);
            self::assertCount(0, $crawler->filter('[data-testid="effective-prices-status"]'), 'A signed-in customer is shown the price.');
            self::assertSame('120.00 € incl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId()));
            self::assertSame('Yes', $this->text($crawler, 'effective-prices-purchasable-'.$pse->getId()));
        } finally {
            ConfigQuery::write('price_display_mode', $previous ?? 'visible');
        }
    }

    public function testAProductOutsideTheAssortmentOfTheCustomerIsNeitherPricedNorBuyable(): void
    {
        [$product, $pse] = $this->product();
        $closed = $this->factory->customerGroup();
        $member = $this->memberOf($closed);
        $outsider = $this->memberOf($this->factory->customerGroup());
        (new \Thelia\Model\CatalogRestriction())
            ->setCustomerGroupId($closed->getId())
            ->setCategoryId($this->factory->category()->getId())
            ->setMode('allow')
            ->save($this->getPropelConnection());

        $crawler = $this->panel($product, $member);

        self::assertSame('outside_assortment', $crawler->filter('[data-testid="effective-prices-status"]')->attr('data-status'));
        self::assertSame('-', $this->text($crawler, 'effective-prices-price-'.$pse->getId()));
        self::assertSame('No', $this->text($crawler, 'effective-prices-purchasable-'.$pse->getId()));

        $crawler = $this->panel($product, $outsider);
        self::assertCount(0, $crawler->filter('[data-testid="effective-prices-status"]'), 'A customer of another group still sees the product.');
        self::assertSame('Yes', $this->text($crawler, 'effective-prices-purchasable-'.$pse->getId()));
    }

    public function testAProductFiledOutsideTheCategoriesOfARestrictedGroupIsOutsideItsAssortment(): void
    {
        [$product, $pse] = $this->product();
        $restricted = $this->factory->customerGroup(categoryIds: [(int) $this->factory->category()->getId()]);
        $member = $this->memberOf($restricted);

        $crawler = $this->panel($product, $member);

        self::assertSame('outside_assortment', $crawler->filter('[data-testid="effective-prices-status"]')->attr('data-status'));
        self::assertSame('No', $this->text($crawler, 'effective-prices-purchasable-'.$pse->getId()));
    }

    public function testThePromotionalCatalogPriceShowsOnlyWhileTheCombinationIsOnPromotion(): void
    {
        [$product, $pse] = $this->product();
        $price = \Thelia\Model\ProductPriceQuery::create()->filterByProductSaleElementsId($pse->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($price);
        $price->setPromoPrice('80')->save($this->getPropelConnection());
        $member = $this->memberOf($this->factory->customerGroup());

        $pse->setPromo(0)->save($this->getPropelConnection());
        $crawler = $this->panel($product, $member);
        self::assertSame('120.00 € incl. tax', $this->text($crawler, 'effective-prices-catalog-'.$pse->getId()), 'A promotional price kept on a combination that is not on promotion is not shown.');

        $pse->setPromo(1)->save($this->getPropelConnection());
        $crawler = $this->panel($product, $member);
        self::assertSame('120.00 96.00 € incl. tax', $this->text($crawler, 'effective-prices-catalog-'.$pse->getId()));
    }

    public function testAVisitorIsPricedAsTheDefaultGroup(): void
    {
        [$product, $pse] = $this->product();
        $group = $this->factory->customerGroup();
        $list = $this->priceList('PRO');
        $this->assignToGroup($list, $group);
        $this->item($list, $pse, '80');

        $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_visitor=1');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('120.00 € incl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId()));
        self::assertSame('Yes', $this->text($crawler, 'effective-prices-purchasable-'.$pse->getId()));
    }

    public function testNothingIsPricedUntilACustomerOrTheVisitorIsChosen(): void
    {
        [$product] = $this->product();

        $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId());

        self::assertCount(1, $crawler->filter('[data-testid="effective-prices-section"]'));
        self::assertCount(0, $crawler->filter('[data-testid="effective-prices-table"]'));
        self::assertCount(1, $crawler->filter('[data-testid="effective-prices-unsaved"].bo-effective-prices__unsaved'), 'The notice of unsaved changes is there, shown by the stylesheet only while a form holds changes.');
    }

    public function testTheSearchOfACustomerAnswersTwentyAtMostAndNothingUnderThreeCharacters(): void
    {
        [$product] = $this->product();
        for ($i = 0; $i < 25; ++$i) {
            $this->factory->customer($this->factory->customerTitle(), ['lastname' => 'Priced'.$i, 'email' => 'priced'.$i.'@example.test']);
        }

        $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_customer_q=Priced');
        self::assertCount(20, $crawler->filter('[data-testid="effective-prices-results"] tbody tr'));
        $link = (string) $crawler->filter('[data-testid="effective-prices-results"] a')->first()->attr('href');
        self::assertStringContainsString('effective_customer_id=', $link);
        self::assertStringEndsWith('#other-prices', $link, 'The page comes back to the card.');
        self::assertCount(1, $crawler->filter('[data-testid="effective-prices-results-capped"]'), 'A full list says it may be cut.');

        $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_customer_q=Pr');
        self::assertCount(0, $crawler->filter('[data-testid="effective-prices-results"]'));
    }

    public function testAPriceWithTaxesKeepsTheDecimalsOfThePriceWithout(): void
    {
        [$product, $pse] = $this->product();
        $price = \Thelia\Model\ProductPriceQuery::create()->filterByProductSaleElementsId($pse->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($price);

        foreach (['9.99' => '11.99 € incl. tax', '0.0035' => '0.0042 € incl. tax', '25' => '30.00 € incl. tax'] as $untaxed => $expected) {
            $price->setPrice((string) $untaxed)->setPromoPrice((string) $untaxed)->save($this->getPropelConnection());
            $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_visitor=1');
            self::assertSame($expected, $this->text($crawler, 'effective-prices-price-'.$pse->getId()), $untaxed.' without taxes.');
        }
    }

    public function testAPriceListIsNamedByItsTitleAndByItsCodeWithoutOne(): void
    {
        [$product, $pse] = $this->product();
        $group = $this->factory->customerGroup();
        $member = $this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $group]);
        $list = $this->priceList('PRO-TITLED');
        $list->setLocale('en_US')->setTitle('Professionals 2026')->save($this->getPropelConnection());
        (new PriceListAssignment())->setPriceListId($list->getId())->setCustomerGroupId($group->getId())->save($this->getPropelConnection());
        $this->item($list, $pse, '80');

        $source = $this->text($this->panel($product, $member), 'effective-prices-source-'.$pse->getId());
        self::assertStringContainsString('Professionals 2026', $source, 'The title the merchant gave the list.');
        self::assertStringNotContainsString('PRO-TITLED', $source);
    }

    public function testThePriceTheCustomerIsShownIsInBold(): void
    {
        [$product, $pse] = $this->product();
        $untaxedGroup = $this->factory->customerGroup(displayTaxedPrice: false);
        $member = $this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $untaxedGroup]);

        $crawler = $this->panel($product, $member);
        self::assertStringContainsString('fw-semibold', (string) $crawler->filter('[data-testid="effective-prices-price-'.$pse->getId().'-untaxed"]')->attr('class'), 'Its group is shown the prices without taxes.');
        self::assertStringNotContainsString('fw-semibold', (string) $crawler->filter('[data-testid="effective-prices-price-'.$pse->getId().'"]')->attr('class'));
    }

    public function testASearchThatFindsOneCustomerOpensItAndOneThatFindsNoneSaysSo(): void
    {
        [$product] = $this->product();
        $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Ada', 'lastname' => 'Uniquename', 'email' => 'ada.uniquename@example.test']);

        $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_customer_q=Uniquename');
        self::assertCount(0, $crawler->filter('[data-testid="effective-prices-results"]'));
        self::assertStringContainsString('Uniquename', $crawler->filter('[data-testid="effective-prices-subject"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="effective-prices-table"]'));

        $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Bob', 'lastname' => 'Uniquename', 'email' => 'bob.uniquename@example.test']);
        $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_customer_q=Uniquename');
        self::assertCount(2, $crawler->filter('[data-testid="effective-prices-results"] tbody tr'));
        self::assertCount(0, $crawler->filter('[data-testid="effective-prices-results-capped"]'), 'A short list is complete.');

        $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_customer_q='.str_repeat('a', 300));
        self::assertSame(100, mb_strlen((string) $crawler->filter('[data-testid="effective-prices-search"]')->attr('value')), 'A term longer than the field is cut to it.');

        $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_customer_q=Nobodyhere');
        self::assertStringContainsString('Nobodyhere', $crawler->filter('[data-testid="effective-prices-no-result"]')->text());
    }

    public function testBothTheRightToSeeThePricingAndTheOneToSeeTheCustomersAreNeeded(): void
    {
        [$product, $pse] = $this->product();
        $member = $this->memberOf($this->factory->customerGroup());
        $tab = '/admin/products/combinations/tab?product_id='.$product->getId().'&effective_customer_id='.$member->getId();

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW], AdminResources::CUSTOMER => [AccessManager::VIEW]]));
        $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_customer_id='.$member->getId());
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Without the pricing the panel is refused.');
        $crawler = $this->client->request('GET', $tab);
        self::assertCount(0, $crawler->filter('[data-testid="effective-prices-section"]'));
        self::assertStringNotContainsString('not allowed', $crawler->text(), 'The tab does not embed a refusal.');

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW], AdminResources::PRICING => [AccessManager::VIEW]]));
        $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_customer_id='.$member->getId());
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Without the customers the panel is refused.');
        $crawler = $this->client->request('GET', $tab);
        self::assertCount(0, $crawler->filter('[data-testid="effective-prices-section"]'));
        self::assertStringNotContainsString('not allowed', $crawler->text(), 'The tab does not embed a refusal.');

        $this->injector->setAdmin($this->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW], AdminResources::PRICING => [AccessManager::VIEW], AdminResources::CUSTOMER => [AccessManager::VIEW]]));
        $crawler = $this->client->request('GET', $tab);
        self::assertCount(1, $crawler->filter('[data-testid="effective-prices-section"]'));
        self::assertSame('120.00 € incl. tax', $this->text($crawler, 'effective-prices-price-'.$pse->getId()), 'The panel prices the customer chosen in the query of the page.');
    }

    /**
     * What stops the panel from pricing a customer GROUP without a member: the group of a customer
     * not stored yet is the default group, whatever group id it carries. The panel therefore
     * offers a customer or a visitor, and this test says when that can change.
     */
    public function testACustomerNotStoredYetIsPricedAsTheDefaultGroupWhateverGroupItCarries(): void
    {
        $group = $this->factory->customerGroup();
        $unsaved = (new Customer())->setCustomerGroupId($group->getId());

        $resolver = $this->getService(CustomerGroupResolver::class);

        self::assertSame($resolver->defaultGroupId(), $resolver->groupIdOf($unsaved));
        self::assertNotSame($group->getId(), $resolver->groupIdOf($unsaved));
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function product(): array
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        self::assertNotNull($currency);

        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $currency, ['basePrice' => 100.0, 'baseQuantity' => 100]);
        $pse = $product->getDefaultSaleElements();
        self::assertNotNull($pse);

        return [$product, $pse];
    }

    private function secondCombination(Product $product): ProductSaleElements
    {
        $pse = $this->factory->productSaleElement($product, ['ref' => 'second-'.uniqid()]);
        $this->factory->productPrice($pse, Currency::getDefaultCurrency(), ['price' => '100', 'promoPrice' => '100']);

        return $pse;
    }

    private function panel(Product $product, Customer $customer): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', self::PANEL.'?product_id='.$product->getId().'&effective_customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    private function text(\Symfony\Component\DomCrawler\Crawler $crawler, string $testId): string
    {
        $node = $crawler->filter('[data-testid="'.$testId.'"]');
        self::assertCount(1, $node, $testId);

        return trim(preg_replace('/\s+/', ' ', $node->text()) ?? '');
    }

    private function memberOf(CustomerGroup $group): Customer
    {
        return $this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $group]);
    }

    private function priceList(string $code, int $priority = 100, bool $exclusive = false): PriceList
    {
        $list = (new PriceList())
            ->setCode($code)
            ->setCurrencyId(Currency::getDefaultCurrency()->getId())
            ->setPriority($priority)
            ->setExclusive($exclusive ? 1 : 0)
            ->setActive(1);
        $list->save($this->getPropelConnection());

        return $list;
    }

    private function item(PriceList $list, ProductSaleElements $pse, string $price, int $minQuantity = 1): void
    {
        (new PriceListItem())
            ->setPriceListId($list->getId())
            ->setProductSaleElementsId($pse->getId())
            ->setMinQuantity($minQuantity)
            ->setPrice($price)
            ->save($this->getPropelConnection());
    }

    private function assignToGroup(PriceList $list, CustomerGroup $group): void
    {
        (new PriceListAssignment())->setPriceListId($list->getId())->setCustomerGroupId($group->getId())->save($this->getPropelConnection());
    }

    private function assignToCustomer(PriceList $list, Customer $customer): void
    {
        (new PriceListAssignment())->setPriceListId($list->getId())->setCustomerId($customer->getId())->save($this->getPropelConnection());
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function restrictedAdmin(array $grants): \Thelia\Model\Admin
    {
        $admin = $this->factory->restrictedAdmin($grants);
        $admin->eraseCredentials();

        return $admin;
    }
}
