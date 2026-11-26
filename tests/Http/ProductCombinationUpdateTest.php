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

use BackOfficeDefaultTwigBundle\Tests\Support\CombinationGridFields;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\AttributeTemplate;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\ProductPriceTableMap;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The two forms of the combinations tab of a product sheet, over HTTP: the
 * table of combinations and the default pricing of a product without any.
 * Both only save through a POST that carries the token of the form, and a
 * price the form did not send keeps its stored value. A combination without any
 * price is shown with an empty one, and is not saved until one is typed: the
 * update of the core would otherwise store it at 0.
 */
final class ProductCombinationUpdateTest extends WebIntegrationTestCase
{
    private const COMBINATIONS_URL = '/admin/product/combinations/update';
    private const FORMER_DEFAULT_PRICE_URL = '/admin/product/default-price/update';

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

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        // Built without createFixtureFactory(), which would push a synthetic
        // main request the SecurityContext then reads its session from.
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector->clear();

        parent::tearDown();
    }

    public function testTheCombinationsCannotBeUpdatedThroughAGet(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $this->client->request('GET', self::COMBINATIONS_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'quantity' => [$pse->getId() => 0],
            'price' => [$pse->getId() => 0.01],
        ]);

        $this->assertNotSaved();
        $this->assertCombinationUntouched($pse);
    }

    public function testTheCombinationsCannotBeUpdatedWithoutTheFormToken(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $token = $this->tokenOf($product, 'combinations-form');

        $this->client->request('POST', self::COMBINATIONS_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'quantity' => [$pse->getId() => 0],
            'price' => [$pse->getId() => 0.01],
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::COMBINATIONS_URL.'?_token='.$token, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'quantity' => [$pse->getId() => 0],
            'price' => [$pse->getId() => 0.01],
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The token is read from the form body only.');

        $this->assertCombinationUntouched($pse);
    }

    public function testTheCombinationsFormSavesAndKeepsAPriceItDidNotSend(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'product_sale_element_id' => [$pse->getId()],
            'reference' => [$pse->getId() => $pse->getRef()],
            'quantity' => [$pse->getId() => 42],
            'weight' => [$pse->getId() => 1.5],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(42.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'The submitted stock is saved.');
        $price = $this->storedPrice($pse);
        self::assertSame(25.0, (float) $price->getPrice(), 'A price the form did not send keeps its stored value.');
        self::assertSame(20.0, (float) $price->getPromoPrice(), 'A sale price the form did not send keeps its stored value.');
    }

    public function testTheTaxRuleOfAProductWithCombinationsCanBeChangedFromTheGrid(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $otherRule = $this->factory->taxRule(['isDefault' => false]);
        self::assertNotSame($product->getTaxRuleId(), $otherRule->getId());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        $select = $crawler->filter('form[data-testid="combinations-form"] select[name="tax_rule"]');
        self::assertSame(1, $select->count(), 'The grid form offers a choice of the tax rule.');
        self::assertSame(
            (string) $product->getTaxRuleId(),
            $select->filter('option[selected]')->attr('value'),
            'The current tax rule of the product is selected.',
        );
        self::assertSame(1, $select->filter(\sprintf('option[value="%d"]', $otherRule->getId()))->count());

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $otherRule->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'reference' => [$pse->getId() => $pse->getRef()],
            'quantity' => [$pse->getId() => 7],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        ProductTableMap::clearInstancePool();
        self::assertSame(
            $otherRule->getId(),
            ProductQuery::create()->findPk($product->getId(), $this->getPropelConnection())?->getTaxRuleId(),
        );
    }

    public function testTheTaxRuleChosenInTheGridIsSavedWhenEveryRowIsLeftUnpriced(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithACombination();
        $unpriced = $this->unpricedCombinationOf($product);
        $otherRule = $this->factory->taxRule(['isDefault' => false]);

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $otherRule->getId(),
            'product_sale_element_id' => [$unpriced->getId()],
            'reference' => [$unpriced->getId() => $unpriced->getRef()],
            'quantity' => [$unpriced->getId() => 9],
            'price' => [$unpriced->getId() => ''],
            'sale_price' => [$unpriced->getId() => ''],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        ProductTableMap::clearInstancePool();
        self::assertSame(
            $otherRule->getId(),
            ProductQuery::create()->findPk($product->getId(), $this->getPropelConnection())?->getTaxRuleId(),
            'A grid whose rows are all left unpriced still saves the tax rule.',
        );
    }

    public function testTheGridSavesTheAmountsInTheCurrencyItDisplays(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $dollar = $this->factory->currency(['code' => 'USD', 'symbol' => '$', 'rate' => 1.1, 'byDefault' => 0]);

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'currency' => $dollar->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'reference' => [$pse->getId() => $pse->getRef()],
            'quantity' => [$pse->getId() => 42],
            'price' => [$pse->getId() => '30'],
            'sale_price' => [$pse->getId() => '0'],
            'weight' => [$pse->getId() => 1.5],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(25.0, (float) $this->storedPrice($pse)->getPrice(), 'The price of the default currency is not overwritten.');
        ProductPriceTableMap::clearInstancePool();
        $dollarPrice = ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($dollar->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($dollarPrice, 'The price is saved in the currency of the grid.');
        self::assertSame(30.0, (float) $dollarPrice->getPrice());
    }

    public function testAnEmptiedPriceKeepsTheStoredOneInsteadOfSavingZero(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'product_sale_element_id' => [$pse->getId()],
            'reference' => [$pse->getId() => $pse->getRef()],
            'quantity' => [$pse->getId() => 42],
            'price' => [$pse->getId() => ''],
            'sale_price' => [$pse->getId() => ''],
            'weight' => [$pse->getId() => 1.5],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(42.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'The rest of the row is saved.');
        $price = $this->storedPrice($pse);
        self::assertSame(25.0, (float) $price->getPrice(), 'An emptied price keeps its stored value.');
        self::assertSame(20.0, (float) $price->getPromoPrice(), 'An emptied sale price keeps its stored value.');
    }

    public function testAZeroTypedInThePriceIsSaved(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'product_sale_element_id' => [$pse->getId()],
            'reference' => [$pse->getId() => $pse->getRef()],
            'quantity' => [$pse->getId() => 42],
            'price' => [$pse->getId() => '0'],
            'sale_price' => [$pse->getId() => '0'],
            'weight' => [$pse->getId() => 1.5],
        ]);

        self::assertSame(0.0, (float) $this->storedPrice($pse)->getPrice(), 'A price of 0 is saved when 0 is typed.');
    }

    public function testTheGridHidesACombinationWhoseVisibilityBoxIsUnticked(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $pse->setVisible(1)->save($this->getPropelConnection());

        $this->client->request('POST', self::COMBINATIONS_URL, $this->gridOf($product, $pse) + ['visible_sent' => 1]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertFalse((bool) $this->freshSaleElement($pse)->getVisible(), 'A box left unticked hides the combination.');
    }

    public function testTheGridShowsACombinationWhoseVisibilityBoxIsTicked(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $pse->setVisible(0)->save($this->getPropelConnection());

        $this->client->request('POST', self::COMBINATIONS_URL, $this->gridOf($product, $pse) + ['visible_sent' => 1, 'visible' => [$pse->getId() => 1]]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertTrue((bool) $this->freshSaleElement($pse)->getVisible(), 'A ticked box shows the combination.');
    }

    public function testAFormWithoutTheVisibilityBoxesLeavesTheVisibilityAsItIs(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $pse->setVisible(1)->save($this->getPropelConnection());

        // A copy of the grid made before the boxes existed sends none of them.
        $this->client->request('POST', self::COMBINATIONS_URL, $this->gridOf($product, $pse));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertTrue((bool) $this->freshSaleElement($pse)->getVisible(), 'Without the marker of the boxes, nothing is hidden.');
    }

    public function testTheGridRendersTheVisibilityOfEachCombinationAsABoxOfItsRow(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $pse->setVisible(0)->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        $form = $crawler->filter('form[data-testid="combinations-form"]');
        self::assertCount(1, $form->filter('input[name="visible_sent"]'), 'The grid says it sends its visibility boxes.');
        $box = $form->filter(\sprintf('tbody[data-testid="combinations-row-%d"] input[type="checkbox"][name="visible[%d]"]', $pse->getId(), $pse->getId()));
        self::assertCount(1, $box, 'The visibility is a box of the row, named by its combination like every field.');
        self::assertNull($box->attr('checked'), 'A hidden combination shows its box unticked.');
    }

    public function testTheGridIsWatchedBeforeLeavingAndOffersToCancelItsChanges(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithACombination();

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId().'&current_tab=pse');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $page = $crawler->filter('[data-testid="product-edit-page"]');
        self::assertSame('bo-unsaved-changes', $page->attr('data-controller'));
        self::assertSame('true', $page->attr('data-bo-unsaved-changes-warn-on-unload-value'), 'Leaving the page any other way asks too.');
        self::assertCount(1, $page->filter('form[data-testid="combinations-form"][data-bo-unsaved-changes-target="form"]'), 'The grid is one of the forms the page watches.');

        $bar = $crawler->filter('form[data-testid="combinations-form"] [data-testid="combinations-savebar"]');
        self::assertCount(1, $bar->filter('button[type="submit"][data-testid="combinations-submit"]'));
        self::assertStringContainsString('Save changes', $bar->filter('[data-testid="combinations-submit"]')->text(), 'The bar saves the changes, the only thing it shows up for.');
        self::assertCount(1, $bar->filter('button[type="reset"][data-testid="combinations-discard"]'), 'The changes of the grid can be cancelled from its save bar.');
    }

    public function testAFieldOfTheBulkPanelRefusesWhatTheFieldOfTheLineRefuses(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        $row = $crawler->filter(\sprintf('tbody[data-testid="combinations-row-%d"]', $pse->getId()));
        $panel = $crawler->filter('[data-testid="combinations-bulk-panel"]');
        $compared = 0;
        foreach ($row->filter('input[data-bulk-field]') as $line) {
            $name = $line->getAttribute('data-bulk-field');
            $bulk = $panel->filter(\sprintf('input[data-bulk-field="%s"]', $name));
            if (0 === $bulk->count()) {
                continue;
            }
            // A bulk value the line refuses would leave a folded field invalid: the form then refuses to send, silently.
            foreach (['min', 'max'] as $constraint) {
                self::assertSame($line->getAttribute($constraint), (string) $bulk->attr($constraint), $name.': '.$constraint);
            }
            ++$compared;
        }
        self::assertGreaterThan(5, $compared);
    }

    public function testABulkEditModePutsABoxInPlaceOfEachHandleAndOpensAPanelThatIsNeverSent(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId().'&current_tab=pse');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $area = $crawler->filter('[data-testid="product-combinations-section"][data-controller~="bo-bulk-select"][data-controller~="bo-combinations-bulk-edit"]');
        self::assertCount(1, $area, 'The card holds the button, the boxes and the panel.');
        self::assertSame("form[data-testid='combinations-form']", $area->attr('data-bo-combinations-bulk-edit-bo-pse-price-with-tax-outlet'), 'The panel converts its prices with the controller of the grid.');
        self::assertSame('bo-combinations-card--bulk', $area->attr('data-bo-combinations-bulk-edit-active-class'));
        self::assertSame('bo-side-panel--folded', $area->attr('data-bo-combinations-bulk-edit-folded-class'));

        $toggle = $area->filter('.card-header [data-testid="combinations-bulk-toggle"]');
        self::assertSame('bo-combinations-bulk-edit#toggle', $toggle->attr('data-action'), 'A button turns the mode on.');
        self::assertSame('combinations-bulk-panel', $toggle->attr('aria-controls'));
        self::assertSame('false', $toggle->attr('aria-expanded'));
        self::assertStringContainsString('Bulk edit', $toggle->filter('.bo-combinations-bulk-toggle__enter')->text());
        self::assertStringContainsString('Exit bulk edit', $toggle->filter('.bo-combinations-bulk-toggle__exit')->text(), 'In the mode, the button says it leaves it.');

        $grid = $crawler->filter('form[data-testid="combinations-form"]');
        self::assertSame('sortable', $grid->filter('table[data-controller="bo-sortable"]')->attr('data-bo-combinations-bulk-edit-target'), 'The mode stops the dragging of the combinations.');
        $all = $grid->filter('thead th.bo-combinations-grid__col-handle [data-testid="combinations-select-all"]');
        self::assertSame('combinations-bulk-edit', $all->attr('form'));
        $box = $grid->filter(\sprintf('tbody[data-testid="combinations-row-%d"] td.bo-combinations-grid__handle [data-testid="combinations-select-%d"]', $pse->getId(), $pse->getId()));
        self::assertCount(1, $box, 'The box of a combination is in its handle.');
        self::assertSame('combinations-bulk-edit', $box->attr('form'), 'A selection box is neither sent nor reset with the grid.');
        self::assertNull($box->attr('name'));
        self::assertSame('off', $box->attr('autocomplete'), 'A reload does not tick again a box of a mode that is off.');
        self::assertSame('off', $all->attr('autocomplete'));
        self::assertSame((string) $pse->getId(), $box->attr('value'));

        $panel = $area->filter('dialog#combinations-bulk-panel[data-testid="combinations-bulk-panel"]');
        self::assertCount(1, $panel);
        self::assertNull($panel->attr('open'), 'The panel waits for the button.');
        self::assertSame('close->bo-combinations-bulk-edit#closed keydown.esc->bo-combinations-bulk-edit#close', $panel->attr('data-action'), 'Closing the panel, Escape included, ends the mode.');
        self::assertCount(0, $crawler->filter('form[data-testid="combinations-form"] dialog, form[data-testid="combinations-form"] form'), 'The panel and its form are not nested in the grid.');

        $notch = $panel->filter('button[data-testid="combinations-bulk-notch"]');
        self::assertSame('bo-combinations-bulk-edit#fold', $notch->attr('data-action'), 'A notch slides the panel aside and back.');
        self::assertSame('true', $notch->attr('aria-expanded'));
        self::assertSame('combinations-bulk-panel', $notch->attr('aria-controls'));

        $bulkForm = $panel->filter('form#combinations-bulk-edit');
        self::assertSame('submit->bo-combinations-bulk-edit#apply', $bulkForm->attr('data-action'), 'Enter in a field of the panel applies it.');
        $apply = $bulkForm->filter('.bo-side-panel__header button[type="submit"][data-testid="combinations-bulk-apply"]');
        self::assertCount(1, $apply, 'The buttons of the panel are on top of it.');
        self::assertSame('Bulk edit', $panel->filter('#combinations-bulk-panel-title')->text());
        self::assertNotNull($apply->attr('disabled'), 'Nothing is ticked yet.');
        self::assertSame('bo-combinations-bulk-edit#close', $bulkForm->filter('.bo-side-panel__header [data-testid="combinations-bulk-close"]')->attr('data-action'));

        $fields = $bulkForm->filter('[data-bo-combinations-bulk-edit-target="field"]');
        $names = $fields->each(static fn (Crawler $field): string => (string) $field->attr('data-bulk-field'));
        self::assertSame([
            'price', 'price-incl', 'onsale', 'sale-price', 'sale-price-incl',
            'quantity', 'weight',
            'policy', 'preorder-limit', 'threshold', 'restock-date',
            'unit', 'unit-quantity', 'decimals', 'min-quantity', 'quantity-step', 'reference-quantity',
            'visible', 'isnew', 'brand',
        ], $names, 'The panel fills the stock settings and the quantities and units too, grouped as in the fold.');
        self::assertCount(5, $bulkForm->filter('fieldset.bo-side-panel__section'));
        $fields->each(static function (Crawler $field): void {
            self::assertNull($field->attr('form'), 'A field of the panel belongs to the form of the panel.');
            self::assertNull($field->attr('name'), 'A field of the panel is never sent.');
        });

        $row = $grid->filter(\sprintf('tbody[data-testid="combinations-row-%d"]', $pse->getId()));
        foreach ($names as $name) {
            $target = $row->filter(\sprintf('[data-bulk-field="%s"]', $name));
            self::assertCount(1, $target, 'The row has the field the panel fills: '.$name);
            self::assertNull($target->attr('form'), 'The field of the row is sent with the grid: '.$name);
        }
        self::assertSame(\sprintf('manufacturer_brand_id[%d]', $pse->getId()), $row->filter('[data-bulk-field="brand"]')->attr('name'));
        self::assertSame(
            $row->filter('[data-bulk-field="brand"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')),
            \array_slice($bulkForm->filter('[data-bulk-field="brand"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')), 1),
            'The panel offers the brands of a row, after "Unchanged".',
        );
        $units = $bulkForm->filter('[data-bulk-field="unit"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertSame(['', '__none'], \array_slice($units, 0, 2), '"Unchanged" is empty, "No unit" has a value of its own.');
        self::assertSame(
            \array_slice($row->filter('[data-bulk-field="unit"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')), 1),
            \array_slice($units, 2),
            'Then the units of a row.',
        );
        self::assertStringContainsString("NONE ? ''", (string) file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/bo-combinations-bulk-edit_controller.js'), '"No unit" empties the unit of the rows.');
    }

    public function testTheLeavingModalCanSayThatSeveralTabsAreToBeSavedOneByOne(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithACombination();

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId().'&current_tab=pse');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $modal = $crawler->filter('[data-testid="product-edit-page"] [data-testid="product-unsaved-changes-modal"]');
        self::assertCount(1, $modal, 'The modal is inside the controller that drives it.');
        self::assertCount(1, $modal->filter('[data-testid="product-unsaved-save"][data-bo-unsaved-changes-target="saveButton"]'), 'The modal can hide its save button.');
        self::assertCount(1, $modal->filter('[data-bo-unsaved-changes-target="oneFormMessage"]'));
        $several = $modal->filter('[data-bo-unsaved-changes-target="severalFormsMessage"]');
        self::assertCount(1, $several);
        self::assertNotNull($several->attr('hidden'), 'Until several forms are changed, the modal offers to save the one that is.');
        self::assertStringContainsString('Save each tab first', $several->text());
    }

    public function testTheExchangeRateBoxSitsNextToTheCurrencyAndIsSentWithTheGrid(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithACombination();
        $other = $this->factory->currency(['code' => 'ZZC', 'symbol' => 'Z', 'rate' => 2.0]);

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId().'&currency_id='.$other->getId());

        $box = $crawler->filter('.card-header [data-testid="combinations-use-exchange-rate"]');
        self::assertCount(1, $box, 'In the header, next to the currency.');
        self::assertSame('combinations-form', $box->attr('form'), 'Sent with the grid, which it is not in.');
        self::assertSame('use_exchange_rate', $box->attr('name'));
        self::assertCount(1, $crawler->filter('form#combinations-form[data-testid="combinations-form"]'));
        self::assertCount(0, $crawler->filter('form[data-testid="combinations-form"] [name="use_exchange_rate"]'), 'No hidden 0 in the form: sent after the box, it would win over it.');

        $default = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(0, $default->filter('[data-testid="combinations-use-exchange-rate"]'), 'Nothing to convert in the default currency.');

        $guard = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/bo-unsaved-changes_controller.js');
        self::assertStringContainsString('outsideFieldChanged', $guard, 'A box given to the grid by form= marks it as changed.');
    }

    public function testTheHintOfAProductWithoutCombinationSitsBeforeTheButtonThatAddsOne(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithoutCombination();
        $template = $this->factory->template();
        $attribute = $this->factory->attribute();
        $this->factory->attributeAv($attribute);
        (new AttributeTemplate())->setTemplateId($template->getId())->setAttributeId($attribute->getId())->setPosition(1)->save($this->getPropelConnection());
        $product->setTemplateId($template->getId())->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertCount(1, $crawler->filter('[data-testid="combinations-toolbar"] [data-testid="combinations-empty-hint"]:not(.alert) + [data-testid="combination-create-btn"]'), 'Plain text, right before the button.');
    }

    public function testTheTaxRuleIsOneNarrowFieldAboveTheGrid(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithACombination();

        $form = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId())->filter('form[data-testid="combinations-form"]');

        self::assertCount(1, $form->filter('.bo-combinations-tax-rule > select[data-testid="combinations-tax-rule"]'));
        self::assertCount(0, $form->filter('.row [data-testid="combinations-tax-rule"]'));
    }

    public function testTheOtherPricesAreOneCardWithATabEachWhoseFormsAreWatched(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithACombination();

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        $block = $crawler->filter('[data-testid="other-prices-section"][data-controller="bo-other-prices"]');
        self::assertCount(1, $block, 'One card under the grid.');
        self::assertSame('Other prices', $block->filter('.card-header > h2.h5')->text(), 'A card title, as on the other cards of the back office.');
        self::assertCount(1, $block->filter('.card-header > h2 + ul.nav-tabs[data-testid="other-prices-tabs"]'), 'The tabs at the foot of the header, under the title.');
        $tabs = $block->filter('[data-testid="other-prices-tabs"] [role="tab"]');
        self::assertGreaterThanOrEqual(2, $tabs->count());
        self::assertSame(['true'], array_values(array_unique(array_filter($tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('aria-selected')), static fn (string $selected): bool => $selected === 'true'))), 'One tab is open.');
        self::assertSame('true', $tabs->first()->attr('aria-selected'), 'The first one, by default.');
        foreach (['display' => 'price-display-mode', 'effective' => 'effective-prices'] as $key => $section) {
            self::assertCount(1, $block->filter(\sprintf('[data-testid="other-prices-panel-%s"][role="tabpanel"] [data-testid="%s-section"]', $key, $section)), $key);
        }
        self::assertCount(0, $crawler->filter('.card [data-testid="price-display-mode-section"].card, .card[data-testid="effective-prices-section"]'), 'No card in the card.');
        foreach (['price-display-mode-form', 'group-prices-form', 'sale-unit-values-form'] as $form) {
            $node = $block->filter(\sprintf('form[data-testid="%s"]', $form));
            if ($node->count() > 0) {
                self::assertSame('form', $node->attr('data-bo-unsaved-changes-target'), 'Leaving the page with '.$form.' changed asks first.');
            }
        }
        self::assertSame('effective', $block->filter('form[data-testid="effective-prices-search-form"] input[name="other_prices_tab"]')->attr('value'), 'A search comes back to its tab.');

        $display = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId().'&other_prices_tab=display');
        self::assertSame('true', $display->filter('[data-testid="other-prices-tab-display"]')->attr('aria-selected'), 'The page opens the tab it is asked for.');
        self::assertStringContainsString('active', (string) $display->filter('[data-testid="other-prices-panel-display"]')->attr('class'));
    }

    public function testASaveOfTheDisplayOfThePricesComesBackToItsTab(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithACombination();
        $token = (string) $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId())
            ->filter('form[data-testid="price-display-mode-form"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/product/price-display-mode/save', ['_token' => $token, 'product_id' => $product->getId(), 'price_display_mode' => '']);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('other_prices_tab=display', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testOneButtonOpensTheFoldOfEveryCombination(): void
    {
        $this->loginFullAdmin();
        [$product] = $this->productWithACombination();
        [$simple] = $this->productWithoutCombination();

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertStringContainsString('bo-combination-folds', (string) $crawler->filter('[data-testid="product-combinations-section"]')->attr('data-controller'));
        $toggle = $crawler->filter('.card-header [data-testid="combinations-toolbar"] [data-testid="combinations-folds-toggle"]');
        self::assertCount(1, $toggle, 'In the toolbar of the grid.');
        self::assertSame('checkbox', $toggle->attr('type'), 'A switch.');
        self::assertSame('change->bo-combination-folds#toggleAll', $toggle->attr('data-action'));
        self::assertNull($toggle->attr('checked'), 'Every fold is closed at first.');

        $one = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$simple->getId());
        self::assertCount(0, $one->filter('[data-testid="combinations-folds-toggle"]'), 'A product without combination has one fold: nothing to open in bulk.');
    }

    public function testTheFormerAddressOfTheDefaultPriceIsGone(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();

        $this->client->request('POST', self::FORMER_DEFAULT_PRICE_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'quantity' => 43,
            'price' => 0.01,
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertCombinationUntouched($pse);
    }

    public function testAProductWithoutCombinationIsAGridOfOneLineSavedLikeTheGrid(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();
        $id = (int) $pse->getId();

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertCount(0, $crawler->filter('form[data-testid="default-pse-form"]'), 'No form of its own any more.');
        $form = $crawler->filter('form[data-testid="combinations-form"]');
        self::assertCount(1, $form);
        self::assertSame('/admin/product/combinations/update', $form->attr('action'));
        self::assertCount(1, $form->filter('tbody[data-row-id]'), 'One line: the default combination.');
        self::assertSame((string) $id, $form->filter('input[name="default_pse"][checked]')->attr('value'), 'It is sent as the default combination.');
        self::assertCount(0, $form->filter('input[name="visible_sent"]'), 'It has no visibility box, so it does not say it sends one.');
        self::assertCount(0, $form->filter(\sprintf('[name="visible[%d]"]', $id)));
        self::assertCount(0, $form->filter('[data-bo-sortable-handle], [data-controller~="bo-sortable"]'), 'Nothing to reorder.');
        self::assertCount(0, $form->filter('td.bo-combinations-grid__handle'), 'Nor a handle cell, which the style darkens on hover.');
        self::assertCount(0, $crawler->filter('[data-testid="combinations-bulk-toggle"], [data-testid="combinations-bulk-panel"]'), 'Nothing to edit in bulk.');
        self::assertCount(0, $form->filter(\sprintf('[data-testid="combinations-actions-%d"]', $id)));
        $reference = $form->filter(\sprintf('input[name="reference[%d]"]', $id));
        self::assertNotNull($reference->attr('readonly'), 'The reference follows the one of the product.');
        self::assertSame($pse->getRef(), $reference->attr('value'), 'Read-only, it is still sent.');
        self::assertSame('Follows the reference of the product', $form->filter(\sprintf('[data-testid="combinations-ref-hint-%d"][data-bs-toggle="tooltip"]', $id))->attr('title'), 'Said in a tooltip, the line keeps its height.');
        self::assertCount(1, $form->filter(\sprintf('tr#combination-%d-more [data-testid="combinations-fold-stock-%d"]', $id, $id)), 'It has the fold of a combination.');

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'default_pse' => $id,
        ] + CombinationGridFields::of([[$pse, ['reference' => $pse->getRef(), 'quantity' => 43, 'weight' => 1.5]]]));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $fresh = $this->freshSaleElement($pse);
        self::assertSame(43.0, (float) $fresh->getQuantity(), 'The submitted stock is saved.');
        self::assertTrue((bool) $fresh->getIsDefault(), 'It stays the default combination.');
        $price = $this->storedPrice($pse);
        self::assertSame(25.0, (float) $price->getPrice(), 'A price the grid did not send keeps its stored value.');
        self::assertSame(20.0, (float) $price->getPromoPrice(), 'A sale price the grid did not send keeps its stored value.');
    }

    public function testACombinationWithoutPriceShowsAnEmptyPriceThatMustBeFilled(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $unpriced = $this->unpricedCombinationOf($product);

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $unpricedInput = $crawler->filter(\sprintf('tbody[data-testid="combinations-row-%d"] input[name^="price["]', $unpriced->getId()));
        self::assertSame('', $unpricedInput->attr('value'), 'A combination without any price shows an empty price, not 0.');
        self::assertNotNull($unpricedInput->attr('required'), 'The browser asks for the missing price before submitting.');
        $pricedInput = $crawler->filter(\sprintf('tbody[data-testid="combinations-row-%d"] input[name^="price["]', $pse->getId()));
        self::assertSame('25', $pricedInput->attr('value'));
        self::assertNull($pricedInput->attr('required'), 'A priced combination is shown as before.');
    }

    public function testAnUnpricedCombinationSentWithoutAPriceIsLeftAsItIsAndTheOthersAreSaved(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $unpriced = $this->unpricedCombinationOf($product);

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'default_pse' => $pse->getId(),
            'product_sale_element_id' => [$pse->getId(), $unpriced->getId()],
            'reference' => [$pse->getId() => $pse->getRef(), $unpriced->getId() => $unpriced->getRef()],
            'quantity' => [$pse->getId() => 42, $unpriced->getId() => 9],
            'price' => [$pse->getId() => '25', $unpriced->getId() => ''],
            'sale_price' => [$pse->getId() => '20', $unpriced->getId() => ''],
            'weight' => [$pse->getId() => 1.5, $unpriced->getId() => 0],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->priceOf($unpriced), 'No price of 0 is written for a combination saved without a price.');
        self::assertSame(3.0, (float) $this->freshSaleElement($unpriced)->getQuantity(), 'The unpriced combination is left as it is.');
        self::assertSame(42.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'The other combinations are saved.');
        self::assertSame(25.0, (float) $this->storedPrice($pse)->getPrice());
        $this->client->followRedirect();
        self::assertStringContainsString(
            $unpriced->getRef(),
            $this->client->getCrawler()->filter('[data-testid="bo-flash-danger"]')->text(''),
            'The merchant is told which combination needs a price.',
        );
    }

    public function testAPriceEnteredForAnUnpricedCombinationIsSaved(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $unpriced = $this->unpricedCombinationOf($product);

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'default_pse' => $pse->getId(),
            'product_sale_element_id' => [$pse->getId(), $unpriced->getId()],
            'reference' => [$pse->getId() => $pse->getRef(), $unpriced->getId() => $unpriced->getRef()],
            'quantity' => [$pse->getId() => 42, $unpriced->getId() => 3],
            'price' => [$pse->getId() => '25', $unpriced->getId() => '12.5'],
            'sale_price' => [$pse->getId() => '20', $unpriced->getId() => ''],
            'weight' => [$pse->getId() => 1.5, $unpriced->getId() => 0],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(42.0, (float) $this->freshSaleElement($pse)->getQuantity());
        self::assertSame(25.0, (float) $this->storedPrice($pse)->getPrice());
        $price = $this->priceOf($unpriced);
        self::assertNotNull($price);
        self::assertSame(12.5, (float) $price->getPrice());
    }

    public function testAProductWithoutAnyPriceShowsAnEmptyPriceAndIsNotSavedAtZero(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();
        ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->delete($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertSame('', $crawler->filter(\sprintf('#combination-%d-price', $pse->getId()))->attr('value'), 'A product without any price shows an empty price, not 0.');

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'default_pse' => $pse->getId(),
        ] + CombinationGridFields::of([[$pse, ['reference' => $pse->getRef(), 'quantity' => 43, 'weight' => 1.5, 'price' => '', 'sale_price' => '']]]));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->priceOf($pse), 'No price of 0 is written for a product saved without a price.');
        self::assertSame(7.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'Nothing is saved.');
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
        $pse = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($pse);

        $price = ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($this->defaultCurrency()->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($price);
        $price->setPromoPrice('20.000000')->save($this->getPropelConnection());

        return [$product, $pse];
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithACombination(): array
    {
        [$product, $pse] = $this->productWithoutCombination();

        $attribute = $this->factory->attribute(['title' => 'Size']);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($attribute, ['title' => 'Large']));

        return [$product, $pse];
    }

    /**
     * A combination written without any price row, the way the API or an import
     * can create one.
     */
    private function unpricedCombinationOf(Product $product): ProductSaleElements
    {
        $pse = $this->factory->productSaleElement($product, ['quantity' => 3]);
        $attribute = $this->factory->attribute(['title' => 'Color']);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($attribute, ['title' => 'Red']));
        self::assertNull($this->priceOf($pse));

        return $pse;
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }

    /**
     * @return array<string, mixed> the fields the grid sends for its one combination, without its visibility
     */
    private function gridOf(Product $product, ProductSaleElements $pse): array
    {
        return [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'default_pse' => $pse->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'reference' => [$pse->getId() => $pse->getRef()],
            'quantity' => [$pse->getId() => 7],
            'weight' => [$pse->getId() => 0],
        ];
    }

    /**
     * The token the given form of the combinations tab renders in its body, so
     * the POST is submitted the way the browser submits it.
     */
    private function tokenOf(Product $product, string $formTestId): string
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        $token = $crawler->filter(\sprintf('form[data-testid="%s"] input[name="_token"]', $formTestId))->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('The "%s" form renders no token field.', $formTestId));

        return (string) $token->attr('value');
    }

    /**
     * A GET never reaches the save of the Twig back-office. When the legacy
     * back-office is installed next to it, its route of the same path answers
     * instead, and it only saves a POSTed form: the status then depends on how
     * that page renders, so the check is that nothing was saved.
     */
    private function assertNotSaved(): void
    {
        self::assertFalse(
            $this->client->getResponse()->isRedirection(),
            'A GET must not be answered with the redirection that follows a save.',
        );
    }

    private function assertCombinationUntouched(ProductSaleElements $pse): void
    {
        self::assertSame(7.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'The stock must not change.');
        self::assertSame(25.0, (float) $this->storedPrice($pse)->getPrice(), 'The price must not change.');
    }

    private function freshSaleElement(ProductSaleElements $pse): ProductSaleElements
    {
        ProductSaleElementsTableMap::clearInstancePool();
        $fresh = ProductSaleElementsQuery::create()->findPk($pse->getId(), $this->getPropelConnection());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function storedPrice(ProductSaleElements $pse): ProductPrice
    {
        $price = $this->priceOf($pse);
        self::assertNotNull($price);

        return $price;
    }

    private function priceOf(ProductSaleElements $pse): ?ProductPrice
    {
        ProductPriceTableMap::clearInstancePool();

        return ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($this->defaultCurrency()->getId())
            ->findOne($this->getPropelConnection());
    }
}
