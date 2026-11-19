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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProductTax;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The tax breakdown of the order sheet keeps every taxed line, including the old lines
 * that carry no tax rule title.
 */
final class OrderTaxBreakdownSheetTest extends WebIntegrationTestCase
{
    private const LOCALE = 'en_US';

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

        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin(['locale' => self::LOCALE]);
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

    public function testATaxedLineWithoutRuleTitleShowsUnderTheGenericLabel(): void
    {
        $order = $this->orderWithTaxedLine(null, null, '2.000000');

        $rows = $this->itemTaxRows($order);

        self::assertCount(1, $rows);
        self::assertStringContainsString('Taxes', $rows[0]);
        self::assertStringContainsString('2.00', $rows[0]);
    }

    public function testATaxedLineWithoutRuleTitleFallsBackOnTheRuleDescription(): void
    {
        $order = $this->orderWithTaxedLine(null, 'Reduced VAT', '2.000000');

        $rows = $this->itemTaxRows($order);

        self::assertCount(1, $rows);
        self::assertStringContainsString('Reduced VAT', $rows[0]);
        self::assertStringContainsString('2.00', $rows[0]);
    }

    public function testALineWithARuleTitleKeepsIt(): void
    {
        $order = $this->orderWithTaxedLine('French VAT', 'ignored', '2.000000');

        $rows = $this->itemTaxRows($order);

        self::assertCount(1, $rows);
        self::assertStringContainsString('French VAT', $rows[0]);
        self::assertStringNotContainsString('ignored', $rows[0]);
    }

    private function orderWithTaxedLine(?string $ruleTitle, ?string $ruleDescription, string $tax): Order
    {
        $order = $this->factory->order();
        $line = $this->factory->orderProduct($order, ['price' => '10.000000', 'promoPrice' => '10.000000']);
        $line->setTaxRuleTitle($ruleTitle)->setTaxRuleDescription($ruleDescription)->save($this->getPropelConnection());

        (new OrderProductTax())
            ->setOrderProductId($line->getId())
            ->setTitle('VAT')
            ->setAmount($tax)
            ->setPromoAmount($tax)
            ->save($this->getPropelConnection());

        return $order;
    }

    /**
     * @return list<string> the text of each line of the items taxes list
     */
    private function itemTaxRows(Order $order): array
    {
        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler->filter('[data-testid="order-taxes-items"] li')
            ->each(static fn (Crawler $row): string => (string) preg_replace('/\s+/', ' ', trim($row->text())));
    }
}
