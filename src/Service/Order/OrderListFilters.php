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

namespace BackOfficeDefaultTwigBundle\Service\Order;

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves filter query parameters of the order list screen into ready-to-use
 * values (sort field, direction, "since" datetime, available chips). Keeps the
 * controller free from this branching logic.
 */
final readonly class OrderListFilters
{
    public const PERIOD_ALL = 'all';
    public const PERIOD_TODAY = 'today';
    public const PERIOD_WEEK = 'week';
    public const DEFAULT_SORT = 'created_at';
    public const DEFAULT_DIRECTION = 'desc';
    public const ALLOWED_DIRECTIONS = ['asc', 'desc'];

    public const CHIPS = [
        ['code' => self::PERIOD_ALL, 'label' => 'All orders', 'icon' => 'bi-list-ul'],
        ['code' => self::PERIOD_TODAY, 'label' => 'Today', 'icon' => 'bi-sun'],
        ['code' => self::PERIOD_WEEK, 'label' => 'Last 7 days', 'icon' => 'bi-calendar-week'],
    ];

    /**
     * @return array{
     *     period: string,
     *     created_since: ?\DateTimeImmutable,
     *     order: string,
     *     direction: string,
     *     chips: list<array{code: string, label: string, icon: string}>
     * }
     */
    public function fromRequest(Request $request): array
    {
        $period = (string) $request->query->get('period', self::PERIOD_ALL);
        if (!\in_array($period, [self::PERIOD_ALL, self::PERIOD_TODAY, self::PERIOD_WEEK], true)) {
            $period = self::PERIOD_ALL;
        }

        $orderBy = (string) $request->query->get('order', self::DEFAULT_SORT);
        if (!\array_key_exists($orderBy, OrderRepository::SORT_FIELDS)) {
            $orderBy = self::DEFAULT_SORT;
        }

        $direction = strtolower((string) $request->query->get('direction', self::DEFAULT_DIRECTION));
        if (!\in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = self::DEFAULT_DIRECTION;
        }

        return [
            'period' => $period,
            'created_since' => $this->periodToDate($period),
            'order' => $orderBy,
            'direction' => $direction,
            'chips' => self::CHIPS,
        ];
    }

    private function periodToDate(string $period): ?\DateTimeImmutable
    {
        return match ($period) {
            self::PERIOD_TODAY => new \DateTimeImmutable('today'),
            self::PERIOD_WEEK => new \DateTimeImmutable('-7 days'),
            default => null,
        };
    }
}
