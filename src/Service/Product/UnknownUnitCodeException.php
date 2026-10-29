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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A unit posted for a combination that is not one of the units the core knows: the page
 * only offers those, so the value was written by hand.
 */
#[Exclude]
final class UnknownUnitCodeException extends \InvalidArgumentException
{
}
