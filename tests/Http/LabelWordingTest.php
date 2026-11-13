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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Area;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The wording of a few labels, read in the HTML of the page the way an admin reads it.
 */
final class LabelWordingTest extends WebIntegrationTestCase
{
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
        foreach (['fr_FR', 'en_US'] as $locale) {
            if (LangQuery::create()->findOneByLocale($locale) === null) {
                self::markTestSkipped('The test shop lacks the language '.$locale.'.');
            }
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

    public function testTheDeliverySlipButtonsAreNamedAfterTheDeliverySlip(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));

        foreach (['fr_FR' => ['Voir le bon de livraison en PDF', 'Imprimer le bon de livraison', 'Télécharger le bon de livraison en PDF'], 'en_US' => ['View delivery slip as PDF', 'Print delivery slip', 'Download delivery slip as PDF']] as $locale => $titles) {
            $crawler = $this->page('/admin/order/update/'.$order->getId(), $locale);

            self::assertSame($titles[0], $crawler->filter('[data-testid="order-delivery-link"]')->attr('title'), $locale);
            self::assertSame($titles[1], $crawler->filter('[data-testid="order-delivery-print"]')->attr('title'), $locale);
            self::assertSame($titles[2], $crawler->filter('[data-testid="order-delivery-download"]')->attr('title'), $locale);
        }
    }

    public function testTheStatusColumnIsSpelledWithItsAccentInFrench(): void
    {
        $text = $this->page('/admin/configuration/webhooks', 'fr_FR')->filter('table')->text();

        self::assertStringContainsString('État', $text);
        self::assertStringNotContainsString('Etat', $text);
    }

    public function testTheZoneButtonSaysSelectionnerWithItsAccent(): void
    {
        $area = (new Area())->setName('Accent zone');
        $area->save($this->getPropelConnection());

        $text = $this->page('/admin/configuration/shipping_configuration/update/'.$area->getId(), 'fr_FR')->text();

        self::assertStringContainsString('Sélectionner les pays sans zone', $text);
    }

    public function testTheStoreBreadcrumbReadsLikeTheCardThatLeadsThere(): void
    {
        $breadcrumb = $this->page('/admin/configuration/store', 'fr_FR')->filter('.breadcrumb-item.active')->text();
        $card = $this->page('/admin/configuration', 'fr_FR')->filter('a[href$="/admin/configuration/store"]')->first()->text();

        self::assertSame('Informations de la boutique', $breadcrumb);
        self::assertStringContainsString($breadcrumb, $card);
    }

    public function testTheRevenueChartSubtitleAvoidsTheFrenchAbbreviationInEnglish(): void
    {
        $english = $this->page('/admin/home', 'en_US')->text();
        $french = $this->page('/admin/home', 'fr_FR')->text();

        self::assertStringContainsString('Daily, incl. tax', $english);
        self::assertStringNotContainsString('TTC', $english);
        self::assertStringContainsString('Journalier, TTC', $french);
    }

    public function testTheLanguageColumnNamesTheCodeTheFormAsksFor(): void
    {
        self::assertStringContainsString('ISO 639-1 Code', $this->page('/admin/configuration/languages', 'en_US')->filter('table')->text());
        self::assertStringContainsString('Code ISO 639-1', $this->page('/admin/configuration/languages', 'fr_FR')->filter('table')->text());
    }

    private function page(string $path, string $locale): Crawler
    {
        $crawler = $this->client->request('GET', $path.'?lang='.$locale);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        return $crawler;
    }
}
