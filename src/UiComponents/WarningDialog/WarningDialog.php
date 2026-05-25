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

namespace BackOfficeDefaultTwigBundle\UiComponents\WarningDialog;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'BoWarningDialog', template: '@BackOfficeDefaultTwig/components/WarningDialog/WarningDialog.html.twig')]
final class WarningDialog
{
    public string $id;

    public string $title;

    public ?string $message = null;

    public string $okLabel = 'OK';

    public ?string $okButtonId = null;

    public string $variant = 'warning';

    public ?string $icon = null;

    public ?string $testid = null;

    public function iconClass(): string
    {
        return $this->icon ?? match ($this->variant) {
            'danger' => 'bi-exclamation-octagon-fill',
            'info' => 'bi-info-circle-fill',
            'primary', 'success' => 'bi-check-circle-fill',
            default => 'bi-exclamation-triangle-fill',
        };
    }
}
