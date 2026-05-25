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
use Thelia\Model\Country;
use Thelia\Model\CountryQuery;

final readonly class CountryRepository
{
    /**
     * @param list<int> $ids
     *
     * @return list<array{id: int, title: string, iso: string}>
     */
    public function findByIdsLocalized(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        $countries = CountryQuery::create()
            ->filterById($ids, Criteria::IN)
            ->find();

        $items = [];
        foreach ($countries as $country) {
            \assert($country instanceof Country);
            $country->setLocale($locale);
            $items[] = [
                'id' => (int) $country->getId(),
                'title' => (string) $country->getTitle(),
                'iso' => (string) $country->getIsoalpha2(),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $items;
    }
}
