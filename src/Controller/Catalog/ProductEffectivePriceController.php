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

namespace BackOfficeDefaultTwigBundle\Controller\Catalog;

use BackOfficeDefaultTwigBundle\Repository\CatalogRestrictionRepository;
use BackOfficeDefaultTwigBundle\Repository\CurrencyRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\CatalogRestriction\BoundedSearch;
use BackOfficeDefaultTwigBundle\Service\Product\EffectivePricePresenter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ProductQuery;
use Twig\Environment;

/**
 * The effective prices of a product for one customer, or for a visitor: what the shop
 * would charge them for each combination, and why. A read only panel of the combinations
 * tab, which shows what a customer pays: it needs the right to see the pricing AND the
 * right to see the customers. The customer is picked by a search of twenty answers at most,
 * never from a directory.
 */
final class ProductEffectivePriceController
{
    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly CurrencyRepository $currencies,
        private readonly CatalogRestrictionRepository $restrictions,
        private readonly CustomerRepository $customers,
        private readonly CustomerGroupRepository $customerGroups,
        private readonly EffectivePricePresenter $presenter,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/admin/products/effective-prices/panel', name: 'admin.product.effective-prices.panel', methods: ['GET'])]
    public function panel(Request $request): Response
    {
        if ($denied = $this->access->check([AdminResources::PRICING, AdminResources::CUSTOMER], [], AccessManager::VIEW)) {
            return $denied;
        }

        $productId = (int) ($request->attributes->get('product_id') ?? $request->query->get('product_id', 0));
        $product = ProductQuery::create()->findPk($productId);

        if (null === $product) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // Rendered by the combinations tab, a sub-request that does not carry the query of the page: the choice is read there.
        $query = ($this->requests->getMainRequest() ?? $request)->query;
        $currencyId = (int) ($request->attributes->get('currency_id') ?? $request->query->get('currency_id', 0));
        $currency = $this->currencies->find($currencyId > 0 ? $currencyId : (int) $query->get('combinations_currency_id', 0)) ?? $this->currencies->findDefault();

        if (null === $currency) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $locale = $request->getLocale();
        // The field stops at 100 characters; a longer term typed in the address goes no further into the search.
        $search = mb_substr(trim((string) $query->get('effective_customer_q', '')), 0, 100);
        $customerId = (int) $query->get('effective_customer_id', 0);
        $results = 0 === $customerId ? $this->customers->findMatchingForPrices($search) : [];
        // A search that finds a single customer opens it.
        if (1 === \count($results)) {
            $customerId = $results[0]['id'];
            $results = [];
        }
        $customer = $customerId > 0 ? $this->restrictions->customer($customerId) : null;
        $visitor = '1' === (string) $query->get('effective_visitor', '');
        $subject = null !== $customer ? 'customer' : ($visitor ? 'visitor' : null);
        if ([] !== $results) {
            $groups = array_column($this->customerGroups->findChoices($locale), 'label', 'id');
            $results = array_map(static fn (array $result): array => $result + ['group' => $groups[$result['group_id']] ?? ''], $results);
        }

        $prices = null === $subject ? null : $this->presenter->present($product, $currency, $customer, $locale);

        return new Response($this->twig->render('@BackOfficeDefaultTwig/catalog/product/_effective_prices.html.twig', [
            'product_id' => $productId,
            'currency' => $currency,
            'subject' => $subject,
            'customer_label' => null === $customer ? null : ($this->restrictions->customerLabels([$customerId])[$customerId] ?? '#'.$customerId),
            'search' => $search,
            'results' => $results,
            'search_minimum' => BoundedSearch::MINIMUM_LENGTH,
            'search_limit' => BoundedSearch::LIMIT,
            'prices' => $prices,
        ]));
    }
}
