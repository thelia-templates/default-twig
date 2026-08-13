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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Configuration\SystemInformationProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Twig\Environment;

#[Route('/admin/configuration/system-information', name: 'admin.configuration.system-information')]
final class SystemInformationController
{
    private const RESOURCE = AdminResources::ADVANCED_CONFIGURATION;
    /** Advanced configuration's legacy 'admin.cache' resource, reused so both sibling screens share one permission (System information has no Smarty predecessor of its own). */
    private const LEGACY_RESOURCE = 'admin.cache';
    private const RESOURCES = [self::RESOURCE, self::LEGACY_RESOURCE];

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly SystemInformationProvider $provider,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->checkAny(self::RESOURCES, AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/system-information/index.html.twig', [
            'systemInformation' => $this->provider->collect(),
        ]));
    }
}
