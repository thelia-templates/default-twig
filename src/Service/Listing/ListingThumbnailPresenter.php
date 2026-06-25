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

namespace BackOfficeDefaultTwigBundle\Service\Listing;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Action\Image as ImageAction;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\BrandImageQuery;
use Thelia\Model\CategoryImageQuery;
use Thelia\Model\ContentImageQuery;
use Thelia\Model\FolderImageQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductSaleElementsProductImageQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Builds the small image cell shown in back-office listings (product, category, brand, content, folder).
 * Returns ready-to-render HTML: either an <img> pointing at a cached thumbnail, or a neutral placeholder.
 */
final readonly class ListingThumbnailPresenter
{
    private const WIDTH = 48;
    private const HEIGHT = 48;

    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    public function forProduct(int $productId): string
    {
        $image = $this->resolveProductImage($productId);

        return $this->render($image?->getUploadDir(), $image?->getFile(), 'product');
    }

    public function forCategory(int $categoryId): string
    {
        $image = CategoryImageQuery::create()
            ->filterByCategoryId($categoryId)
            ->orderByPosition()
            ->findOne();

        return $this->render($image?->getUploadDir(), $image?->getFile(), 'category');
    }

    public function forBrand(int $brandId): string
    {
        $image = BrandImageQuery::create()
            ->filterByBrandId($brandId)
            ->orderByPosition()
            ->findOne();

        return $this->render($image?->getUploadDir(), $image?->getFile(), 'brand');
    }

    public function forContent(int $contentId): string
    {
        $image = ContentImageQuery::create()
            ->filterByContentId($contentId)
            ->orderByPosition()
            ->findOne();

        return $this->render($image?->getUploadDir(), $image?->getFile(), 'content');
    }

    public function forFolder(int $folderId): string
    {
        $image = FolderImageQuery::create()
            ->filterByFolderId($folderId)
            ->orderByPosition()
            ->findOne();

        return $this->render($image?->getUploadDir(), $image?->getFile(), 'folder');
    }

    /**
     * The default sale element's image when set, otherwise the first product image by position.
     * The PSE-to-image link lives in product_sale_elements_product_image, not on the product row.
     */
    private function resolveProductImage(int $productId): ?ProductImage
    {
        $defaultPse = ProductSaleElementsQuery::create()
            ->filterByProductId($productId)
            ->filterByIsDefault(true)
            ->findOne();

        if ($defaultPse !== null) {
            $link = ProductSaleElementsProductImageQuery::create()
                ->filterByProductSaleElementsId($defaultPse->getId())
                ->findOne();

            if ($link !== null) {
                $image = ProductImageQuery::create()->findPk($link->getProductImageId());
                if ($image !== null) {
                    return $image;
                }
            }
        }

        return ProductImageQuery::create()
            ->filterByProductId($productId)
            ->orderByPosition()
            ->findOne();
    }

    private function render(?string $uploadDir, ?string $file, string $cacheSubdirectory): string
    {
        if ($uploadDir === null || $file === null || $file === '') {
            return $this->placeholder();
        }

        $event = new ImageEvent();
        $event->setSourceFilepath($uploadDir.\DIRECTORY_SEPARATOR.$file);
        $event->setCacheSubdirectory($cacheSubdirectory);
        $event->setWidth(self::WIDTH);
        $event->setHeight(self::HEIGHT);
        $event->setResizeMode((string) ImageAction::EXACT_RATIO_WITH_BORDERS);

        try {
            $this->events->dispatch($event, TheliaEvents::IMAGE_PROCESS);
        } catch (\Throwable $exception) {
            Tlog::getInstance()->addError(\sprintf('Failed to build listing thumbnail: %s', $exception->getMessage()));

            return $this->placeholder();
        }

        $url = (string) $event->getFileUrl();
        if ($url === '') {
            return $this->placeholder();
        }

        return \sprintf(
            '<img src="%s" alt="" loading="lazy" class="rounded border" style="width:%dpx;height:%dpx;object-fit:cover;">',
            htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            self::WIDTH,
            self::HEIGHT,
        );
    }

    private function placeholder(): string
    {
        return \sprintf(
            '<span class="d-inline-flex align-items-center justify-content-center bg-light text-secondary rounded border" style="width:%dpx;height:%dpx;" aria-hidden="true"><i class="bi bi-image"></i></span>',
            self::WIDTH,
            self::HEIGHT,
        );
    }
}
