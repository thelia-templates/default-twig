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

namespace BackOfficeDefaultTwigBundle\Tests\Support;

use Thelia\Model\ProductSaleElements;

/**
 * The fields of the combinations grid as the page posts them: the ids of the
 * combinations in a list, every other field named by the id of its combination.
 */
final class CombinationGridFields
{
    /**
     * @param list<array{ProductSaleElements, array<string, mixed>}> $rows
     *
     * @return array<string, array<int, mixed>>
     */
    public static function of(array $rows): array
    {
        $fields = ['product_sale_element_id' => []];
        foreach ($rows as [$combination, $values]) {
            $id = (int) $combination->getId();
            $fields['product_sale_element_id'][] = $id;
            foreach ($values as $name => $value) {
                $fields[$name][$id] = $value;
            }
        }

        return $fields;
    }
}
