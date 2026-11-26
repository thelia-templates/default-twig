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

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Catalog\Quantity\QuantityFormatter;
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Domain\Catalog\Quantity\ReferenceUnitPrice;

/**
 * The line under a combination sold with a unit, in labelled parts: the price per unit of measure,
 * the content (by the piece only), the quantities it is ordered in (when not from 1, by 1).
 */
final readonly class ReferencePriceLineWriter
{
    public function __construct(
        private ReferenceUnitPricePresenter $prices,
        private QuantityFormatter $quantities,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{label: string, value: string}>|null
     */
    public function write(QuantityRule $rule, ReferenceUnitPrice $price, string $locale, ?string $currencyCode, bool $onSale): ?array
    {
        // Without a content, an article sold by the piece has no line: never "Content: 0 g".
        if ($rule->unitCode === null || (!$rule->isSoldByMeasure() && null === $rule->unitQuantity)) {
            return null;
        }

        $parameters = ['%price%' => $this->prices->format($price, $locale, $currencyCode)];
        $parts = [[
            'label' => $this->translator->trans('Price per unit of measure'),
            'value' => $onSale
                ? $this->translator->trans('%price% incl. taxes (sale price)', $parameters)
                : $this->translator->trans('%price% incl. taxes', $parameters),
        ]];

        if ($rule->isSoldByMeasure()) {
            $minimum = $this->quantities->formatMeasure($rule->minQuantity, $rule->decimals, $rule->unitCode, $locale);
            $step = $this->quantities->formatMeasure($rule->step, $rule->decimals, $rule->unitCode, $locale);
        } else {
            $parts[] = [
                'label' => $this->translator->trans('Content'),
                'value' => $this->quantities->formatMeasure((float) $rule->unitQuantity, 3, $rule->unitCode, $locale),
            ];
            if ($rule->minQuantity <= 1.0 && $rule->step <= 1.0) {
                return $parts;
            }
            $minimum = $this->quantities->format($rule->minQuantity, 0, null, $locale);
            $step = $this->quantities->format($rule->step, 0, null, $locale);
        }

        $parts[] = [
            'label' => $this->translator->trans('Order'),
            'value' => $this->translator->trans('from %min%, by %step%', ['%min%' => $minimum, '%step%' => $step]),
        ];

        return $parts;
    }
}
