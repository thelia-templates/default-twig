<?php

declare(strict_types=1);

namespace BackOfficeDefaultTwigBundle\UiComponents\Dashboard;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DashboardData;
use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\Service\Dashboard\DashboardStatsProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Model\Lang;

#[AsTwigComponent(name: 'BoDashboard', template: '@BackOfficeDefaultTwig/components/Dashboard/Dashboard.html.twig')]
final class Dashboard
{
    public string $period = DateRange::PRESET_THIRTY_DAYS;

    public function __construct(
        private readonly DashboardStatsProvider $statsProvider,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getData(): DashboardData
    {
        $request = $this->requestStack->getCurrentRequest();
        $preset = $request?->query->get('period', $this->period) ?? $this->period;
        $range = DateRange::fromPreset((string) $preset);
        $locale = $request?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();

        return $this->statsProvider->compute($range, $locale);
    }
}
