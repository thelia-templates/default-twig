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
use Thelia\Model\Map\ContentFolderTableMap;

final readonly class ContentRepository
{
    /**
     * Position is stored per-folder in the content_folder pivot, not on the content row.
     * Expose it as a virtual column so the listing reflects the folder-scoped order.
     */
    public const FOLDER_POSITION_COLUMN = 'folder_position';

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
            ->withColumn(ContentFolderTableMap::COL_POSITION, self::FOLDER_POSITION_COLUMN)
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
