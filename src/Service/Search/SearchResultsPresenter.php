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
use Thelia\Model\Brand;
use Thelia\Model\Category;
use Thelia\Model\Content;
use Thelia\Model\Customer;
use Thelia\Model\Folder;
use Thelia\Model\Order;
use Thelia\Model\Product;

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
            \assert($product instanceof Product);
            $product->setLocale($locale);
            $results['products'][] = [
                'id' => (int) $product->getId(),
                'title' => (string) $product->getTitle(),
                'ref' => (string) $product->getRef(),
            ];
        }

        foreach ($this->search->findCategories($term) as $category) {
            \assert($category instanceof Category);
            $category->setLocale($locale);
            $results['categories'][] = [
                'id' => (int) $category->getId(),
                'title' => (string) $category->getTitle(),
            ];
        }

        foreach ($this->search->findFolders($term) as $folder) {
            \assert($folder instanceof Folder);
            $folder->setLocale($locale);
            $results['folders'][] = [
                'id' => (int) $folder->getId(),
                'title' => (string) $folder->getTitle(),
            ];
        }

        foreach ($this->search->findContents($term) as $content) {
            \assert($content instanceof Content);
            $content->setLocale($locale);
            $results['contents'][] = [
                'id' => (int) $content->getId(),
                'title' => (string) $content->getTitle(),
            ];
        }

        foreach ($this->search->findBrands($term) as $brand) {
            \assert($brand instanceof Brand);
            $brand->setLocale($locale);
            $results['brands'][] = [
                'id' => (int) $brand->getId(),
                'title' => (string) $brand->getTitle(),
            ];
        }

        foreach ($this->search->findCustomers($term) as $customer) {
            \assert($customer instanceof Customer);
            $results['customers'][] = [
                'id' => (int) $customer->getId(),
                'firstname' => (string) $customer->getFirstname(),
                'lastname' => (string) $customer->getLastname(),
                'email' => (string) $customer->getEmail(),
                'ref' => (string) $customer->getRef(),
            ];
        }

        foreach ($this->search->findOrders($term) as $order) {
            \assert($order instanceof Order);
            $results['orders'][] = [
                'id' => (int) $order->getId(),
                'ref' => (string) $order->getRef(),
            ];
        }

        return $results;
    }
}
