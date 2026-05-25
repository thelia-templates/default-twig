<?php

declare(strict_types=1);

namespace BackOfficeDefaultTwigBundle\Service\Dashboard;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DashboardData;
use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\DTO\Dashboard\Kpi;
use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Repository\ProductRepository;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\OrderQuery;

/**
 * Composes every dashboard widget (KPIs, chart series, breakdowns, alerts) for
 * a given date range. Repository queries are cheap (indexed SUM/GROUP BY) so
 * we re-run them on every render; cache layer can be added later if needed.
 */
final readonly class DashboardStatsProvider
{
    private const UNPAID_FOLLOWUP_HOURS = 48;
    private const RECENT_ORDERS_LIMIT = 8;
    private const TOP_PRODUCTS_LIMIT = 5;
    private const LOW_STOCK_LIMIT = 5;
    private const LOW_STOCK_THRESHOLD = 5;

    public function __construct(
        private OrderRepository $orders,
        private CustomerRepository $customers,
        private ProductRepository $products,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function compute(DateRange $range, string $locale): DashboardData
    {
        $previous = $range->previous();

        $revenue = $this->orders->getRevenue($range);
        $orderCount = $this->orders->countOrders($range);
        $previousRevenue = $this->orders->getRevenue($previous);
        $previousOrderCount = $this->orders->countOrders($previous);

        $newCustomers = $this->customers->countNew($range);
        $previousNewCustomers = $this->customers->countNew($previous);

        $aov = $orderCount > 0 ? $revenue / $orderCount : 0.0;
        $previousAov = $previousOrderCount > 0 ? $previousRevenue / $previousOrderCount : 0.0;

        $kpis = [
            new Kpi(
                label: $this->translator->trans('Revenue'),
                value: $this->formatCurrency($revenue),
                variationPercent: $this->variation($revenue, $previousRevenue),
                icon: 'bi-cash-stack',
                accent: 'primary',
                href: $this->urls->generate('admin.order.list'),
                testid: 'kpi-revenue',
            ),
            new Kpi(
                label: $this->translator->trans('Orders'),
                value: (string) $orderCount,
                variationPercent: $this->variation((float) $orderCount, (float) $previousOrderCount),
                icon: 'bi-cart-check',
                accent: 'info',
                href: $this->urls->generate('admin.order.list'),
                testid: 'kpi-orders',
            ),
            new Kpi(
                label: $this->translator->trans('Average order'),
                value: $this->formatCurrency($aov),
                variationPercent: $this->variation($aov, $previousAov),
                icon: 'bi-receipt',
                accent: 'success',
                testid: 'kpi-aov',
            ),
            new Kpi(
                label: $this->translator->trans('New customers'),
                value: (string) $newCustomers,
                variationPercent: $this->variation((float) $newCustomers, (float) $previousNewCustomers),
                icon: 'bi-person-plus',
                accent: 'warning',
                href: $this->urls->generate('admin.customers'),
                testid: 'kpi-new-customers',
            ),
        ];

        return new DashboardData(
            range: $range,
            kpis: $kpis,
            chart: $this->orders->getDailyBuckets($range),
            statusBreakdown: $this->orders->getStatusBreakdown($locale),
            recentOrders: $this->loadRecentOrders(),
            topProducts: $this->products->findTopSellers($range, self::TOP_PRODUCTS_LIMIT, $locale),
            lowStockProducts: $this->products->findLowStock(self::LOW_STOCK_THRESHOLD, self::LOW_STOCK_LIMIT, $locale),
            lowStockThreshold: self::LOW_STOCK_THRESHOLD,
            alerts: $this->buildAlerts(),
            periodOptions: $this->buildPeriodOptions($range),
            locale: $locale,
        );
    }

    /**
     * @return list<\Thelia\Model\Order>
     */
    private function loadRecentOrders(): array
    {
        return array_values(iterator_to_array(
            OrderQuery::create()
                ->joinWithCustomer()
                ->orderByCreatedAt(Criteria::DESC)
                ->limit(self::RECENT_ORDERS_LIMIT)
                ->find(),
        ));
    }

    /**
     * @return list<array{label: string, count: int, href: ?string, icon: string, level: string}>
     */
    private function buildAlerts(): array
    {
        $alerts = [];

        $unpaid = $this->orders->countUnpaidOlderThan(self::UNPAID_FOLLOWUP_HOURS);
        if ($unpaid > 0) {
            $alerts[] = [
                'label' => $this->translator->trans('%count% unpaid orders over 48h', ['%count%' => $unpaid]),
                'count' => $unpaid,
                'href' => $this->urls->generate('admin.order.list', ['status_ids' => [1]]),
                'icon' => 'bi-exclamation-triangle-fill',
                'level' => 'danger',
            ];
        }

        $awaiting = $this->orders->countAwaitingShipment();
        if ($awaiting > 0) {
            $alerts[] = [
                'label' => $this->translator->trans('%count% orders awaiting shipment', ['%count%' => $awaiting]),
                'count' => $awaiting,
                'href' => $this->urls->generate('admin.order.list', ['status_ids' => [2, 3]]),
                'icon' => 'bi-truck',
                'level' => 'info',
            ];
        }

        return $alerts;
    }

    /**
     * @return list<array{value: string, label: string, active: bool, url: string}>
     */
    private function buildPeriodOptions(DateRange $current): array
    {
        $labels = [
            DateRange::PRESET_TODAY => $this->translator->trans('Today'),
            DateRange::PRESET_SEVEN_DAYS => $this->translator->trans('7 days'),
            DateRange::PRESET_THIRTY_DAYS => $this->translator->trans('30 days'),
            DateRange::PRESET_NINETY_DAYS => $this->translator->trans('90 days'),
            DateRange::PRESET_THIS_MONTH => $this->translator->trans('This month'),
            DateRange::PRESET_THIS_YEAR => $this->translator->trans('This year'),
        ];

        $options = [];
        foreach (DateRange::ALLOWED_PRESETS as $preset) {
            $options[] = [
                'value' => $preset,
                'label' => $labels[$preset],
                'active' => $preset === $current->preset,
                'url' => $this->urls->generate('admin.home', ['period' => $preset]),
            ];
        }

        return $options;
    }

    private function variation(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return $current > 0.0 ? null : 0.0;
        }

        return (($current - $previous) / $previous) * 100;
    }

    private function formatCurrency(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' €';
    }
}
