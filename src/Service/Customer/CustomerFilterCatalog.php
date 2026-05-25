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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

use BackOfficeDefaultTwigBundle\Repository\CountryRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;

/**
 * Locale-aware option lists for the customer filter form. Each list is fetched
 * via its dedicated Repository and memoised per locale for the request.
 */
final class CustomerFilterCatalog
{
    /** @var array<string, list<array{id: int, title: string, flag: string, iso: string}>> */
    private array $countriesByLocale = [];

    /** @var list<array{id: int, title: string, code: string}>|null */
    private ?array $langs = null;

    /** @var array<string, list<array{id: int, title: string}>> */
    private array $titlesByLocale = [];

    /** @var array{min: float, max: float, step: int}|null */
    private ?array $totalSpentBounds = null;

    /** @var array{min: int, max: int, step: int}|null */
    private ?array $orderCountBounds = null;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CountryRepository $countries,
    ) {
    }

    /**
     * Decorated with emoji flags. Restricted to countries actually used in at
     * least one customer address, so the dropdown stays meaningful.
     *
     * @return list<array{id: int, title: string, flag: string, iso: string}>
     */
    public function referencedCountries(string $locale): array
    {
        if (isset($this->countriesByLocale[$locale])) {
            return $this->countriesByLocale[$locale];
        }

        $ids = $this->customers->findReferencedCountryIds();
        $countries = $this->countries->findByIdsLocalized($ids, $locale);

        $items = array_map(
            static fn (array $country): array => [
                'id' => $country['id'],
                'title' => $country['title'],
                'flag' => self::countryFlag($country['iso']),
                'iso' => $country['iso'],
            ],
            $countries,
        );

        return $this->countriesByLocale[$locale] = $items;
    }

    /**
     * @return list<array{id: int, title: string, code: string}>
     */
    public function langs(): array
    {
        return $this->langs ??= $this->customers->findLangs();
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function titles(string $locale): array
    {
        return $this->titlesByLocale[$locale] ??= $this->customers->findTitlesLocalized($locale);
    }

    /**
     * @return array{min: float, max: float, step: int}
     */
    public function totalSpentBounds(): array
    {
        return $this->totalSpentBounds ??= $this->customers->getTotalSpentBounds();
    }

    /**
     * @return array{min: int, max: int, step: int}
     */
    public function orderCountBounds(): array
    {
        return $this->orderCountBounds ??= $this->customers->getOrderCountBounds();
    }

    private static function countryFlag(string $iso): string
    {
        if (\strlen($iso) !== 2) {
            return '';
        }

        $upper = strtoupper($iso);
        $offset = 0x1F1E6 - \ord('A');
        $flag = '';
        for ($i = 0, $len = \strlen($upper); $i < $len; ++$i) {
            $flag .= mb_chr(\ord($upper[$i]) + $offset);
        }

        return $flag;
    }
}
