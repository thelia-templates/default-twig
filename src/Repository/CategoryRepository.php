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
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;

final readonly class CategoryRepository
{
    /**
     * @return ObjectCollection<int, Category>
     */
    public function findByDefaultTemplateId(int $templateId): ObjectCollection
    {
        return CategoryQuery::create()
            ->filterByDefaultTemplateId($templateId)
            ->orderByPosition()
            ->find();
    }

    /**
     * @return ObjectCollection<int, Category>
     */
    public function findChildrenOrderedByPosition(int $parentId, string $locale): ObjectCollection
    {
        $categories = CategoryQuery::create()
            ->filterByParent($parentId)
            ->orderByPosition()
            ->find();

        foreach ($categories as $category) {
            \assert($category instanceof Category);
            $category->setLocale($locale);
        }

        return $categories;
    }

    public function find(int $id, string $locale): ?Category
    {
        $category = CategoryQuery::create()->findPk($id);
        if ($category === null) {
            return null;
        }
        $category->setLocale($locale);

        return $category;
    }

    public function countChildren(int $parentId): int
    {
        return CategoryQuery::create()->filterByParent($parentId)->count();
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function buildBreadcrumbPath(?Category $current, string $locale): array
    {
        if ($current === null) {
            return [];
        }

        $path = [];
        $node = $current;
        while ($node !== null && (int) $node->getId() !== 0) {
            $node->setLocale($locale);
            array_unshift($path, [
                'id' => (int) $node->getId(),
                'title' => (string) $node->getTitle(),
            ]);
            $parentId = (int) $node->getParent();
            $node = $parentId > 0 ? CategoryQuery::create()->findPk($parentId) : null;
        }

        return $path;
    }
}
