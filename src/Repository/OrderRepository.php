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

use BackOfficeDefaultTwigBundle\Service\Order\OrderFilters;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Propel;
use Thelia\Model\Order;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

/**
 * Centralised Propel queries for the order back-office screens.
 */
final readonly class OrderRepository
{
    public const SORT_FIELDS = [
        'id' => 'id',
        'ref' => 'ref',
        'created_at' => 'createdAt',
        'amount' => 'id', // total is computed; we fall back to id (insertion order ≈ creation order).
    ];

    /**
     * @return array{rows: ObjectCollection<int, Order>, total: int, lastPage: int}
     */
    public function findPaginated(OrderFilters $filters, int $page, int $perPage): array
    {
        $query = $this->buildFilteredQuery($filters);

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $lastPage));

        $sortColumn = self::SORT_FIELDS[$filters->sort] ?? self::SORT_FIELDS[OrderFilters::DEFAULT_SORT];
        $sortDirection = $filters->direction === 'asc' ? Criteria::ASC : Criteria::DESC;

        $rows = $query
            ->joinWithCustomer()
            ->joinWithOrderAddressRelatedByDeliveryOrderAddressId()
            ->joinModuleRelatedByPaymentModuleId('PaymentModule')
            ->with('PaymentModule')
            ->joinModuleRelatedByDeliveryModuleId('DeliveryModule')
            ->with('DeliveryModule')
            ->orderBy('order.'.$sortColumn, $sortDirection)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->find();

        return ['rows' => $rows, 'total' => $total, 'lastPage' => $lastPage];
    }

    public function findById(int $orderId): ?Order
    {
        return OrderQuery::create()->findPk($orderId);
    }

    public function countItemsForOrder(int $orderId): int
    {
        return OrderProductQuery::create()->filterByOrderId($orderId)->count();
    }

    /**
     * @return ObjectCollection<int, OrderStatus>
     */
    public function findAllStatuses(): ObjectCollection
    {
        return OrderStatusQuery::create()->orderById()->find();
    }

    /**
     * First entry is a synthetic "all statuses" option (id = 0).
     *
     * @return list<array{id: int, title: string, code: string}>
     */
    public function findStatusChoices(string $locale, string $allLabel): array
    {
        $items = [['id' => 0, 'title' => $allLabel, 'code' => '']];

        foreach (OrderStatusQuery::create()->orderByPosition()->find() as $status) {
            $status->setLocale($locale);
            $items[] = [
                'id' => (int) $status->getId(),
                'title' => (string) $status->getTitle(),
                'code' => (string) $status->getCode(),
            ];
        }

        return $items;
    }

    /**
     * Includes native colour. No synthetic "all" option (the multi-select handles it).
     *
     * @return list<array{id: int, title: string, code: string, color: string}>
     */
    public function findStatusesLocalized(string $locale): array
    {
        $items = [];
        foreach (OrderStatusQuery::create()->orderByPosition()->find() as $status) {
            $status->setLocale($locale);
            $items[] = [
                'id' => (int) $status->getId(),
                'title' => (string) $status->getTitle(),
                'code' => (string) $status->getCode(),
                'color' => (string) ($status->getColor() ?: '#6c757d'),
            ];
        }

        return $items;
    }

    /**
     * Restricted to statuses currently hosting at least one order. Used by the sidebar counters.
     *
     * @return list<array{id: int, code: string, title: string, color: string, count: int}>
     */
    public function findStatusesWithCounts(string $locale): array
    {
        $items = [];

        foreach ($this->findAllStatuses() as $status) {
            $statusId = (int) $status->getId();
            $count = OrderQuery::create()->filterByStatusId($statusId)->count();

            if ($count === 0) {
                continue;
            }

            $status->setLocale($locale);

            $items[] = [
                'id' => $statusId,
                'code' => (string) $status->getCode(),
                'title' => (string) $status->getTitle(),
                'color' => (string) ($status->getColor() ?: '#6c757d'),
                'count' => $count,
            ];
        }

        return $items;
    }

    public function countAll(): int
    {
        return OrderQuery::create()->count();
    }

    /**
     * Restricted to countries actually appearing on a delivery address (avoids
     * a 250+ entry dropdown).
     *
     * @return list<int>
     */
    public function findReferencedDeliveryCountryIds(): array
    {
        $sql = '
            SELECT DISTINCT oa.country_id
            FROM `order` o
            JOIN order_address oa ON oa.id = o.delivery_order_address_id
            WHERE oa.country_id IS NOT NULL
        ';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute();

        $ids = [];
        while (($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
            $id = (int) $row[0];
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function buildFilteredQuery(OrderFilters $filters): OrderQuery
    {
        $query = OrderQuery::create();
        $filters->applyTo($query);

        return $query;
    }
}
