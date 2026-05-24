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

use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleQuery;

final readonly class CombinationsTabContextBuilder
{
    /**
     * @return array{
     *     product: Product,
     *     pse_rows: list<array<string, mixed>>,
     *     has_combinations: bool,
     *     default_pse: array<string, mixed>|null,
     *     tax_rule_id: int,
     *     tax_rules: list<array{id: int, title: string}>,
     *     currency: array{id: int, symbol: string, name: string, code: string, is_default: bool}
     * }
     */
    public function build(Product $product): array
    {
        $locale = $this->defaultLocale();
        $currency = $this->defaultCurrency();
        $pseRecords = ProductSaleElementsQuery::create()
            ->filterByProductId((int) $product->getId())
            ->orderById()
            ->find();

        $rows = [];
        $defaultPse = null;
        $hasCombinations = false;
        foreach ($pseRecords as $pse) {
            \assert($pse instanceof ProductSaleElements);
            $combinationLabels = [];
            foreach ($pse->getAttributeCombinations() as $combination) {
                $attribute = $combination->getAttribute();
                $attributeAv = $combination->getAttributeAv();
                if ($attribute === null || $attributeAv === null) {
                    continue;
                }
                $attribute->setLocale($locale);
                $attributeAv->setLocale($locale);
                $combinationLabels[] = (string) $attribute->getTitle().': '.(string) $attributeAv->getTitle();
            }
            if ($combinationLabels !== []) {
                $hasCombinations = true;
            }

            $price = $pse->getPricesByCurrency($currency);
            $row = [
                'id' => (int) $pse->getId(),
                'label' => $combinationLabels === [] ? 'default' : implode(' / ', $combinationLabels),
                'ref' => (string) $pse->getRef(),
                'price' => $price !== null ? (float) $price->getPrice() : 0.0,
                'sale_price' => $price !== null ? (float) $price->getPromoPrice() : 0.0,
                'quantity' => (float) $pse->getQuantity(),
                'weight' => (float) $pse->getWeight(),
                'ean_code' => (string) $pse->getEanCode(),
                'onsale' => (bool) $pse->getPromo(),
                'isnew' => (bool) $pse->getNewness(),
                'isdefault' => (bool) $pse->getIsDefault(),
            ];
            $rows[] = $row;

            if ($defaultPse === null && (bool) $pse->getIsDefault()) {
                $defaultPse = $row;
            }
        }

        if ($defaultPse === null && $rows !== []) {
            $defaultPse = $rows[0];
        }

        return [
            'product' => $product,
            'pse_rows' => $rows,
            'has_combinations' => $hasCombinations,
            'default_pse' => $defaultPse,
            'tax_rule_id' => (int) $product->getTaxRuleId(),
            'tax_rules' => $this->collectTaxRules($locale),
            'currency' => [
                'id' => (int) $currency->getId(),
                'symbol' => (string) $currency->getSymbol(),
                'name' => (string) $currency->getName(),
                'code' => (string) $currency->getCode(),
                'is_default' => (bool) $currency->getByDefault(),
            ],
        ];
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function collectTaxRules(string $locale): array
    {
        $rules = [];
        foreach (TaxRuleQuery::create()->orderByPosition()->find() as $taxRule) {
            \assert($taxRule instanceof TaxRule);
            $taxRule->setLocale($locale);
            $rules[] = [
                'id' => (int) $taxRule->getId(),
                'title' => (string) $taxRule->getTitle(),
            ];
        }

        return $rules;
    }

    private function defaultLocale(): string
    {
        return LangQuery::create()->findOneByByDefault(true)?->getLocale() ?? 'en_US';
    }

    private function defaultCurrency(): Currency
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(true);
        if ($currency !== null) {
            return $currency;
        }
        $first = CurrencyQuery::create()->orderById()->findOne();
        if ($first === null) {
            throw new \RuntimeException('No currency configured.');
        }

        return $first;
    }
}
