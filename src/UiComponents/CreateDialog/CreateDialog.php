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

namespace BackOfficeDefaultTwigBundle\UiComponents\CreateDialog;

use Symfony\Component\Form\FormView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'BoCreateDialog', template: '@BackOfficeDefaultTwig/components/CreateDialog/CreateDialog.html.twig')]
final class CreateDialog
{
    public string $id;

    public string $title;

    public FormView $form;

    public string $formAction;

    public string $method = 'post';

    public ?string $fieldsTemplate = null;

    public string $submitLabel = 'Save';

    public string $submitIcon = 'bi-check2';

    public string $submitVariant = 'primary';

    public string $cancelLabel = 'Cancel';

    public string $size = 'modal-lg';

    public ?string $testid = null;

    public ?string $hook = null;

    /** @var array<string, scalar|null> */
    public array $hookContext = [];
}
