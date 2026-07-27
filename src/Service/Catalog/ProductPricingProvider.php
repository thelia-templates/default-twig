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

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use Propel\Runtime\Connection\StatementInterface;
use Propel\Runtime\Propel;
use Thelia\Domain\Taxation\TaxEngine\Calculator;
use Thelia\Model\CountryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\ProductQuery;

/**
 * Batches the price/stock figures of a whole catalog page in three queries, so
 * the list can show prices without one lookup per row.
 */
final class ProductPricingProvider
{
    /** @var array<int, Calculator> keyed by tax rule id */
    private array $calculators = [];

    private ?int $defaultCurrencyId = null;

    /**
     * @param list<int> $productIds
     *
     * @return array<int, ProductPricingSnapshot>
     */
    public function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $prices = $this->fetchDefaultPrices($productIds);
        $stocks = $this->fetchStocks($productIds);
        $taxRules = $this->fetchTaxRules($productIds);

        $snapshots = [];
        foreach ($productIds as $productId) {
            $price = $prices[$productId] ?? null;
            $stock = $stocks[$productId] ?? ['quantity' => 0.0, 'count' => 0];

            $untaxed = (float) ($price['price'] ?? 0.0);
            $untaxedPromo = (float) ($price['promo_price'] ?? 0.0);
            $onSale = (bool) ($price['promo'] ?? false);
            $taxRuleId = $taxRules[$productId] ?? 0;

            $snapshots[$productId] = new ProductPricingSnapshot(
                price: $untaxed,
                taxedPrice: $this->taxed($taxRuleId, $untaxed),
                promoPrice: $untaxedPromo,
                taxedPromoPrice: $this->taxed($taxRuleId, $untaxedPromo),
                onSale: $onSale,
                quantity: (float) $stock['quantity'],
                saleElementCount: (int) $stock['count'],
            );
        }

        return $snapshots;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, array{price: float, promo_price: float, promo: bool}>
     */
    private function fetchDefaultPrices(array $productIds): array
    {
        $currencyId = $this->defaultCurrencyId();
        if ($currencyId === null) {
            return [];
        }

        $sql = 'SELECT pse.product_id, pp.price, pp.promo_price, pse.promo
                FROM product_sale_elements pse
                INNER JOIN product_price pp ON pp.product_sale_elements_id = pse.id AND pp.currency_id = :currency
                WHERE pse.product_id IN ('.$this->placeholders($productIds).')
                  AND pse.is_default = 1';

        $statement = Propel::getConnection(ProductTableMap::DATABASE_NAME)->prepare($sql);
        $statement->bindValue(':currency', $currencyId, \PDO::PARAM_INT);
        $this->bindIds($statement, $productIds);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['product_id']] = [
                'price' => (float) $row['price'],
                'promo_price' => (float) $row['promo_price'],
                'promo' => (bool) $row['promo'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, array{quantity: float, count: int}>
     */
    private function fetchStocks(array $productIds): array
    {
        $sql = 'SELECT product_id, COALESCE(SUM(quantity), 0) AS total, COUNT(*) AS pse_count
                FROM product_sale_elements
                WHERE product_id IN ('.$this->placeholders($productIds).')
                GROUP BY product_id';

        $statement = Propel::getConnection(ProductTableMap::DATABASE_NAME)->prepare($sql);
        $this->bindIds($statement, $productIds);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['product_id']] = [
                'quantity' => (float) $row['total'],
                'count' => (int) $row['pse_count'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, int>
     */
    private function fetchTaxRules(array $productIds): array
    {
        $rows = ProductQuery::create()
            ->filterById($productIds, \Propel\Runtime\ActiveQuery\Criteria::IN)
            ->select(['Id', 'TaxRuleId'])
            ->find()
            ->toArray();

        $taxRules = [];
        foreach ($rows as $row) {
            $taxRules[(int) $row['Id']] = (int) $row['TaxRuleId'];
        }

        return $taxRules;
    }

    private function taxed(int $taxRuleId, float $amount): float
    {
        if ($amount <= 0.0 || $taxRuleId === 0) {
            return $amount;
        }

        $calculator = $this->calculator($taxRuleId);
        if ($calculator === null) {
            return $amount;
        }

        try {
            return (float) $calculator->getTaxedPrice($amount);
        } catch (\Throwable) {
            return $amount;
        }
    }

    private function calculator(int $taxRuleId): ?Calculator
    {
        if (\array_key_exists($taxRuleId, $this->calculators)) {
            return $this->calculators[$taxRuleId];
        }

        $taxRule = \Thelia\Model\TaxRuleQuery::create()->findPk($taxRuleId);
        $country = CountryQuery::create()->findOneByByDefault(1);
        if ($taxRule === null || $country === null) {
            return $this->calculators[$taxRuleId] = null;
        }

        $calculator = new Calculator();
        try {
            $calculator->loadTaxRuleWithoutProduct($taxRule, $country);
        } catch (\Throwable) {
            return $this->calculators[$taxRuleId] = null;
        }

        return $this->calculators[$taxRuleId] = $calculator;
    }

    private function defaultCurrencyId(): ?int
    {
        if ($this->defaultCurrencyId !== null) {
            return $this->defaultCurrencyId;
        }

        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? CurrencyQuery::create()->findOne();

        return $this->defaultCurrencyId = $currency !== null ? (int) $currency->getId() : null;
    }

    /**
     * @param list<int> $ids
     */
    private function placeholders(array $ids): string
    {
        return implode(',', array_map(static fn (int $index): string => ':id'.$index, array_keys($ids)));
    }

    /**
     * Propel hands back a StatementWrapper, not a raw PDOStatement.
     *
     * @param list<int> $ids
     */
    private function bindIds(StatementInterface $statement, array $ids): void
    {
        foreach ($ids as $index => $id) {
            $statement->bindValue(':id'.$index, $id, \PDO::PARAM_INT);
        }
    }
}
