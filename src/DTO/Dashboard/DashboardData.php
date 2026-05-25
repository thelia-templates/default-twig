<?php

declare(strict_types=1);

namespace BackOfficeDefaultTwigBundle\DTO\Dashboard;

use Thelia\Model\Order;
use Thelia\Model\ProductSaleElements;

final readonly class DashboardData
{
    /**
     * @param list<Kpi>                                                                              $kpis
     * @param array{labels: list<string>, revenue: list<float>, orders: list<int>}                   $chart
     * @param list<array{id: int, code: string, title: string, color: string, count: int}>          $statusBreakdown
     * @param list<Order>                                                                            $recentOrders
     * @param list<array{id: int, ref: string, title: string, quantity: int, revenue: float}>       $topProducts
     * @param list<ProductSaleElements>                                                              $lowStockProducts
     * @param list<array{label: string, count: int, href: ?string, icon: string, level: string}>    $alerts
     * @param list<array{value: string, label: string, active: bool, url: string}>                  $periodOptions
     */
    public function __construct(
        public DateRange $range,
        public array $kpis,
        public array $chart,
        public array $statusBreakdown,
        public array $recentOrders,
        public array $topProducts,
        public array $lowStockProducts,
        public int $lowStockThreshold,
        public array $alerts,
        public array $periodOptions,
        public string $locale,
    ) {
    }
}
