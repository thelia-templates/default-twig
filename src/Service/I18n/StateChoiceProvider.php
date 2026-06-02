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

namespace BackOfficeDefaultTwigBundle\Service\I18n;

use Thelia\Model\StateQuery;

final readonly class StateChoiceProvider
{
    /**
     * @return list<array{id: int, country_id: int, title: string}>
     */
    public function forLocale(?string $locale = null): array
    {
        $states = [];
        foreach (StateQuery::create()->filterByVisible(1)->orderByCountryId()->find() as $state) {
            if ($locale !== null) {
                $state->setLocale($locale);
            }
            $states[] = [
                'id' => (int) $state->getId(),
                'country_id' => (int) $state->getCountryId(),
                'title' => (string) $state->getTitle(),
            ];
        }

        return $states;
    }
}
