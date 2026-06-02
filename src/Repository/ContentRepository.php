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
use Thelia\Model\Content;
use Thelia\Model\ContentQuery;

final readonly class ContentRepository
{
    /**
     * @return ObjectCollection<int, Content>
     */
    public function findInFolderPage(int $folderId, string $locale, int $offset, int $limit): ObjectCollection
    {
        /** @var ObjectCollection<int, Content> $contents */
        $contents = ContentQuery::create()
            ->useContentFolderQuery()
                ->filterByFolderId($folderId)
                ->orderByPosition()
            ->endUse()
            ->offset($offset)
            ->limit($limit)
            ->find();

        foreach ($contents as $content) {
            $content->setLocale($locale);
        }

        return $contents;
    }

    public function countInFolder(int $folderId): int
    {
        return ContentQuery::create()
            ->useContentFolderQuery()
                ->filterByFolderId($folderId)
            ->endUse()
            ->count();
    }
}
