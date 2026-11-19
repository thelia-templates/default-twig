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
 * The texts a Stimulus controller writes into the page come from the server in the
 * language of the interface: none is hard-coded in the script.
 */
final class JavascriptLabelsTest extends WebIntegrationTestCase
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
        if (LangQuery::create()->findOneByLocale('fr_FR') === null) {
            self::markTestSkipped('The test shop has no French language.');
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

    public function testTheTaxRuleMatrixPassesItsEmptyGroupTextInTheAdminLanguage(): void
    {
        $taxRule = $this->factory->taxRule();

        $html = $this->page('/admin/configuration/taxes_rules/update/'.$taxRule->getId());

        $crawler = new Crawler($html);
        $labels = json_decode($crawler->filter('[data-controller="bo-tax-rule-matrix"]')->attr('data-bo-tax-rule-matrix-labels-value') ?? '', true);

        self::assertSame('Déposer une taxe ici', $labels['add_to_group'] ?? null);
    }

    public function testTheShippingZonePassesItsEmptyTableTextInTheAdminLanguage(): void
    {
        $area = (new Area())->setName('Empty zone');
        $area->save($this->getPropelConnection());

        $html = $this->page('/admin/configuration/shipping_configuration/update/'.$area->getId());

        self::assertStringContainsString('data-bo-area-edit-empty-label-value="Cette zone de livraison ne contient aucun pays."', $html);
    }

    public function testTheFileListPassesItsDeleteConfirmationInTheAdminLanguage(): void
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());

        $html = $this->page('/admin/document/type/product/'.$product->getId().'/list-ajax');

        self::assertStringContainsString('data-bo-file-list-confirm-delete-value="Êtes-vous sûr ?"', $html);
    }

    public function testTheMailingTestPassesItsMissingAddressTextInTheAdminLanguage(): void
    {
        $html = $this->page('/admin/configuration/mailingSystem');

        self::assertStringContainsString('data-bo-mailing-system-test-email-required-label-value="Veuillez saisir une adresse e-mail"', $html);
    }

    private function page(string $path): string
    {
        $this->client->request('GET', $path.'?lang=fr_FR');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $this->client->getResponse()->getContent();
    }
}
