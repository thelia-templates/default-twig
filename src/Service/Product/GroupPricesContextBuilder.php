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

use BackOfficeDefaultTwigBundle\Repository\CurrencyRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupPriceRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use Thelia\Domain\Pricing\CurrencyConverter;
use Thelia\Model\Currency;

/**
 * The prices the customer groups keep of their own for the combinations of a product, laid
 * out as one cell per combination and group. An empty field means the group has no price of
 * its own: it shows in grey what the group pays then, the price of the default currency
 * converted, else the catalog price less the discount of the group.
 * A shop without any customer group gets no section at all.
 *
 * A converted placeholder carries where it comes from as a translation key and its parameters.
 */
final readonly class GroupPricesContextBuilder
{
    public const HINT_CONVERTED = 'Converted from %currency%';

    public function __construct(
        private CustomerGroupRepository $customerGroups,
        private CustomerGroupPriceRepository $prices,
        private CurrencyRepository $currencies,
        private CurrencyConverter $converter,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $combinationRows the rows of the combinations tab, catalog prices in $currencyId
     *
     * @return array{
     *     groups: list<array{id: int, label: string, discount: float}>,
     *     currency_id: int,
     *     rows: list<array{id: int, label: string, ref: string, price: ?string, sale_price: ?string, onsale: bool, cells: list<array{group_id: int, fields: list<array<string, mixed>>}>}>
     * }|null
     */
    public function build(array $combinationRows, int $currencyId, string $locale): ?array
    {
        $groups = $this->customerGroups->findChoicesWithDiscount($locale);
        if ([] === $groups) {
            return null;
        }

        $currency = $this->currencies->find($currencyId);
        $default = $this->currencies->findDefault();
        $converts = null !== $currency && null !== $default && $currency->getId() !== $default->getId();
        $defaultId = (int) $default?->getId();
        $defaultCode = (string) $default?->getCode();

        $pseIds = array_map(static fn (array $row): int => (int) $row['id'], $combinationRows);
        $prices = $this->prices->findPrices($pseIds, $converts ? [$currencyId, $defaultId] : [$currencyId]);

        $rows = [];
        foreach ($combinationRows as $row) {
            $pseId = (int) $row['id'];
            $catalogPrice = self::number($row['price'] ?? null);
            $catalogPromo = self::number($row['sale_price'] ?? null);
            $onSale = (bool) ($row['onsale'] ?? false);
            $cells = [];
            foreach ($groups as $group) {
                $own = $prices[$group['id']][$pseId][$currencyId] ?? null;
                // A row flagged as taken from the default currency is not a price typed here.
                if ($converts && $own?->getFromDefaultCurrency()) {
                    $own = null;
                }
                $fromDefault = $converts ? ($prices[$group['id']][$pseId][$defaultId] ?? null) : null;
                [$catalogPriceForGroup, $catalogPromoForGroup] = self::catalogForGroup(
                    $catalogPrice,
                    $catalogPromo,
                    $onSale,
                    $group['discount'],
                    null !== self::number($own?->getPrice() ?? $fromDefault?->getPrice()),
                );

                $cells[] = [
                    'group_id' => $group['id'],
                    'fields' => [
                        $this->field('price', $own?->getPrice(), $fromDefault?->getPrice(), $catalogPriceForGroup, $currency, $defaultCode),
                        $this->field('promo_price', $own?->getPromoPrice(), $fromDefault?->getPromoPrice(), $catalogPromoForGroup, $currency, $defaultCode),
                    ],
                ];
            }

            $rows[] = [
                'id' => $pseId,
                'label' => (string) $row['label'],
                'ref' => (string) $row['ref'],
                'price' => $catalogPrice,
                'sale_price' => $catalogPromo,
                'onsale' => $onSale,
                'cells' => $cells,
            ];
        }

        return ['groups' => $groups, 'currency_id' => $currencyId, 'rows' => $rows];
    }

    /**
     * As the core resolves it: the catalog promotion never comes with a price of the group's own.
     *
     * @return array{?string, ?string}
     */
    private static function catalogForGroup(?string $price, ?string $promo, bool $onSale, float $discount, bool $groupHasPrice): array
    {
        if (null === $price) {
            return [null, null];
        }

        $discounted = (float) $price * (1 - $discount / 100);
        $promoApplies = !$groupHasPrice && $onSale && null !== $promo && (float) $promo < $discounted;

        return [self::number(round($discounted, 6)), $promoApplies ? $promo : null];
    }

    /**
     * Each of the two prices is read on its own, as the core does: one typed here wins,
     * else the one of the default currency converted at the rates of the shop, else the catalog.
     *
     * @return array{name: string, value: ?string, placeholder: ?string, hint: string, hint_parameters: array<string, string>}
     */
    private function field(string $name, mixed $own, mixed $inDefaultCurrency, ?string $catalog, ?Currency $currency, string $defaultCode): array
    {
        $value = self::number($own);
        if (null !== $value) {
            return ['name' => $name, 'value' => $value, 'placeholder' => null, 'hint' => '', 'hint_parameters' => []];
        }

        $converted = null === $inDefaultCurrency || null === $currency ? null : $this->converter->fromDefaultCurrency((float) $inDefaultCurrency, $currency);
        if (null === $converted) {
            return ['name' => $name, 'value' => null, 'placeholder' => $catalog, 'hint' => '', 'hint_parameters' => []];
        }

        return [
            'name' => $name,
            'value' => null,
            'placeholder' => self::number($converted),
            'hint' => self::HINT_CONVERTED,
            'hint_parameters' => ['%currency%' => $defaultCode],
        ];
    }

    /**
     * A decimal column read back without its trailing zeros, null when there is no value:
     * an empty field is what says "no price of its own".
     */
    private static function number(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
}
