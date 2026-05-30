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

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use BackOfficeDefaultTwigBundle\Repository\BrandRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Action\Image as ImageAction;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;

final readonly class BrandImagePresenter
{
    private const CACHE_SUBDIR = 'brand';
    private const THUMBNAIL_WIDTH = 90;
    private const THUMBNAIL_HEIGHT = 90;
    private const PREVIEW_WIDTH = 300;
    private const PREVIEW_HEIGHT = 300;

    public function __construct(
        private BrandRepository $brands,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @return list<array{id: int, title: string, thumbnail_url: string, preview_url: string}>
     */
    public function listForBrand(int $brandId, string $locale): array
    {
        $images = $this->brands->findImagesForBrand($brandId, $locale);

        $baseSourcePath = $this->resolveBaseSourcePath();
        $items = [];

        foreach ($images as $image) {
            $sourceFile = \sprintf('%s/%s/%s', $baseSourcePath, self::CACHE_SUBDIR, (string) $image->getFile());

            $items[] = [
                'id' => (int) $image->getId(),
                'title' => (string) $image->getTitle(),
                'thumbnail_url' => $this->processedUrl($sourceFile, self::THUMBNAIL_WIDTH, self::THUMBNAIL_HEIGHT),
                'preview_url' => $this->processedUrl($sourceFile, self::PREVIEW_WIDTH, self::PREVIEW_HEIGHT),
            ];
        }

        return $items;
    }

    private function processedUrl(string $sourceFile, int $width, int $height): string
    {
        $event = new ImageEvent();
        $event->setSourceFilepath($sourceFile);
        $event->setCacheSubdirectory(self::CACHE_SUBDIR);
        $event->setWidth($width);
        $event->setHeight($height);
        $event->setResizeMode((string) ImageAction::EXACT_RATIO_WITH_BORDERS);

        try {
            $this->events->dispatch($event, TheliaEvents::IMAGE_PROCESS);
        } catch (\Throwable $exception) {
            Tlog::getInstance()->addError(\sprintf('Failed to process brand image: %s', $exception->getMessage()));

            return '';
        }

        return (string) $event->getFileUrl();
    }

    private function resolveBaseSourcePath(): string
    {
        $configured = ConfigQuery::read('images_library_path');
        if ($configured === null || $configured === '') {
            return THELIA_LOCAL_DIR.'media'.\DIRECTORY_SEPARATOR.'images';
        }

        return THELIA_ROOT.$configured;
    }
}
