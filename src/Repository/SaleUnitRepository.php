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

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\OrderQuery;
use Thelia\Model\SaleUnit;
use Thelia\Model\SaleUnitProductPrice;
use Thelia\Model\SaleUnitProductPriceQuery;
use Thelia\Model\SaleUnitProductSaleElements;
use Thelia\Model\SaleUnitProductSaleElementsQuery;
use Thelia\Model\SaleUnitQuery;

/**
 * Reads of the sale units and of what each one owns of the catalog: its prices and its
 * stocks. A shop that never declared a sale unit answers every question with one query
 * on an empty table.
 */
final readonly class SaleUnitRepository
{
    public function find(int $saleUnitId): ?SaleUnit
    {
        return $saleUnitId > 0 ? SaleUnitQuery::create()->findPk($saleUnitId) : null;
    }

    public function hasAny(): bool
    {
        return SaleUnitQuery::create()->exists();
    }

    public function isCodeTaken(string $code, ?int $exceptId = null): bool
    {
        $query = SaleUnitQuery::create()->filterByCode($code);
        if (null !== $exceptId) {
            $query->filterById($exceptId, Criteria::NOT_EQUAL);
        }

        return $query->exists();
    }

    public function isUrlTaken(string $url, ?int $exceptId = null): bool
    {
        $query = SaleUnitQuery::create()->filterByUrl($url);
        if (null !== $exceptId) {
            $query->filterById($exceptId, Criteria::NOT_EQUAL);
        }

        return $query->exists();
    }

    public function countOrders(int $saleUnitId): int
    {
        return OrderQuery::create()->filterBySaleUnitId($saleUnitId)->count();
    }

    public function lastPosition(): int
    {
        return (int) SaleUnitQuery::create()->orderByPosition(Criteria::DESC)->findOne()?->getPosition();
    }

    /**
     * @return ObjectCollection<int, SaleUnit>
     */
    public function findSorted(string $locale, string $field, string $direction): ObjectCollection
    {
        $criteria = 'DESC' === strtoupper($direction) ? Criteria::DESC : Criteria::ASC;
        $query = SaleUnitQuery::create();

        match ($field) {
            'id' => $query->orderById($criteria),
            'code' => $query->orderByCode($criteria),
            'url' => $query->orderByUrl($criteria),
            'title' => $query->useSaleUnitI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderByPosition($criteria)->orderById(),
        };

        /** @var ObjectCollection<int, SaleUnit> $saleUnits */
        $saleUnits = $query->find();

        return $saleUnits;
    }

    /**
     * Every sale unit, in the merchant's order, with the title of the given language
     * and the code in its place when that title is missing.
     *
     * @return list<array{id: int, code: string, title: string, active: bool}>
     */
    public function findOptions(string $locale): array
    {
        $options = [];

        foreach (SaleUnitQuery::create()->orderByPosition()->orderById()->find() as $saleUnit) {
            $options[] = [
                'id' => (int) $saleUnit->getId(),
                'code' => (string) $saleUnit->getCode(),
                'title' => self::titleOf($saleUnit, $locale),
                'active' => (bool) $saleUnit->getActive(),
            ];
        }

        return $options;
    }

    /**
     * The other sale units, in the merchant's order: the ones an action may swap places with.
     *
     * @return list<SaleUnit>
     */
    public function findSiblingsOf(SaleUnit $saleUnit): array
    {
        /** @var list<SaleUnit> $siblings */
        $siblings = SaleUnitQuery::create()
            ->filterById($saleUnit->getId(), Criteria::NOT_EQUAL)
            ->orderByPosition()
            ->orderById()
            ->find()
            ->getData();

        return $siblings;
    }

    /**
     * @param list<int> $productSaleElementsIds
     *
     * @return array<int, SaleUnitProductPrice> by sale element id
     */
    public function findPrices(int $saleUnitId, array $productSaleElementsIds, int $currencyId): array
    {
        if ([] === $productSaleElementsIds) {
            return [];
        }

        $prices = [];
        foreach (SaleUnitProductPriceQuery::create()
            ->filterBySaleUnitId($saleUnitId)
            ->filterByCurrencyId($currencyId)
            ->filterByProductSaleElementsId($productSaleElementsIds, Criteria::IN)
            ->find() as $price) {
            $prices[(int) $price->getProductSaleElementsId()] = $price;
        }

        return $prices;
    }

    /**
     * @param list<int> $productSaleElementsIds
     *
     * @return array<int, SaleUnitProductSaleElements> by sale element id
     */
    public function findStocks(int $saleUnitId, array $productSaleElementsIds): array
    {
        if ([] === $productSaleElementsIds) {
            return [];
        }

        $stocks = [];
        foreach (SaleUnitProductSaleElementsQuery::create()
            ->filterBySaleUnitId($saleUnitId)
            ->filterByProductSaleElementsId($productSaleElementsIds, Criteria::IN)
            ->find() as $stock) {
            $stocks[(int) $stock->getProductSaleElementsId()] = $stock;
        }

        return $stocks;
    }

    public function findPrice(int $saleUnitId, int $productSaleElementsId, int $currencyId): ?SaleUnitProductPrice
    {
        return SaleUnitProductPriceQuery::create()
            ->filterBySaleUnitId($saleUnitId)
            ->filterByProductSaleElementsId($productSaleElementsId)
            ->filterByCurrencyId($currencyId)
            ->findOne();
    }

    public function findStock(int $saleUnitId, int $productSaleElementsId): ?SaleUnitProductSaleElements
    {
        return SaleUnitProductSaleElementsQuery::create()
            ->filterBySaleUnitId($saleUnitId)
            ->filterByProductSaleElementsId($productSaleElementsId)
            ->findOne();
    }

    public static function titleOf(SaleUnit $saleUnit, string $locale): string
    {
        $title = trim((string) $saleUnit->setLocale($locale)->getTitle());

        return '' !== $title ? $title : (string) $saleUnit->getCode();
    }
}
