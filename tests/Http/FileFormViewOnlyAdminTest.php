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
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The images and documents tabs are sub-requests of the sheet of their parent: an administrator
 * who may only view the parent reads the sheet, without the upload form.
 */
final class FileFormViewOnlyAdminTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testAViewOnlyAdministratorReadsTheProductSheetWithoutTheUploadForms(): void
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), \Thelia\Model\Currency::getDefaultCurrency(), ['title' => 'A product']);
        $this->actAsViewOnlyAdministrator(AdminResources::PRODUCT);

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('[data-testid="bo-file-upload-form-image"]'), 'No image upload form.');
        self::assertCount(0, $crawler->filter('[data-testid="bo-file-upload-form-document"]'), 'No document upload form.');
        self::assertCount(2, $crawler->filter('#tab-images [data-bo-file-upload-target="list"], #tab-documents [data-bo-file-upload-target="list"]'), 'Both tabs still show their list.');
    }

    public function testTheUploadFormStaysForAnAdministratorWhoMayUpdate(): void
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), \Thelia\Model\Currency::getDefaultCurrency(), ['title' => 'A product']);
        $admin = $this->factory->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $crawler = $this->client->request('GET', '/admin/products/update?product_id='.$product->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid="bo-file-upload-form-image"]'));
        self::assertCount(1, $crawler->filter('[data-testid="bo-file-upload-form-document"]'));
    }

    private function actAsViewOnlyAdministrator(string $resource): void
    {
        $admin = $this->factory->restrictedAdmin([$resource => [AccessManager::VIEW]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }
}
