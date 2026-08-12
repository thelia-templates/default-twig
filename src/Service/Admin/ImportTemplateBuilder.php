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

namespace BackOfficeDefaultTwigBundle\Service\Admin;

use Thelia\Core\Serializer\SerializerManager;
use Thelia\Domain\DataTransfer\Import\AbstractImport;
use Thelia\Model\Import;

/**
 * Builds the empty CSV template advertising the columns an import expects.
 * Columns are read from the import handle class, so nothing has to be maintained on disk.
 */
final readonly class ImportTemplateBuilder
{
    private const CSV_SERIALIZER_ID = 'thelia.csv';

    public function __construct(
        private SerializerManager $serializerManager,
    ) {
    }

    /**
     * @return list<string> Columns declared by the import, empty when it advertises none
     */
    public function columnsFor(Import $import): array
    {
        $handleClass = $import->getHandleClass();

        if ($handleClass === null || !class_exists($handleClass)) {
            return [];
        }

        $handler = new $handleClass();

        if (!$handler instanceof AbstractImport) {
            return [];
        }

        return array_values($handler->getTemplateColumns());
    }

    /**
     * Builds the header row alone. Column names are used as both keys and values so the
     * serializer writes them with the delimiter and enclosure its own unserialize() expects.
     */
    public function build(Import $import): string
    {
        $columns = $this->columnsFor($import);

        if ($columns === []) {
            return '';
        }

        return $this->serializerManager
            ->get(self::CSV_SERIALIZER_ID)
            ->serialize(array_combine($columns, $columns));
    }

    public function fileNameFor(Import $import): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $import->getRef())), '-');

        return $slug.'-template.csv';
    }

    public function isCsvAvailable(): bool
    {
        return $this->serializerManager->has(self::CSV_SERIALIZER_ID);
    }

    public function mimeType(): string
    {
        return $this->serializerManager->get(self::CSV_SERIALIZER_ID)->getMimeType().'; charset=UTF-8';
    }
}
