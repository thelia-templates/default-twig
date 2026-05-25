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
use Thelia\Model\Folder;
use Thelia\Model\FolderQuery;

final readonly class FolderRepository
{
    /**
     * @return ObjectCollection<int, Folder>
     */
    public function findAllOrderedByPosition(string $locale): ObjectCollection
    {
        $folders = FolderQuery::create()->orderByPosition()->find();
        foreach ($folders as $folder) {
            \assert($folder instanceof Folder);
            $folder->setLocale($locale);
        }

        return $folders;
    }

    /**
     * @return ObjectCollection<int, Folder>
     */
    public function findChildrenOrderedByPosition(int $parentId, string $locale): ObjectCollection
    {
        $folders = FolderQuery::create()->filterByParent($parentId)->orderByPosition()->find();
        foreach ($folders as $folder) {
            \assert($folder instanceof Folder);
            $folder->setLocale($locale);
        }

        return $folders;
    }
}
