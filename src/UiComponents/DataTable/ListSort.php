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

namespace BackOfficeDefaultTwigBundle\UiComponents\DataTable;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tiny VO for back-office list sort state — reads `order` / `direction` query
 * params from the request, validates them against an allow-list, and exposes
 * the values both for the Propel query (`field`/`direction`) and for the
 * `BoDataTable` component (`sortField`/`sortDirection`).
 *
 * Use with {@see Column::$sortKey} so each clickable header carries the same
 * `field` token the controller knows how to map back to a Propel column.
 */
final readonly class ListSort
{
    private const DIRECTIONS = ['asc', 'desc'];

    public function __construct(
        public string $field,
        public string $direction,
    ) {
    }

    /**
     * @param list<string> $allowedFields
     */
    public static function fromRequest(
        Request $request,
        array $allowedFields,
        string $defaultField,
        string $defaultDirection = 'asc',
        string $fieldParam = 'order',
        string $directionParam = 'direction',
    ): self {
        $field = (string) $request->query->get($fieldParam, $defaultField);
        if (!\in_array($field, $allowedFields, true)) {
            $field = $defaultField;
        }
        $direction = strtolower((string) $request->query->get($directionParam, $defaultDirection));
        if (!\in_array($direction, self::DIRECTIONS, true)) {
            $direction = $defaultDirection;
        }

        return new self($field, $direction);
    }
}
