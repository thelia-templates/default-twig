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

namespace BackOfficeDefaultTwigBundle\Service\Sale;

use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\Sale;
use Thelia\Model\SaleProduct;

final readonly class SaleProductAttributesProvider
{
    /**
     * @return list<array{
     *     attribute: array{id: int, title: string},
     *     avs: list<array{id: int, title: string}>
     * }>
     */
    public function optionsForProduct(int $productId, string $locale): array
    {
        $combinations = AttributeCombinationQuery::create()
            ->useProductSaleElementsQuery()
                ->filterByProductId($productId)
            ->endUse()
            ->find();

        $avIds = array_values(array_unique(array_filter(array_map(
            static fn ($combination) => (int) $combination->getAttributeAvId(),
            iterator_to_array($combinations),
        ))));

        if ($avIds === []) {
            return [];
        }

        $avs = AttributeAvQuery::create()
            ->filterById($avIds, \Propel\Runtime\ActiveQuery\Criteria::IN)
            ->joinWithAttribute()
            ->orderByPosition()
            ->find();

        $grouped = [];
        foreach ($avs as $av) {
            \assert($av instanceof AttributeAv);
            $attribute = $av->getAttribute();
            if ($attribute === null) {
                continue;
            }
            $attribute->setLocale($locale);
            $av->setLocale($locale);

            $attributeId = (int) $attribute->getId();
            if (!isset($grouped[$attributeId])) {
                $grouped[$attributeId] = [
                    'attribute' => ['id' => $attributeId, 'title' => (string) $attribute->getTitle()],
                    'avs' => [],
                ];
            }
            $grouped[$attributeId]['avs'][] = ['id' => (int) $av->getId(), 'title' => (string) $av->getTitle()];
        }

        return array_values($grouped);
    }

    /**
     * @return array<int, list<int>> productId → list of selected attribute_av_id
     */
    public function selectedForSale(Sale $sale): array
    {
        $selection = [];
        foreach ($sale->getSaleProductList() as $saleProduct) {
            \assert($saleProduct instanceof SaleProduct);
            $avId = $saleProduct->getAttributeAvId();
            if ($avId === null) {
                continue;
            }
            $productId = (int) $saleProduct->getProductId();
            $selection[$productId] ??= [];
            $selection[$productId][] = (int) $avId;
        }

        return $selection;
    }
}
