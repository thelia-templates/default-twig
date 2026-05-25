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

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\Brand;
use Thelia\Model\BrandImage;
use Thelia\Model\BrandImageQuery;
use Thelia\Model\BrandQuery;

final readonly class BrandRepository
{
    public function findById(int $brandId): ?Brand
    {
        return BrandQuery::create()->findPk($brandId);
    }

    /**
     * @return ObjectCollection<int, Brand>
     */
    public function findAllOrderedByPosition(): ObjectCollection
    {
        return BrandQuery::create()->orderByPosition()->find();
    }

    /**
     * @return ObjectCollection<int, BrandImage>
     */
    public function findImagesForBrand(int $brandId, string $locale): ObjectCollection
    {
        $images = BrandImageQuery::create()
            ->filterByBrandId($brandId)
            ->orderByPosition()
            ->find();

        foreach ($images as $image) {
            \assert($image instanceof BrandImage);
            $image->setLocale($locale);
        }

        return $images;
    }
}
