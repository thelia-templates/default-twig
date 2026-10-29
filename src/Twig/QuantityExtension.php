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

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Domain\Catalog\Quantity\QuantityFormatter;
use Thelia\Domain\Catalog\Quantity\UnitCode;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ quantity|format_quantity(decimals, unitCode) }}`: a quantity with the decimals of its
 * combination and the symbol of its unit, in the admin's language ("1,25 kg"). The core
 * writes it; each theme only declares its own filter.
 */
final class QuantityExtension extends AbstractExtension
{
    public function __construct(
        private readonly QuantityFormatter $formatter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_quantity', fn (float|int|string|null $quantity, int|string|null $decimals = 0, ?string $unitCode = null): string => $this->formatter->format((float) $quantity, (int) $decimals, UnitCode::tryFrom((string) $unitCode), $this->requestStack->getCurrentRequest()?->getLocale())),
        ];
    }
}
