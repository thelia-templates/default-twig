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

namespace BackOfficeDefaultTwigBundle\Controller;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Search\SearchResultsPresenter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\LangQuery;
use Twig\Environment;

final class SearchController
{
    private const SEARCH_RESOURCES = ['admin.product', 'admin.category', 'admin.customer', 'admin.order'];

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly SearchResultsPresenter $presenter,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/admin/search', name: 'admin.search', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->isDenied()) {
            return new Response($this->twig->render('@BackOfficeDefaultTwig/general_error.html.twig'), Response::HTTP_FORBIDDEN);
        }

        $term = trim((string) $request->query->get('search_term', ''));
        $results = $this->presenter->buildResults($term, $this->defaultLocale());

        return new Response($this->twig->render('@BackOfficeDefaultTwig/search/index.html.twig', $results));
    }

    private function isDenied(): bool
    {
        foreach (self::SEARCH_RESOURCES as $resource) {
            if ($this->access->check($resource, [], AccessManager::VIEW) === null) {
                return false;
            }
        }

        return true;
    }

    private function defaultLocale(): string
    {
        return LangQuery::create()->findOneByByDefault(true)?->getLocale() ?? 'en_US';
    }
}
