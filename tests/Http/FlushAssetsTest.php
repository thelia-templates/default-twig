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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * "Flush assets" empties what Thelia copies again when a page needs it (the assets of the
 * modules and of the templates). The compiled assets of the store and of the back office are
 * not rebuilt by a page view: outside debug mode their urls answer 404 once they are gone.
 */
final class FlushAssetsTest extends WebIntegrationTestCase
{
    private const COPY = 'backOffice/default-twig/ZzFlushTestModule/style.css';
    private const COMPILED = 'zz-flush-test-compiled-1a2b3c.css';

    private AdminSessionInjector $injector;

    private string $assetsDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->assetsDir = rtrim(\defined('THELIA_WEB_DIR') ? THELIA_WEB_DIR : self::getContainer()->getParameter('kernel.project_dir').'/public', '/').'/assets/';
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

        if (isset($this->assetsDir)) {
            $fs = new Filesystem();
            $fs->remove([$this->assetsDir.self::COPY, $this->assetsDir.'backOffice/default-twig/ZzFlushTestModule', $this->assetsDir.self::COMPILED]);
        }

        parent::tearDown();
    }

    public function testFlushingTheAssetsKeepsTheCompiledOnesAndEmptiesTheCopies(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->assetsDir.self::COPY, 'a{}');
        $fs->dumpFile($this->assetsDir.self::COMPILED, 'a{}');

        $crawler = $this->client->request('GET', '/admin/configuration/advanced');
        $token = (string) $crawler->filter('meta[name="bo-token"]')->attr('content');
        $this->client->request('POST', '/admin/configuration/advanced/flush-assets', ['_token' => $token]);

        self::assertResponseRedirects();
        self::assertFileDoesNotExist($this->assetsDir.self::COPY, 'The copy of a module asset is gone: it is made again on display.');
        self::assertFileExists($this->assetsDir.self::COMPILED, 'The compiled assets stay: nothing rebuilds them on a production shop.');
    }

    public function testTheCardNoLongerTalksAboutWebpack(): void
    {
        $this->client->request('GET', '/admin/configuration/advanced');

        self::assertStringNotContainsString('Webpack', (string) $this->client->getResponse()->getContent());
    }
}
