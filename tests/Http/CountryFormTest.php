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
use Thelia\Model\ConfigQuery;
use Thelia\Model\CountryQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The country form offers what a save changes. The zones of a country are managed from the
 * shipping zones, and the form once carried a zone field that nothing read.
 */
final class CountryFormTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
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

    public function testNeitherTheCreationNorTheEditionOfACountryOffersAZoneField(): void
    {
        $country = CountryQuery::create()->findOne();
        self::assertNotNull($country);

        $edit = $this->client->request('GET', '/admin/configuration/countries/update/'.$country->getId());
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $edit->filter('input[name="thelia_country_update[isoalpha2]"]'), 'The form of the edition is there.');
        self::assertCount(0, $edit->filter('[name="thelia_country_update[area]"]'));

        $list = $this->client->request('GET', '/admin/configuration/countries');
        self::assertResponseStatusCodeSame(200);
        self::assertGreaterThan(0, $list->filter('input[name$="[isoalpha2]"]')->count(), 'The creation form is there.');
        self::assertCount(0, $list->filter('[name$="[area]"]'));
    }

    public function testTheEditionOfACountryStillSaves(): void
    {
        $country = CountryQuery::create()->findOne();
        self::assertNotNull($country);
        $crawler = $this->client->request('GET', '/admin/configuration/countries/update/'.$country->getId());
        $form = $crawler->filter('form')->reduce(static fn ($node) => $node->filter('input[name="thelia_country_update[isoalpha2]"]')->count() > 0)->form();

        $this->client->submit($form);

        self::assertResponseRedirects();
    }
}
