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

namespace BackOfficeDefaultTwigBundle\UiComponents\FetchDialog;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'BoFetchDialog', template: '@BackOfficeDefaultTwig/components/FetchDialog/FetchDialog.html.twig')]
final class FetchDialog
{
    public string $id;

    public string $title;

    public string $triggerAttribute = 'data-fetch-url';

    public ?string $size = 'lg';

    public ?string $testid = null;
}
