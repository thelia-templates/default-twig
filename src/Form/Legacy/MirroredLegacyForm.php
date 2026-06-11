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

namespace BackOfficeDefaultTwigBundle\Form\Legacy;

use Symfony\Component\Form\FormBuilderInterface;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Form\BaseForm;

/**
 * BaseForm facade over a Symfony form builder so legacy module listeners can keep
 * calling $event->getForm()->getFormBuilder(). Never goes through init()/buildForm(),
 * not a container service (excluded from DI, built by LegacyFormEventBridge).
 */
final class MirroredLegacyForm extends BaseForm
{
    public function __construct(FormBuilderInterface $formBuilder, ?Request $request = null)
    {
        $this->formBuilder = $formBuilder;

        if ($request instanceof Request) {
            $this->request = $request;
        }
    }

    protected function buildForm(): void
    {
    }
}
