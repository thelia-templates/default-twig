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

namespace BackOfficeDefaultTwigBundle\Service\OrderReturn;

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;

/**
 * Locale-aware option lists for the return filter form. Each list is fetched
 * via the Repository and memoised per locale for the request.
 */
final class OrderReturnFilterCatalog
{
    /** @var array<string, list<array{id: int, code: string, effective_code: string, title: string, color: string}>> */
    private array $statusesByLocale = [];

    public function __construct(
        private readonly OrderReturnRepository $returns,
    ) {
    }

    /**
     * @return list<array{id: int, code: string, effective_code: string, title: string, color: string}>
     */
    public function statuses(string $locale): array
    {
        return $this->statusesByLocale[$locale] ??= $this->returns->findStatusesLocalized($locale);
    }
}
