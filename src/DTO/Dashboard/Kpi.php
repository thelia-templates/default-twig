<?php

declare(strict_types=1);

namespace BackOfficeDefaultTwigBundle\DTO\Dashboard;

final readonly class Kpi
{
    public function __construct(
        public string $label,
        public string $value,
        public ?float $variationPercent,
        public string $icon,
        public string $accent,
        public ?string $href = null,
        public ?string $testid = null,
    ) {
    }

    public function variationDirection(): string
    {
        return match (true) {
            $this->variationPercent === null => 'flat',
            $this->variationPercent > 0.5 => 'up',
            $this->variationPercent < -0.5 => 'down',
            default => 'flat',
        };
    }
}
