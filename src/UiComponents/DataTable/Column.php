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

final readonly class Column
{
    /**
     * @param string               $cellAlign bootstrap text-alignment utility, one of: 'start', 'center', 'end'
     * @param array<string, mixed> $options   Kind-specific options. Schemas per ColumnKind:
     *                                        - TOGGLE: { url_key: string|null, icon_on: string, icon_off: string } (null url_key → readonly)
     *                                        - BADGE:  { variants: array<scalar, string> mapping row value → Bootstrap variant, label_key?: string optional row key holding the visible label, color_key?: string optional row key holding a CSS color taking precedence over variants }
     *                                        - RADIO:  { name: string, value_key: string, checked_when_key: string|null, label_key: string|null }
     *                                        - TEXT / HTML / ACTIONS: empty
     * @param ?string              $sortKey   Sort field this column maps to. When set, the header becomes a sortable link.
     *                                        Use the same string the consuming controller expects in its order query param.
     */
    public function __construct(
        public string $key,
        public string $label,
        public ColumnKind $kind = ColumnKind::TEXT,
        public string $cellAlign = 'start',
        public array $options = [],
        public ?string $sortKey = null,
    ) {
    }
}
