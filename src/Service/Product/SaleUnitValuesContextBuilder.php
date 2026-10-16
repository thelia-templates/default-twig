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

use BackOfficeDefaultTwigBundle\Repository\SaleUnitRepository;

/**
 * The prices and stocks one sale unit keeps of the combinations of a product, next to the
 * national values they replace. A value the unit does not set is shown as the national one
 * it falls back to; a shop without any sale unit gets no section at all.
 */
final readonly class SaleUnitValuesContextBuilder
{
    public function __construct(
        private SaleUnitRepository $saleUnits,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $combinationRows the rows of the combinations tab, national values in $currencyId
     *
     * @return array{
     *     sale_units: list<array{id: int, code: string, title: string, active: bool}>,
     *     sale_unit: array{id: int, code: string, title: string, active: bool},
     *     currency_id: int,
     *     rows: list<array<string, mixed>>
     * }|null
     */
    public function build(array $combinationRows, int $currencyId, ?int $saleUnitId, string $locale): ?array
    {
        $options = $this->saleUnits->findOptions($locale);
        if ([] === $options) {
            return null;
        }

        $selected = $options[0];
        foreach ($options as $option) {
            if ($option['id'] === $saleUnitId) {
                $selected = $option;
                break;
            }
        }

        $pseIds = array_map(static fn (array $row): int => (int) $row['id'], $combinationRows);
        $prices = $this->saleUnits->findPrices($selected['id'], $pseIds, $currencyId);
        $stocks = $this->saleUnits->findStocks($selected['id'], $pseIds);

        $rows = [];
        foreach ($combinationRows as $row) {
            $pseId = (int) $row['id'];
            $price = $prices[$pseId] ?? null;
            $stock = $stocks[$pseId] ?? null;

            $rows[] = [
                'id' => $pseId,
                'label' => (string) $row['label'],
                'ref' => (string) $row['ref'],
                'onsale' => (bool) $row['onsale'],
                'national_price' => self::number($row['price'] ?? null),
                'national_promo_price' => self::number($row['sale_price'] ?? null),
                'national_quantity' => self::number($row['quantity'] ?? null),
                'price' => self::number($price?->getPrice()),
                'promo_price' => self::number($price?->getPromoPrice()),
                'quantity' => self::number($stock?->getQuantity()),
            ];
        }

        return [
            'sale_units' => $options,
            'sale_unit' => $selected,
            'currency_id' => $currencyId,
            'rows' => $rows,
        ];
    }

    /**
     * A decimal column read back without its trailing zeros, null when there is no value:
     * an empty field is what says "the national value applies".
     */
    private static function number(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $formatted = number_format((float) $value, 6, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
