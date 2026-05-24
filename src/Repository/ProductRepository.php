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
use Thelia\Model\Product;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\ProductQuery;

final readonly class ProductRepository
{
    /**
     * @return ObjectCollection<int, Product>
     */
    public function findInCategoryPage(int $categoryId, string $locale, int $offset, int $limit): ObjectCollection
    {
        $products = ProductQuery::create()
            ->useProductCategoryQuery()
                ->filterByCategoryId($categoryId)
                ->orderByPosition()
            ->endUse()
            ->offset($offset)
            ->limit($limit)
            ->find();

        foreach ($products as $product) {
            \assert($product instanceof Product);
            $product->setLocale($locale);
        }

        return $products;
    }

    public function countInCategory(int $categoryId): int
    {
        return ProductCategoryQuery::create()->filterByCategoryId($categoryId)->count();
    }
}
