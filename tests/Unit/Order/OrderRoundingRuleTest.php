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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Order;

use BackOfficeDefaultTwigBundle\Service\Order\OrderRoundingRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line of the order sheet is shown at the cent Order::getTotalAmount() charges.
 *
 * 1.25 kg at 14.82 is a line of 18.525. The order total rounds it half up, to 18.53;
 * the money formatter rounds a float half to even, and 18.525 is stored a hair
 * below, so a line handed over unrounded reads 18.52 above a total of 18.53.
 */
final class OrderRoundingRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{OrderRoundingRule}>
     */
    public static function provideCurrentRules(): iterable
    {
        yield 'sum of roundings' => [OrderRoundingRule::SumOfRoundings];
        yield 'rounding of sums' => [OrderRoundingRule::RoundingOfSums];
    }

    #[DataProvider('provideCurrentRules')]
    public function testALineSoldByMeasureIsShownAtTheCentTheOrderIsCharged(OrderRoundingRule $rule): void
    {
        $line = $rule->lineTotals(14.82, 0.0, 1.25);

        self::assertSame(18.53, $line['ttc']);
        self::assertSame(18.53, $line['ht']);
        self::assertSame('18,53 €', $this->euros($line['ttc']));
    }

    #[DataProvider('provideCurrentRules')]
    public function testTheTaxOfALineIsTheGapBetweenItsTwoRoundedTotals(OrderRoundingRule $rule): void
    {
        $line = $rule->lineTotals(14.82, 2.964, 1.25);

        self::assertSame(round($line['ttc'] - $line['ht'], 2), $line['tax']);
        self::assertSame('18,53 €', $this->euros($line['ht']));
    }

    /**
     * 0.7 kg at 0.75 is 0.52499999… as a float: PHP 8.4 no longer rounds it up on its own,
     * and the sheet would show 0.52 above an order charged 0.53.
     */
    #[DataProvider('provideCurrentRules')]
    public function testALineWhoseTotalIsNotExactAsAFloatIsShownAtTheCentTheOrderIsCharged(OrderRoundingRule $rule): void
    {
        $line = $rule->lineTotals(0.75, 0.0, 0.7);

        self::assertSame(0.53, $line['ht']);
        self::assertSame(0.53, $line['ttc']);
        self::assertSame(0.0, $line['tax']);
    }

    public function testALineSoldByThePieceKeepsItsAmount(): void
    {
        $line = OrderRoundingRule::SumOfRoundings->lineTotals(12.3456, 2.46912, 3.0);

        self::assertSame(37.05, $line['ht']);
        self::assertSame(44.46, $line['ttc']);
        self::assertSame(7.41, $line['tax']);
    }

    public function testALegacyLineStaysUnroundedLikeTheLegacyTotal(): void
    {
        $line = OrderRoundingRule::Legacy->lineTotals(0.0045, 0.0, 3.0);

        self::assertEqualsWithDelta(0.0135, $line['ttc'], 1e-12);
    }

    private function euros(float $amount): string
    {
        $formatter = new \NumberFormatter('fr_FR', \NumberFormatter::CURRENCY);

        return str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) $formatter->formatCurrency($amount, 'EUR'));
    }
}
