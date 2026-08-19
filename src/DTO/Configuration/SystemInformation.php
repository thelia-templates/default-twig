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

namespace BackOfficeDefaultTwigBundle\DTO\Configuration;

/**
 * Full system information snapshot. `toPlainText()` produces the untranslated,
 * English summary rendered into the copy-to-clipboard source, so what is copied
 * derives from the same object that is displayed and can never diverge from it.
 */
final readonly class SystemInformation
{
    /**
     * @param list<SystemInformationSection> $sections
     */
    public function __construct(
        public array $sections,
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    public function toPlainText(): string
    {
        $lines = ['Thelia system information - '.$this->generatedAt->format('Y-m-d H:i')];

        foreach ($this->sections as $section) {
            $lines[] = '';
            $lines[] = $section->title;

            if (SystemInformationSection::KEY_PHP_EXTENSIONS === $section->key) {
                $lines = [...$lines, ...$this->extensionLines($section)];

                continue;
            }

            foreach ($section->items as $item) {
                $lines[] = $item->label.': '.$this->plainValue($item);
            }
        }

        return implode("\n", $lines);
    }

    private function plainValue(SystemInformationItem $item): string
    {
        $value = $item->value;

        if (null !== $item->badge) {
            $value = '' !== $value ? $value.' ('.$item->badge.')' : $item->badge;
        }

        return '' !== $value ? $value : 'unavailable';
    }

    /**
     * @return list<string>
     */
    private function extensionLines(SystemInformationSection $section): array
    {
        $loaded = [];
        $missing = [];
        foreach ($section->items as $item) {
            if (SystemInformationItem::STATUS_OK === $item->status) {
                $loaded[] = $item->value;
            } else {
                $missing[] = $item->value;
            }
        }

        return [
            'Loaded: '.($loaded ? implode(', ', $loaded) : 'none'),
            'Missing: '.($missing ? implode(', ', $missing) : 'none'),
        ];
    }
}
