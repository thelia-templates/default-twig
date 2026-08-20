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

namespace BackOfficeDefaultTwigBundle\Service\Order;

use Thelia\Model\ConfigQuery;

/**
 * How a line total is built from a unit amount and a quantity, for one order.
 *
 * The core decides this per order, and the decision is durable on purpose: reading
 * an invoice back under a newer rule would restate an amount the customer has
 * already paid. Two configuration pivots freeze the orders that came before a
 * change of rule, and the rest follow the rule the shop runs today.
 *
 * The pivots and the mode are read raw through ConfigQuery::read() rather than
 * through ConfigQuery::getOrderRoundingMode(): that method answers for one order
 * at a time, so it cannot be consulted from SQL, and reading raw also keeps this
 * template working against a core that predates thelia/thelia#3801, where
 * order_rounding_mode is simply absent and the historical rule applies.
 */
enum OrderRoundingRule
{
    /**
     * Orders placed before Thelia 2.4 were totalled without any rounding at all.
     * Order::getTotalAmountLegacy() still reads them that way.
     */
    case Legacy;

    /**
     * Every unit amount is rounded to the cent before being multiplied by the
     * quantity. Historical Thelia behaviour, and still the default.
     */
    case SumOfRoundings;

    /**
     * The unit amount multiplies the quantity at full precision and only the line
     * total is rounded. What a shop selling by weight or by volume needs: a price
     * stored per gram is nothing once cut to the cent.
     */
    case RoundingOfSums;

    /**
     * Mirrors ConfigQuery::ROUNDING_MODE_* (thelia/thelia#3801).
     */
    private const MODE_SUM_OF_ROUNDINGS = 1;
    private const MODE_ROUNDING_OF_SUMS = 2;

    public static function forOrder(int $orderId): self
    {
        if ($orderId > 0 && $orderId <= self::legacyPivot()) {
            return self::Legacy;
        }

        if ($orderId > 0 && $orderId <= self::sumOfRoundingsPivot()) {
            return self::SumOfRoundings;
        }

        return self::shopRule();
    }

    /**
     * The rule the shop runs today, which every order past both pivots follows.
     */
    public static function shopRule(): self
    {
        return self::MODE_ROUNDING_OF_SUMS === (int) ConfigQuery::read('order_rounding_mode', self::MODE_SUM_OF_ROUNDINGS)
            ? self::RoundingOfSums
            : self::SumOfRoundings;
    }

    /**
     * Id of the last order frozen on the pre-2.4 rule, 0 when the shop has none.
     * Written once by the 2.4 upgrade, so it exists on cores without #3801 too.
     */
    public static function legacyPivot(): int
    {
        return (int) ConfigQuery::read('last_legacy_rounding_order_id', 0);
    }

    /**
     * Id of the last order frozen on the historical rule when the shop switched to
     * rounding of sums, 0 when it never switched.
     */
    public static function sumOfRoundingsPivot(): int
    {
        return (int) ConfigQuery::read('last_sum_of_roundings_order_id', 0);
    }

    /**
     * The three figures of one order line, built so that they add up the way
     * Order::getTotalAmount() adds up the order: summing `ht` over the lines gives
     * the untaxed total, summing `ttc` gives the taxed total, and `tax` is the gap
     * between the two rather than a rounding of its own.
     *
     * @return array{ht: float, tax: float, ttc: float}
     */
    public function lineTotals(float $unitPriceUntaxed, float $unitTax, float $quantity): array
    {
        if (self::RoundingOfSums === $this) {
            $untaxed = round($unitPriceUntaxed * $quantity, 2);
            $taxed = round(($unitPriceUntaxed + $unitTax) * $quantity, 2);

            return ['ht' => $untaxed, 'tax' => round($taxed - $untaxed, 2), 'ttc' => $taxed];
        }

        if (self::SumOfRoundings === $this) {
            $unitPriceUntaxed = round($unitPriceUntaxed, 2);
            $unitTax = round($unitTax, 2);
        }

        $untaxed = $unitPriceUntaxed * $quantity;
        $tax = $unitTax * $quantity;

        return ['ht' => $untaxed, 'tax' => $tax, 'ttc' => $untaxed + $tax];
    }
}
