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

use Propel\Runtime\ActiveQuery\Criteria;
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
        /** @var ObjectCollection<int, Brand> $result */
        $result = BrandQuery::create()->orderByPosition()->find();

        return $result;
    }

    /**
     * @return ObjectCollection<int, Brand>
     */
    public function findAllSorted(string $field, string $direction, string $locale): ObjectCollection
    {
        $criteria = strtoupper($direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = BrandQuery::create();

        match ($field) {
            'id' => $query->orderById($criteria),
            'visible' => $query->orderByVisible($criteria),
            'position' => $query->orderByPosition($criteria),
            'title' => $query
                ->useBrandI18nQuery(null, Criteria::LEFT_JOIN)
                    ->filterByLocale($locale)
                    ->orderByTitle($criteria)
                ->endUse(),
            default => $query->orderByPosition($criteria),
        };

        /** @var ObjectCollection<int, Brand> $result */
        $result = $query->find();

        return $result;
    }

    /**
     * @return ObjectCollection<int, BrandImage>
     */
    public function findImagesForBrand(int $brandId, string $locale): ObjectCollection
    {
        /** @var ObjectCollection<int, BrandImage> $images */
        $images = BrandImageQuery::create()
            ->filterByBrandId($brandId)
            ->orderByPosition()
            ->find();

        foreach ($images as $image) {
            $image->setLocale($locale);
        }

        return $images;
    }

    /** @return array{previous: ?int, next: ?int} */
    public function findPreviousNext(Brand $current): array
    {
        $previous = BrandQuery::create()
            ->filterByPosition($current->getPosition(), Criteria::LESS_THAN)
            ->orderByPosition(Criteria::DESC)
            ->findOne();
        $next = BrandQuery::create()
            ->filterByPosition($current->getPosition(), Criteria::GREATER_THAN)
            ->orderByPosition(Criteria::ASC)
            ->findOne();

        return [
            'previous' => $previous !== null ? (int) $previous->getId() : null,
            'next' => $next !== null ? (int) $next->getId() : null,
        ];
    }
}
