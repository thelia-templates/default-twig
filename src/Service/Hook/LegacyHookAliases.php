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

namespace BackOfficeDefaultTwigBundle\Service\Hook;

/**
 * Maps a current BO Twig hook name to the legacy Smarty name(s) it replaced.
 *
 * During the Smarty/Twig back-office cohabitation, the Twig templates render hooks under new
 * names. {@see \BackOfficeDefaultTwigBundle\Twig\HookExtension} replays the legacy names on the
 * same render event so that modules still listening on the old names keep contributing.
 * Render arguments follow the new (Twig) convention; legacy listeners reading renamed arguments
 * must adapt — see the upgrade notes.
 */
final readonly class LegacyHookAliases
{
    private const ALIASES = [
        'attribute.update-form' => ['attribute-edit-form.bottom'],
        'feature.update-form' => ['feature-edit-form.bottom'],
        'administrator.edit-form' => ['administrator.update-form'],
        'advanced-configuration.top' => ['advanced-configuration'],
    ];

    /**
     * @return list<string>
     */
    public function legacyNamesFor(string $name): array
    {
        return self::ALIASES[$name] ?? [];
    }
}
