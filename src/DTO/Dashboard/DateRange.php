<?php

declare(strict_types=1);

namespace BackOfficeDefaultTwigBundle\DTO\Dashboard;

/**
 * Inclusive datetime range used by every dashboard analytics query. `previous()`
 * returns the same-length window immediately before $from for variation maths.
 */
final readonly class DateRange
{
    public const PRESET_TODAY = 'today';
    public const PRESET_SEVEN_DAYS = '7days';
    public const PRESET_THIRTY_DAYS = '30days';
    public const PRESET_NINETY_DAYS = '90days';
    public const PRESET_THIS_MONTH = 'month';
    public const PRESET_THIS_YEAR = 'year';

    public const ALLOWED_PRESETS = [
        self::PRESET_TODAY,
        self::PRESET_SEVEN_DAYS,
        self::PRESET_THIRTY_DAYS,
        self::PRESET_NINETY_DAYS,
        self::PRESET_THIS_MONTH,
        self::PRESET_THIS_YEAR,
    ];

    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $preset,
    ) {
    }

    public static function fromPreset(string $preset): self
    {
        $preset = \in_array($preset, self::ALLOWED_PRESETS, true) ? $preset : self::PRESET_THIRTY_DAYS;
        $now = new \DateTimeImmutable('now');
        $endOfDay = $now->setTime(23, 59, 59);

        [$from, $to] = match ($preset) {
            self::PRESET_TODAY => [$now->setTime(0, 0), $endOfDay],
            self::PRESET_SEVEN_DAYS => [$now->modify('-6 days')->setTime(0, 0), $endOfDay],
            self::PRESET_THIRTY_DAYS => [$now->modify('-29 days')->setTime(0, 0), $endOfDay],
            self::PRESET_NINETY_DAYS => [$now->modify('-89 days')->setTime(0, 0), $endOfDay],
            self::PRESET_THIS_MONTH => [$now->modify('first day of this month')->setTime(0, 0), $endOfDay],
            self::PRESET_THIS_YEAR => [$now->modify('first day of January')->setTime(0, 0), $endOfDay],
            default => [$now->modify('-29 days')->setTime(0, 0), $endOfDay],
        };

        return new self($from, $to, $preset);
    }

    public function previous(): self
    {
        $duration = $this->from->diff($this->to->modify('+1 second'));
        $previousTo = $this->from->modify('-1 second');
        $previousFrom = $previousTo->sub($duration)->modify('+1 second');

        return new self($previousFrom, $previousTo, $this->preset);
    }

    public function days(): int
    {
        return max(1, (int) $this->from->diff($this->to)->format('%a') + 1);
    }

    public function cacheKey(string $prefix): string
    {
        return 'bo_dashboard_'.$prefix.'_'.$this->preset
            .'_'.$this->from->format('Ymd')
            .'_'.$this->to->format('Ymd');
    }

    public function fromSql(): string
    {
        return $this->from->format('Y-m-d H:i:s');
    }

    public function toSql(): string
    {
        return $this->to->format('Y-m-d H:i:s');
    }
}
