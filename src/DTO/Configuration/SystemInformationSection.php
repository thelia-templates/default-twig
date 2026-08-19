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

namespace BackOfficeDefaultTwigBundle\DTO\Configuration;

/**
 * A titled group of {@see SystemInformationItem}. $key is stable and untranslated,
 * used both for the `data-testid` and to drive section-specific rendering (the
 * PHP extensions section is displayed as badges rather than as a two-column table).
 */
final readonly class SystemInformationSection
{
    public const KEY_THELIA = 'thelia';
    public const KEY_PHP = 'php';
    public const KEY_PHP_EXTENSIONS = 'php-extensions';
    public const KEY_SYMFONY = 'symfony';
    public const KEY_DATABASE = 'database';
    public const KEY_CACHE_LOGS = 'cache-logs';

    /**
     * @param list<SystemInformationItem> $items
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $items,
    ) {
    }
}
