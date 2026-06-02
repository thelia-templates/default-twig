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
