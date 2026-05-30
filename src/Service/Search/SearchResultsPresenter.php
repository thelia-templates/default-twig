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

namespace BackOfficeDefaultTwigBundle\Service\Search;

use BackOfficeDefaultTwigBundle\Repository\SearchRepository;

final readonly class SearchResultsPresenter
{
    public function __construct(
        private SearchRepository $search,
    ) {
    }

    /**
     * @return array{term: string, products: list<array<string, mixed>>, categories: list<array<string, mixed>>, folders: list<array<string, mixed>>, contents: list<array<string, mixed>>, brands: list<array<string, mixed>>, customers: list<array<string, mixed>>, orders: list<array<string, mixed>>}
     */
    public function buildResults(string $term, string $locale): array
    {
        $results = [
            'term' => $term,
            'products' => [],
            'categories' => [],
            'folders' => [],
            'contents' => [],
            'brands' => [],
            'customers' => [],
            'orders' => [],
        ];

        if ($term === '') {
            return $results;
        }

        foreach ($this->search->findProducts($term) as $product) {
            $product->setLocale($locale);
            $results['products'][] = [
                'id' => (int) $product->getId(),
                'title' => (string) $product->getTitle(),
                'ref' => (string) $product->getRef(),
            ];
        }

        foreach ($this->search->findCategories($term) as $category) {
            $category->setLocale($locale);
            $results['categories'][] = [
                'id' => (int) $category->getId(),
                'title' => (string) $category->getTitle(),
            ];
        }

        foreach ($this->search->findFolders($term) as $folder) {
            $folder->setLocale($locale);
            $results['folders'][] = [
                'id' => (int) $folder->getId(),
                'title' => (string) $folder->getTitle(),
            ];
        }

        foreach ($this->search->findContents($term) as $content) {
            $content->setLocale($locale);
            $results['contents'][] = [
                'id' => (int) $content->getId(),
                'title' => (string) $content->getTitle(),
            ];
        }

        foreach ($this->search->findBrands($term) as $brand) {
            $brand->setLocale($locale);
            $results['brands'][] = [
                'id' => (int) $brand->getId(),
                'title' => (string) $brand->getTitle(),
            ];
        }

        foreach ($this->search->findCustomers($term) as $customer) {
            $results['customers'][] = [
                'id' => (int) $customer->getId(),
                'firstname' => (string) $customer->getFirstname(),
                'lastname' => (string) $customer->getLastname(),
                'email' => (string) $customer->getEmail(),
                'ref' => (string) $customer->getRef(),
            ];
        }

        foreach ($this->search->findOrders($term) as $order) {
            $results['orders'][] = [
                'id' => (int) $order->getId(),
                'ref' => (string) $order->getRef(),
            ];
        }

        return $results;
    }
}
