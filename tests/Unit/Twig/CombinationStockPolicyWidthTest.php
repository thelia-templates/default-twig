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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;

/**
 * The select of the out-of-stock policy of a combination sits in a block of its own, wider
 * than the 11rem column of the restock date and the threshold, which cut "Vendre jusqu'à une li…".
 */
final class CombinationStockPolicyWidthTest extends TestCase
{
    public function testThePolicyBlockCarriesTheClassTheStyleWidens(): void
    {
        $root = \dirname(__DIR__, 3);
        $template = (string) file_get_contents($root.'/catalog/product/_combination_stock_fields.html.twig');
        $style = (string) file_get_contents($root.'/assets/styles/main.scss');

        self::assertMatchesRegularExpression('/<div class="bo-combinations-grid__stock-policy" data-controller="bo-stock-policy"/', $template);
        self::assertSame(1, preg_match('/> div\.bo-combinations-grid__stock-policy \{\s*width: min\(100%, (\d+)rem\);/', $style, $match), 'The style widens the policy block.');
        self::assertGreaterThanOrEqual(17, (int) $match[1], 'Wide enough for the longest policy wording in a small select.');
    }
}
