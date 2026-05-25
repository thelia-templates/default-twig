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
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

/**
 * Centralised Propel queries for the order back-office screens.
 * Controllers stay thin by delegating filtering, counting and lookups here.
 */
final readonly class OrderRepository
{
    /**
     * @return array{rows: ObjectCollection<int, Order>, total: int, lastPage: int}
     */
    public function findPaginated(int $page, int $perPage, ?int $statusId = null, ?string $search = null): array
    {
        $query = $this->buildQuery($statusId, $search);

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $lastPage));

        $rows = $query
            ->orderByCreatedAt(Criteria::DESC)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->find();

        return ['rows' => $rows, 'total' => $total, 'lastPage' => $lastPage];
    }

    public function findById(int $orderId): ?Order
    {
        return OrderQuery::create()->findPk($orderId);
    }

    /**
     * @return ObjectCollection<int, OrderStatus>
     */
    public function findAllStatuses(): ObjectCollection
    {
        return OrderStatusQuery::create()->orderById()->find();
    }

    /**
     * Statuses that currently host at least one order, with their localised
     * title, native colour and order count. Used by the sidebar counters.
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

    private function buildQuery(?int $statusId, ?string $search): OrderQuery
    {
        $query = OrderQuery::create();

        if ($statusId !== null && $statusId > 0) {
            $query->filterByStatusId($statusId);
        }

        if ($search !== null && $search !== '') {
            $needle = '%'.$search.'%';
            $query
                ->_or()
                ->filterByRef($needle, Criteria::LIKE)
                ->_or()
                ->useCustomerQuery()
                    ->where(Customer::TABLE_MAP.'.firstname LIKE ?', $needle, \PDO::PARAM_STR)
                    ->_or()
                    ->where(Customer::TABLE_MAP.'.lastname LIKE ?', $needle, \PDO::PARAM_STR)
                    ->_or()
                    ->where(Customer::TABLE_MAP.'.email LIKE ?', $needle, \PDO::PARAM_STR)
                ->endUse();
        }

        return $query;
    }
}
