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
 * One label/value line of the system information screen. $status colours an
 * optional Bootstrap badge; $badge, when set, is an extra state word rendered
 * after the value (e.g. a "Writable" badge next to a directory path).
 */
final readonly class SystemInformationItem
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_DANGER = 'danger';
    public const STATUS_NONE = 'none';

    public function __construct(
        public string $label,
        public string $value = '',
        public string $status = self::STATUS_NONE,
        public ?string $badge = null,
    ) {
    }
}
