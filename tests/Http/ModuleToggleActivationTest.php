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

use BackOfficeDefaultTwigBundle\BackOfficeDefaultTwigBundle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Module\ModuleToggleActivationEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The switch of the module list asks the core for its checks (a module another one requires,
 * a mandatory module), and the bulk actions of the list send the token like the switch does.
 */
final class ModuleToggleActivationTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    /** @var list<callable> */
    private array $listeners = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
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
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        foreach ($this->listeners as $listener) {
            $dispatcher->removeListener(TheliaEvents::MODULE_TOGGLE_ACTIVATION, $listener);
        }

        if (isset($this->injector)) {
            $dispatcher->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheSwitchAsksTheCoreForItsDependencyChecks(): void
    {
        $module = ModuleQuery::create()->findOne($this->getPropelConnection());
        self::assertNotNull($module, 'The test shop has a module.');

        $crawler = $this->client->request('GET', '/admin/modules');
        $token = (string) $crawler->filter('meta[name="bo-token"]')->attr('content');

        $seen = [];
        $listener = static function (ModuleToggleActivationEvent $event) use (&$seen): void {
            $seen[] = $event->isNoCheck();
            // The module stays as it is: only the way the event is built matters here.
            $event->stopPropagation();
        };
        $this->listeners[] = $listener;
        $this->getService(EventDispatcherInterface::class)->addListener(TheliaEvents::MODULE_TOGGLE_ACTIVATION, $listener, 1000);

        $this->client->request('POST', '/admin/module/toggle-activation/'.$module->getId(), ['_token' => $token]);

        self::assertSame([false], $seen, 'The event was dispatched once, with the checks on.');
    }

    public function testTheBulkActionsOfTheListPostTheTokenLikeTheSwitch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../assets/controllers/bo-modules-list_controller.js');

        self::assertMatchesRegularExpression('/import\s*\{[^}]*\btokenBody\b[^}]*\}\s*from\s*[\'"]\.\.\/lib\/post-request\.js[\'"]/', $source);
        self::assertMatchesRegularExpression('/fetch\(box\.dataset\.toggleUrl,[^;]*?body:\s*tokenBody\(\)/s', $source, 'Each toggle request carries the token in its body.');
    }
}
