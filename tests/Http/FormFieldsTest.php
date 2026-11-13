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
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The fields of the tax and category forms say what they are: required when they are, hidden when
 * the admin has nothing to type.
 */
final class FormFieldsTest extends WebIntegrationTestCase
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

    public function testTheRequirementsOfATaxAreMarkedRequired(): void
    {
        $tax = $this->factory->tax();

        $crawler = $this->page('/admin/configuration/taxes/update/'.$tax->getId(), 'en_US');

        $rows = $crawler->filter('[data-testid="tax-requirements"] .tax-requirement[data-tax-type!=""]');
        self::assertGreaterThan(0, $rows->count());
        foreach ($rows as $row) {
            $row = new Crawler($row);
            self::assertCount(1, $row->filter('.bo-form-label__required'), 'A requirement of the tax type is mandatory.');
            self::assertCount(0, $row->filter('.bo-form-label__optional'), 'It is not labelled optional.');
        }
    }

    public function testTheCategoryCreationFormHidesTheParentAndStartsOnline(): void
    {
        $crawler = $this->page('/admin/categories', 'en_US');

        self::assertSame('hidden', $crawler->filter('input[name="thelia_category_creation[parent]"]')->attr('type'));
        self::assertNotNull($crawler->filter('input[name="thelia_category_creation[visible]"]')->attr('checked'), 'A category created in a hurry is online.');
    }

    private function page(string $path, string $locale): Crawler
    {
        $crawler = $this->client->request('GET', $path.'?lang='.$locale);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        return $crawler;
    }
}
