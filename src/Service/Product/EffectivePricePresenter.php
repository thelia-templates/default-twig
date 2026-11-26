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

use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Catalog\Product\ClosedCategoryProductRule;
use Thelia\Domain\Catalog\Restriction\CatalogRestrictionRule;
use Thelia\Domain\Pricing\CustomerPrice\CustomerPriceContextInterface;
use Thelia\Domain\Pricing\CustomerPrice\ResolvedPrice;
use Thelia\Domain\Pricing\EffectivePrice;
use Thelia\Domain\Pricing\PriceList\PriceListResolverInterface;
use Thelia\Domain\Pricing\PriceVisibility\PriceVisibilityResolver;
use Thelia\Domain\Taxation\TaxEngine\Calculator;
use Thelia\Model\CountryQuery;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\PriceListQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TaxRuleQuery;

/**
 * What a customer (or a visitor) pays for each combination of a product, laid out for the
 * back office: the catalog price, the price the shop resolves for this customer, where it comes
 * from, whether it can be bought and the tiers of the price list that prices it.
 *
 * Nothing is computed here: the prices are the answer of {@see CustomerPriceContextInterface}, the
 * one resolver the storefront, the cart and the orders ask. The tiers come from the same resolver
 * of the price lists ({@see PriceListResolverInterface::tiersFor()}), which answers them whatever
 * quantity the price is resolved at.
 *
 * What the customer is shown comes first: the price display mode ({@see PriceVisibilityResolver}) and
 * the assortment (the two rules the storefront narrows its queries with) decide whether the price
 * shows and whether the product is reachable at all. A hidden price is not resolved here, and a
 * combination is bought only when the mode lets the customer buy it and the resolver does.
 *
 * Every amount comes as saved, without taxes, and with the taxes of the product in the default country.
 */
final readonly class EffectivePricePresenter
{
    public const STATUS_SHOWN = 'shown';

    public const STATUS_OUTSIDE_ASSORTMENT = 'outside_assortment';

    public function __construct(
        private CustomerPriceContextInterface $prices,
        private PriceListResolverInterface $priceLists,
        private CombinationLabeler $labeler,
        private PriceVisibilityResolver $visibility,
        private CatalogRestrictionRule $assortmentRule,
        private ClosedCategoryProductRule $closedCategoryRule,
        private TranslatorInterface $translator,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array{group: string, taxed: bool, status: string, rows: list<array<string, mixed>>}
     */
    public function present(Product $product, Currency $currency, ?Customer $customer, string $locale): array
    {
        $saleElements = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->orderByIsDefault(Criteria::DESC)
            ->orderById()
            ->find();

        $pseIds = [];
        foreach ($saleElements as $saleElement) {
            $pseIds[] = (int) $saleElement->getId();
        }

        $mode = $this->visibility->modeFor($product, $customer);
        $status = match (true) {
            !$this->isInAssortment($product, $customer) => self::STATUS_OUTSIDE_ASSORTMENT,
            !$mode->showsPrice($customer) => $mode->value,
            default => self::STATUS_SHOWN,
        };
        $shown = self::STATUS_SHOWN === $status;

        $resolved = $shown ? $this->prices->resolve($pseIds, $currency, $customer) : [];
        $tiersByCombination = $shown ? $this->priceLists->tiersFor($pseIds, $currency, $customer) : [];
        $labels = $this->labeler->labelsFor($pseIds, $locale);
        $priceListNames = $this->priceListNames($resolved, $locale);
        $taxed = $this->taxedPriceOf($product);

        $rows = [];
        foreach ($saleElements as $saleElement) {
            $pseId = (int) $saleElement->getId();

            try {
                $catalog = $saleElement->getPricesByCurrency($currency);
            } catch (\RuntimeException) {
                $catalog = null;
            }

            $price = $resolved[$pseId] ?? null;
            $tiers = $tiersByCombination[$pseId] ?? [];

            $catalogPromo = null !== $catalog && 1 === (int) $saleElement->getPromo() && $catalog->getPromoPrice() > 0.0 && $catalog->getPromoPrice() !== $catalog->getPrice() ? $catalog->getPromoPrice() : null;
            $charged = $price?->chargedPrice();

            $rows[] = [
                'id' => $pseId,
                'ref' => (string) $saleElement->getRef(),
                'label' => $labels[$pseId] ?? CombinationLabeler::DEFAULT_LABEL,
                'catalog_price' => self::number($catalog?->getPrice()),
                'catalog_price_with_tax' => self::taxedNumber($catalog?->getPrice(), $taxed($catalog?->getPrice())),
                'catalog_promo_price' => self::number($catalogPromo),
                'catalog_promo_price_with_tax' => self::taxedNumber($catalogPromo, $taxed($catalogPromo)),
                'price' => self::number($charged),
                'price_with_tax' => self::taxedNumber($charged, $taxed($charged)),
                'source' => null === $price ? null : $this->sourceLabel($price, $priceListNames),
                'purchasable' => null !== $price && $price->purchasable,
                'tiers' => array_map(static fn (array $tier): array => ['quantity' => $tier['minQuantity'], 'price' => self::number($tier['untaxedPrice']), 'price_with_tax' => self::taxedNumber($tier['untaxedPrice'], $taxed($tier['untaxedPrice']))], $tiers),
            ];
        }

        return [
            'group' => CustomerGroupRepository::titleOf($this->prices->customerGroupOf($customer), $locale),
            // Whether the customer is shown the prices with taxes, as its group says.
            'taxed' => $this->prices->displaysTaxedPrices($customer),
            'status' => $status,
            'rows' => $rows,
        ];
    }

    /**
     * Whether the product is open to the customer: the two rules the storefront narrows its
     * queries with, the categories closed to the group and the assortment rules, asked for this
     * customer (a visitor when null).
     */
    private function isInAssortment(Product $product, ?Customer $customer): bool
    {
        $query = ProductQuery::create()->filterById($product->getId());
        $narrowed = false;

        foreach ([$this->closedCategoryRule, $this->assortmentRule] as $rule) {
            $clause = $rule->isActive() ? $rule->visibleProductClause('product.id', $customer) : null;

            if (null !== $clause) {
                $query->where(\sprintf('(%s)', $clause));
                $narrowed = true;
            }
        }

        return !$narrowed || $query->exists();
    }

    /**
     * The title the merchant gave each list, its code when the title is missing in this language.
     *
     * @param array<int, ResolvedPrice> $resolved
     *
     * @return array<int, string> price list id => name
     */
    private function priceListNames(array $resolved, string $locale): array
    {
        $ids = [];
        foreach ($resolved as $price) {
            if (null !== $price->priceListId) {
                $ids[$price->priceListId] = $price->priceListId;
            }
        }

        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach (PriceListQuery::create()->filterById(array_values($ids), Criteria::IN)->find() as $list) {
            $title = trim((string) $list->setLocale($locale)->getTitle());
            $names[(int) $list->getId()] = '' !== $title ? $title : (string) $list->getCode();
        }

        return $names;
    }

    /**
     * @param array<int, string> $priceListNames
     */
    private function sourceLabel(ResolvedPrice $price, array $priceListNames): string
    {
        $code = null === $price->priceListId ? '' : ($priceListNames[$price->priceListId] ?? '#'.$price->priceListId);

        if (ResolvedPrice::SOURCE_PRICE_LIST === $price->source) {
            return $this->translator->trans('Price list %code%', ['%code%' => $code]);
        }

        // An exclusive list that does not price the combination closes it: the price shown is the one the catalog would give.
        $source = match ($price->source) {
            ResolvedPrice::SOURCE_CUSTOMER_GROUP => $this->translator->trans('Customer group price'),
            EffectivePrice::SOURCE_RULE => $this->translator->trans('Catalog price rule'),
            EffectivePrice::SOURCE_RESERVED_SALE => $this->translator->trans('Reserved sale'),
            EffectivePrice::SOURCE_SALE_UNIT => $this->translator->trans('Sale unit price'),
            EffectivePrice::SOURCE_BUNDLE => $this->translator->trans('Bundle'),
            default => $this->translator->trans('Catalog price'),
        };

        return '' === $code ? $source : $source.' ('.$this->translator->trans('closed by the exclusive price list %code%', ['%code%' => $code]).')';
    }

    /**
     * Null when the rule cannot be applied, never the untaxed amount; the failure is logged, so
     * that a fault of the calculator does not pass for a product without a price with taxes.
     *
     * @return \Closure(float|string|null): ?float
     */
    private function taxedPriceOf(Product $product): \Closure
    {
        $taxRule = TaxRuleQuery::create()->findPk((int) $product->getTaxRuleId());
        $country = CountryQuery::create()->findOneByByDefault(1);
        $calculator = null;
        if (null !== $taxRule && null !== $country) {
            try {
                $calculator = (new Calculator())->loadTaxRule($taxRule, $country, $product);
            } catch (PropelException $databaseFailure) {
                throw $databaseFailure;
            } catch (\Throwable $failure) {
                $this->logTaxFailure($product, $failure);
                $calculator = null;
            }
        }

        return function (float|string|null $amount) use ($calculator, $product): ?float {
            if (null === $amount || '' === $amount || null === $calculator) {
                return null;
            }

            try {
                return round((float) $calculator->getTaxedPrice((float) $amount), 6);
            } catch (PropelException $databaseFailure) {
                throw $databaseFailure;
            } catch (\Throwable $failure) {
                $this->logTaxFailure($product, $failure);

                return null;
            }
        };
    }

    private function logTaxFailure(Product $product, \Throwable $failure): void
    {
        $this->logger->warning('The effective prices of a product show no price with taxes: its tax rule could not be applied.', [
            'product_id' => $product->getId(),
            'exception' => $failure,
        ]);
    }

    /**
     * With as many decimals as the price without taxes, two at least: 9.99 gives 11.99, not the
     * 11.988 the tax computes, and a price of a fraction of a cent keeps its digits.
     */
    private static function taxedNumber(mixed $untaxed, ?float $taxed): ?string
    {
        $text = self::number($untaxed);
        if (null === $text || null === $taxed) {
            return null;
        }

        $decimals = \strlen($text) - (int) strpos($text, '.') - 1;

        return number_format(round($taxed, $decimals), $decimals, '.', '');
    }

    private static function number(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $text = rtrim(number_format((float) $value, 6, '.', ''), '0');
        $decimals = \strlen($text) - (int) strpos($text, '.') - 1;

        return $decimals >= 2 ? $text : number_format((float) $value, 2, '.', '');
    }
}
