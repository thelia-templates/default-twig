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
use Thelia\Model\Area;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\CountryArea;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * One rule for the language of the back office: what the admin creates is written in the
 * language being edited, and every label of a list or a lookup reads in the language of
 * the interface, never in the default language of the shop.
 */
final class AdminLanguageRuleTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private Lang $defaultLang;

    private Lang $otherLang;

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
        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->defaultLang = Lang::getDefaultLanguage();
        $other = LangQuery::create()->filterByByDefault(0)->filterByActive(1)->orderByLocale()->findOne();
        self::assertNotNull($other, 'The test shop has a single active language.');
        $this->otherLang = $other;
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testACategoryIsCreatedInTheEditLanguageWhateverTheInterfaceLanguage(): void
    {
        $crawler = $this->client->request('GET', '/admin/categories?lang='.$this->defaultLang->getCode().'&edit_language_id='.$this->otherLang->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            $this->otherLang->getLocale(),
            $crawler->filter('input[name="thelia_category_creation[locale]"]')->attr('value'),
        );
    }

    public function testTheProductListReadsInTheLanguageOfTheInterface(): void
    {
        $this->productTitled('Default product title', 'Other product title');

        $this->client->request('GET', '/admin/products?lang='.$this->otherLang->getCode());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Other product title', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Default product title', (string) $this->client->getResponse()->getContent());
    }

    public function testTheModulePagesReadInTheLanguageOfTheInterface(): void
    {
        $module = $this->factory->paymentModule('I18nRuleModule');
        $module->setLocale($this->defaultLang->getLocale())->setTitle('Default module title')->save($this->getPropelConnection());
        $module->setLocale($this->otherLang->getLocale())->setTitle('Other module title')->save($this->getPropelConnection());

        $this->client->request('GET', '/admin/modules?lang='.$this->otherLang->getCode());
        self::assertStringContainsString('Other module title', (string) $this->client->getResponse()->getContent(), 'The module list.');

        $this->client->request('GET', '/admin/module/I18nRuleModule?lang='.$this->otherLang->getCode());
        self::assertStringContainsString('Other module title', (string) $this->client->getResponse()->getContent(), 'The module configuration page.');
        self::assertStringNotContainsString('Default module title', (string) $this->client->getResponse()->getContent());
    }

    public function testTheCurrencyListReadsInTheLanguageOfTheInterface(): void
    {
        $currency = $this->factory->currency(['code' => 'ZZZ']);
        $currency->setLocale($this->otherLang->getLocale())->setName('Other currency name')->save($this->getPropelConnection());
        // Last locale set stays on the pooled model: the page must not depend on it.
        $currency->setLocale($this->defaultLang->getLocale())->setName('Default currency name')->save($this->getPropelConnection());

        $this->client->request('GET', '/admin/configuration/currencies?lang='.$this->otherLang->getCode());

        self::assertStringContainsString('data-currency-name="Other currency name"', (string) $this->client->getResponse()->getContent());
    }

    public function testTheCurrencyListIsSortedInTheLanguageItIsReadIn(): void
    {
        $first = $this->factory->currency(['code' => 'ZZA']);
        $second = $this->factory->currency(['code' => 'ZZB']);
        foreach ([[$first, 'Zeta', 'Alpha'], [$second, 'Beta', 'Omega']] as [$currency, $defaultName, $otherName]) {
            $currency->setLocale($this->otherLang->getLocale())->setName($otherName)->save($this->getPropelConnection());
            $currency->setLocale($this->defaultLang->getLocale())->setName($defaultName)->save($this->getPropelConnection());
        }

        $this->client->request('GET', '/admin/configuration/currencies?order=name&direction=asc&lang='.$this->otherLang->getCode());
        $html = (string) $this->client->getResponse()->getContent();

        self::assertLessThan(strpos($html, 'data-currency-name="Omega"'), strpos($html, 'data-currency-name="Alpha"'), 'Alpha comes before Omega in the language of the page.');
    }

    public function testTheShippingZoneAndItsCountryFeedReadInTheLanguageOfTheInterface(): void
    {
        $country = $this->countryTitled('Default country title', 'Other country title');
        $area = (new Area())->setName('I18n rule zone');
        $area->save($this->getPropelConnection());
        (new CountryArea())->setAreaId($area->getId())->setCountryId($country->getId())->save($this->getPropelConnection());

        $this->client->request('GET', '/admin/configuration/shipping_configuration/update/'.$area->getId().'?lang='.$this->otherLang->getCode());
        self::assertStringContainsString('Other country title', (string) $this->client->getResponse()->getContent(), 'The zone page.');
        self::assertStringNotContainsString('Default country title', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/configuration/countries/data?lang='.$this->otherLang->getCode());
        self::assertStringContainsString('Other country title', (string) $this->client->getResponse()->getContent(), 'The country feed.');
        self::assertStringNotContainsString('Default country title', (string) $this->client->getResponse()->getContent());
    }

    private function productTitled(string $defaultTitle, string $otherTitle): void
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['title' => $defaultTitle, 'locale' => $this->defaultLang->getLocale()],
        );
        $product->setLocale($this->otherLang->getLocale())->setTitle($otherTitle)->save($this->getPropelConnection());
    }

    private function countryTitled(string $defaultTitle, string $otherTitle): Country
    {
        $country = $this->factory->country(['isocode' => '998', 'isoalpha2' => 'ZZ', 'isoalpha3' => 'ZZZ']);
        $country->setLocale($this->defaultLang->getLocale())->setTitle($defaultTitle)->save($this->getPropelConnection());
        $country->setLocale($this->otherLang->getLocale())->setTitle($otherTitle)->save($this->getPropelConnection());

        return $country;
    }
}
