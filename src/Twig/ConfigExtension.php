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

namespace BackOfficeDefaultTwigBundle\Twig;

use Thelia\Model\ConfigQuery;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `thelia_config('key', default)` to Twig templates so they can read
 * arbitrary entries from the Thelia `config` table (store branding, feature
 * flags, etc.) without a dedicated controller wiring.
 */
final class ConfigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('thelia_config', $this->read(...)),
        ];
    }

    public function read(string $key, ?string $default = null): ?string
    {
        $value = ConfigQuery::read($key, $default);

        return null === $value ? null : (string) $value;
    }
}
