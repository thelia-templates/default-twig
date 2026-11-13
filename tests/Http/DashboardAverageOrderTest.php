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

use Propel\Runtime\Propel;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The average order divides the revenue by the orders that make it: the paid ones. An unpaid
 * or a cancelled order lowers neither the revenue nor, now, the average.
 */
final class DashboardAverageOrderTest extends WebIntegrationTestCase
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

    public function testTheAverageOrderIgnoresTheOrdersThatAreNotPaid(): void
    {
        $connection = Propel::getConnection();
        // The orders of the demo are not of the period: the three below are the only ones.
        $connection->exec("UPDATE `order` SET created_at = '2000-01-01 10:00:00'");
        $factory = new FixtureFactory($this->getPropelConnection());
        $customer = $factory->customer($factory->customerTitle());
        $factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID, 'postage' => '100']);
        $factory->order($customer, ['statusCode' => OrderStatus::CODE_NOT_PAID, 'postage' => '50']);
        $factory->order($customer, ['statusCode' => OrderStatus::CODE_CANCELED, 'postage' => '70']);

        $crawler = $this->client->request('GET', '/admin?period=today');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('3', trim($crawler->filter('[data-testid="kpi-orders"] .h4')->text()), 'The three orders are counted.');
        self::assertStringContainsString('100.00', $crawler->filter('[data-testid="kpi-revenue"] .h4')->text());
        self::assertStringContainsString('100.00', $crawler->filter('[data-testid="kpi-aov"] .h4')->text(), 'One paid order of 100: the average order is 100.');
    }
}
