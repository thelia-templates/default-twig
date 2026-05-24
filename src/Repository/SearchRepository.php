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
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\FolderQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\ProductQuery;

final readonly class SearchRepository
{
    private const SEARCH_LIMIT = 10;

    public function findProducts(string $term): ObjectCollection
    {
        return ProductQuery::create()
            ->useProductI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();
    }

    public function findCategories(string $term): ObjectCollection
    {
        return CategoryQuery::create()
            ->useCategoryI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();
    }

    public function findFolders(string $term): ObjectCollection
    {
        return FolderQuery::create()
            ->useFolderI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();
    }

    public function findContents(string $term): ObjectCollection
    {
        return ContentQuery::create()
            ->useContentI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();
    }

    public function findBrands(string $term): ObjectCollection
    {
        return BrandQuery::create()
            ->useBrandI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();
    }

    public function findCustomers(string $term): ObjectCollection
    {
        return CustomerQuery::create()
            ->filterByFirstname('%'.$term.'%', Criteria::LIKE)
            ->_or()->filterByLastname('%'.$term.'%', Criteria::LIKE)
            ->_or()->filterByEmail('%'.$term.'%', Criteria::LIKE)
            ->_or()->filterByRef('%'.$term.'%', Criteria::LIKE)
            ->limit(self::SEARCH_LIMIT)
            ->find();
    }

    public function findOrders(string $term): ObjectCollection
    {
        return OrderQuery::create()
            ->filterByRef('%'.$term.'%', Criteria::LIKE)
            ->_or()->filterByInvoiceRef('%'.$term.'%', Criteria::LIKE)
            ->_or()->filterByTransactionRef('%'.$term.'%', Criteria::LIKE)
            ->limit(self::SEARCH_LIMIT)
            ->find();
    }
}
