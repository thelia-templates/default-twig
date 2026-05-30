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

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\Service\Order\OrderFilters;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Propel;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
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

        /** @var ObjectCollection<int, Order> $rows */
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

    public function countByCustomer(int $customerId): int
    {
        return (int) OrderQuery::create()->filterByCustomerId($customerId)->count();
    }

    /**
     * @return ObjectCollection<int, Order>
     */
    public function findRecentByCustomer(int $customerId, int $limit = 25): ObjectCollection
    {
        /** @var ObjectCollection<int, Order> $result */
        $result = OrderQuery::create()
            ->filterByCustomerId($customerId)
            ->orderByCreatedAt(Criteria::DESC)
            ->limit($limit)
            ->find();

        return $result;
    }

    /**
     * @return ObjectCollection<int, Order>
     */
    public function findByCustomerPage(int $customerId, int $page, int $perPage): ObjectCollection
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        /** @var ObjectCollection<int, Order> $result */
        $result = OrderQuery::create()
            ->filterByCustomerId($customerId)
            ->orderByCreatedAt(Criteria::DESC)
            ->offset($offset)
            ->limit($perPage)
            ->find();

        return $result;
    }

    public function countOrderProducts(int $orderId): int
    {
        return OrderProductQuery::create()->filterByOrderId($orderId)->count();
    }

    /**
     * @return ObjectCollection<int, OrderProduct>
     */
    public function findOrderProductsPage(int $orderId, int $page, int $perPage): ObjectCollection
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        /** @var ObjectCollection<int, OrderProduct> $result */
        $result = OrderProductQuery::create()
            ->filterByOrderId($orderId)
            ->orderById()
            ->offset($offset)
            ->limit($perPage)
            ->find();

        return $result;
    }

    /**
     * @return ObjectCollection<int, OrderStatus>
     */
    public function findAllStatuses(): ObjectCollection
    {
        /** @var ObjectCollection<int, OrderStatus> $result */
        $result = OrderStatusQuery::create()->orderById()->find();

        return $result;
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
     * Sum of computed totals on orders created in $range. Used by the dashboard
     * revenue KPI.
     */
    public function getRevenue(DateRange $range): float
    {
        $sql = 'SELECT COALESCE(SUM(computed), 0) AS revenue FROM (
            SELECT '.OrderFilters::totalAmountSqlExpression().' AS computed
            FROM `order`
            WHERE created_at BETWEEN :from AND :to
        ) AS sub';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute([':from' => $range->fromSql(), ':to' => $range->toSql()]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return (float) ($row['revenue'] ?? 0);
    }

    public function countOrders(DateRange $range): int
    {
        return (int) OrderQuery::create()
            ->filterByCreatedAt($range->fromSql(), Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($range->toSql(), Criteria::LESS_EQUAL)
            ->count();
    }

    /**
     * Daily revenue and order count buckets, one row per day in the range
     * (filled with zeroes when no order). Single SQL pass with GROUP BY.
     *
     * @return array{labels: list<string>, revenue: list<float>, orders: list<int>}
     */
    public function getDailyBuckets(DateRange $range): array
    {
        $sql = 'SELECT DATE(`order`.created_at) AS day, COUNT(*) AS orders_count,
                COALESCE(SUM('.OrderFilters::totalAmountSqlExpression().'), 0) AS revenue
                FROM `order`
                WHERE created_at BETWEEN :from AND :to
                GROUP BY DATE(`order`.created_at)';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute([':from' => $range->fromSql(), ':to' => $range->toSql()]);

        $rows = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $rows[$row['day']] = ['revenue' => (float) $row['revenue'], 'orders' => (int) $row['orders_count']];
        }

        $labels = [];
        $revenue = [];
        $orders = [];
        $cursor = $range->from->setTime(0, 0);
        $end = $range->to->setTime(0, 0);
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('d/m');
            $revenue[] = $rows[$key]['revenue'] ?? 0.0;
            $orders[] = $rows[$key]['orders'] ?? 0;
            $cursor = $cursor->modify('+1 day');
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'orders' => $orders];
    }

    /**
     * Order-count breakdown by status (only statuses with at least one order).
     *
     * @return list<array{id: int, code: string, title: string, color: string, count: int}>
     */
    public function getStatusBreakdown(string $locale): array
    {
        return $this->findStatusesWithCounts($locale);
    }

    public function countUnpaidOlderThan(int $hours): int
    {
        $threshold = (new \DateTimeImmutable())->modify('-'.$hours.' hours')->format('Y-m-d H:i:s');

        return (int) OrderQuery::create()
            ->useOrderStatusQuery()
                ->filterByCode(OrderStatus::CODE_NOT_PAID)
            ->endUse()
            ->filterByCreatedAt($threshold, Criteria::LESS_EQUAL)
            ->count();
    }

    public function countAwaitingShipment(): int
    {
        return (int) OrderQuery::create()
            ->useOrderStatusQuery()
                ->filterByCode([OrderStatus::CODE_PAID, OrderStatus::CODE_PROCESSING], Criteria::IN)
            ->endUse()
            ->count();
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

    /**
     * Adaptive bounds for the amount range slider. Reuses the same total
     * formula as the filter to stay consistent across UI and SQL.
     *
     * @return array{min: float, max: float, step: int}
     */
    public function getAmountBounds(): array
    {
        $sql = 'SELECT MAX(computed) AS max_val FROM (
            SELECT '.OrderFilters::totalAmountSqlExpression().' AS computed
            FROM `order`
        ) AS sub';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $rawMax = (float) ($row['max_val'] ?? 0);

        $niceMax = self::roundUpToNice($rawMax);
        $step = $niceMax >= 5000 ? 100 : ($niceMax >= 1000 ? 50 : 10);

        return ['min' => 0.0, 'max' => (float) $niceMax, 'step' => $step];
    }

    /**
     * Adaptive bounds for the item-count range slider.
     *
     * @return array{min: int, max: int, step: int}
     */
    public function getItemsBounds(): array
    {
        $sql = 'SELECT MAX(item_count) AS max_val FROM (
            SELECT COUNT(*) AS item_count FROM order_product GROUP BY order_id
        ) AS sub';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $rawMax = (int) ($row['max_val'] ?? 0);

        $niceMax = max(10, (int) (ceil(max(1, $rawMax) / 5) * 5));

        return ['min' => 0, 'max' => $niceMax, 'step' => 1];
    }

    private function buildFilteredQuery(OrderFilters $filters): OrderQuery
    {
        $query = OrderQuery::create();
        $filters->applyTo($query);

        return $query;
    }

    private static function roundUpToNice(float $value): int
    {
        if ($value <= 100) {
            return 100;
        }
        if ($value <= 500) {
            return 500;
        }
        if ($value <= 1000) {
            return 1000;
        }
        if ($value <= 5000) {
            return (int) (ceil($value / 500) * 500);
        }

        return (int) (ceil($value / 1000) * 1000);
    }

    /** @return array{previous: ?int, next: ?int} */
    public function findPreviousNext(Order $current): array
    {
        $previous = OrderQuery::create()
            ->filterById($current->getId(), Criteria::LESS_THAN)
            ->filterByStatusId($current->getStatusId(), Criteria::EQUAL)
            ->orderById(Criteria::DESC)
            ->findOne();
        $next = OrderQuery::create()
            ->filterById($current->getId(), Criteria::GREATER_THAN)
            ->filterByStatusId($current->getStatusId(), Criteria::EQUAL)
            ->orderById(Criteria::ASC)
            ->findOne();

        return [
            'previous' => $previous !== null ? (int) $previous->getId() : null,
            'next' => $next !== null ? (int) $next->getId() : null,
        ];
    }
}
