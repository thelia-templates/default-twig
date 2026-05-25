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

namespace BackOfficeDefaultTwigBundle\UiComponents\Pagination;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'BoPagination', template: '@BackOfficeDefaultTwig/components/Pagination/Pagination.html.twig')]
final class Pagination
{
    public string $route;

    public int $currentPage;

    public int $lastPage;

    /** @var array<string, scalar|null> */
    public array $routeParams = [];

    public string $pageParam = 'page';

    public int $window = 2;

    public string $align = 'center';

    public ?string $ariaLabel = null;

    public ?string $testid = null;

    /**
     * Page numbers to display, with null marking an ellipsis. Always keeps the first and last page.
     *
     * @return list<int|null>
     */
    public function getPages(): array
    {
        $start = max(1, $this->currentPage - $this->window);
        $end = min($this->lastPage, $this->currentPage + $this->window);

        $pages = [];

        if ($start > 1) {
            $pages[] = 1;
            if ($start > 2) {
                $pages[] = null;
            }
        }

        for ($page = $start; $page <= $end; ++$page) {
            $pages[] = $page;
        }

        if ($end < $this->lastPage) {
            if ($end < $this->lastPage - 1) {
                $pages[] = null;
            }
            $pages[] = $this->lastPage;
        }

        return $pages;
    }

    /**
     * Route parameters for a given page, dropping empty filters so the URL stays clean.
     *
     * @return array<string, scalar>
     */
    public function linkParams(int $page): array
    {
        $params = array_filter(
            $this->routeParams,
            static fn (mixed $value): bool => null !== $value && '' !== $value,
        );

        $params[$this->pageParam] = $page;

        return $params;
    }
}
