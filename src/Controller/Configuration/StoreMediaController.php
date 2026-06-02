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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;

/**
 * Serves the store media (logo, banner, favicon) uploaded through the
 * `/admin/configuration/store` form. The files live under
 * `local/media/images/store/` which is not publicly exposed, so we proxy
 * them behind an admin route guarded by the STORE access voter.
 */
#[Route('/admin/store-media', name: 'admin.store-media.')]
final class StoreMediaController
{
    private const FIELD_TO_CONFIG = [
        'logo' => 'logo_file',
        'banner' => 'banner_file',
        'favicon' => 'favicon_file',
    ];

    public function __construct(private readonly AdminAccessChecker $access)
    {
    }

    #[Route('/{field}', name: 'show', methods: ['GET'], requirements: ['field' => 'logo|banner|favicon'])]
    public function show(string $field): Response
    {
        if ($denied = $this->access->check(AdminResources::STORE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $filename = ConfigQuery::read(self::FIELD_TO_CONFIG[$field]);
        if (!\is_string($filename) || $filename === '') {
            throw new NotFoundHttpException(\sprintf('No %s configured.', $field));
        }

        $absolutePath = $this->uploadDirectory().\DIRECTORY_SEPARATOR.$filename;
        if (!is_file($absolutePath)) {
            throw new NotFoundHttpException(\sprintf('Stored %s file is missing on disk.', $field));
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    private function uploadDirectory(): string
    {
        $configured = ConfigQuery::read('images_library_path');
        $base = \is_string($configured) && $configured !== ''
            ? THELIA_ROOT.$configured
            : THELIA_LOCAL_DIR.'media'.\DIRECTORY_SEPARATOR.'images';

        return $base.\DIRECTORY_SEPARATOR.'store';
    }
}
