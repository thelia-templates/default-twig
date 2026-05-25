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

namespace BackOfficeDefaultTwigBundle\UiComponents\ConfirmDialog;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'BoConfirmDialog', template: '@BackOfficeDefaultTwig/components/ConfirmDialog/ConfirmDialog.html.twig')]
final class ConfirmDialog
{
    public string $id;

    public string $title;

    public string $formAction;

    public ?string $message = null;

    public string $method = 'post';

    public string $variant = 'danger';

    public ?string $confirmLabel = null;

    public string $cancelLabel = 'Cancel';

    public ?string $confirmIcon = null;

    public bool $token = true;

    public ?string $size = null;

    public ?string $testid = null;

    public ?string $hook = null;

    /** @var array<string, scalar|null> */
    public array $hookContext = [];

    public ?string $prefillTrigger = null;

    public ?string $prefillInput = null;
}
