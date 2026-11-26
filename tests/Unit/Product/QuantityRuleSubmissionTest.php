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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Product;

use BackOfficeDefaultTwigBundle\Service\Product\QuantityRuleSubmission;
use BackOfficeDefaultTwigBundle\Service\Product\UnknownUnitCodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Translation\IdentityTranslator;
use Thelia\Domain\Catalog\Quantity\InvalidQuantityRuleException;
use Thelia\Domain\Catalog\Quantity\QuantityPolicy;
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Domain\Catalog\Quantity\UnitCode;
use Thelia\Domain\Catalog\Quantity\UnreadableQuantityException;

/**
 * The quantity rules of a combination as the combinations tab posts them: read with a comma
 * as well as a dot, judged by the core, keyed by the combination they belong to.
 */
final class QuantityRuleSubmissionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(QuantityPolicy::class)) {
            self::markTestSkipped('The installed core predates the quantity rules of a combination.');
        }
    }

    public function testARowThatPostsNoneOfItsFieldsKeepsItsRules(): void
    {
        $form = new InputBag(['min_quantity' => [12 => '2'], 'quantity_step' => [12 => '6']]);

        self::assertNull($this->submission()->fromGrid($form, 13));
    }

    public function testEachRowReadsTheFieldsKeyedByItsOwnCombination(): void
    {
        $form = new InputBag([
            'min_quantity' => [12 => '2', 13 => '0,5'],
            'quantity_step' => [12 => '6', 13 => '0,25'],
            'quantity_decimals' => [12 => '0', 13 => '2'],
            'unit_quantity' => [12 => '0,75', 13 => ''],
            'reference_unit_quantity' => [12 => '1', 13 => ''],
            'unit_code' => [12 => 'l', 13 => 'kg'],
        ]);

        $bottle = $this->submission()->fromGrid($form, 12);
        self::assertNotNull($bottle);
        self::assertSame([2.0, 6.0, 0, 0.75, 1.0, UnitCode::LITRE], [$bottle->minQuantity, $bottle->step, $bottle->decimals, $bottle->unitQuantity, $bottle->referenceUnitQuantity, $bottle->unitCode]);

        $weighed = $this->submission()->fromGrid($form, 13);
        self::assertNotNull($weighed);
        self::assertSame([0.5, 0.25, 2, null, null, UnitCode::KILOGRAM], [$weighed->minQuantity, $weighed->step, $weighed->decimals, $weighed->unitQuantity, $weighed->referenceUnitQuantity, $weighed->unitCode]);
    }

    public function testEmptyFieldsAreTheRulesOfAProductSoldByThePiece(): void
    {
        $rule = $this->submission()->fromGrid(new InputBag(['min_quantity' => [12 => ''], 'quantity_step' => [12 => ' '], 'quantity_decimals' => [12 => ''], 'unit_code' => [12 => '']]), 12);

        self::assertNotNull($rule);
        self::assertSame([1.0, 1.0, 0, null], [$rule->minQuantity, $rule->step, $rule->decimals, $rule->unitCode]);
    }

    /**
     * @return iterable<string, array{array<string, string>, class-string<\Throwable>}>
     */
    public static function refusedRules(): iterable
    {
        yield 'a step of zero' => [['quantity_step' => '0'], InvalidQuantityRuleException::class];
        yield 'a negative minimum' => [['min_quantity' => '-1'], InvalidQuantityRuleException::class];
        yield 'half a piece' => [['quantity_step' => '0,5', 'quantity_decimals' => '0'], InvalidQuantityRuleException::class];
        yield 'four decimals' => [['quantity_decimals' => '4'], InvalidQuantityRuleException::class];
        yield 'decimals that are not a number' => [['quantity_decimals' => 'two'], UnreadableQuantityException::class];
        yield 'a unit content of zero' => [['unit_quantity' => '0'], InvalidQuantityRuleException::class];
        yield 'a word for a step' => [['quantity_step' => 'six'], UnreadableQuantityException::class];
        yield 'a unit the core does not know' => [['unit_code' => 'oz'], UnknownUnitCodeException::class];
    }

    /**
     * @param array<string, string>    $values
     * @param class-string<\Throwable> $refusal
     */
    #[DataProvider('refusedRules')]
    public function testARuleTheCoreCannotAcceptIsRefused(array $values, string $refusal): void
    {
        $this->expectException($refusal);

        $this->submission()->read($values);
    }

    public function testARefusalNamesTheFieldByItsLabelInTheForm(): void
    {
        $this->expectException(InvalidQuantityRuleException::class);
        $this->expectExceptionMessage('« Sold by » cannot carry more decimals than « Decimals of a quantity » allows (0).');

        $this->submission()->read(['quantity_step' => '0,5', 'quantity_decimals' => '0']);
    }

    public function testAZeroNamesTheFieldByItsLabelInTheForm(): void
    {
        $this->expectException(InvalidQuantityRuleException::class);
        $this->expectExceptionMessage('« Content of one unit sold » must be greater than zero.');

        $this->submission()->read(['unit_quantity' => '0']);
    }

    /**
     * Minimum, step, decimals, content, reference quantity: limits on both sides of each rule of the core.
     *
     * @return iterable<string, array{float, float, int, ?float, ?float}>
     */
    public static function limits(): iterable
    {
        yield 'by the piece' => [1.0, 1.0, 0, null, null];
        yield 'from 6, by 6' => [6.0, 6.0, 0, null, null];
        yield 'half a piece' => [1.0, 0.5, 0, null, null];
        yield 'a minimum with decimals sold by the piece' => [1.5, 1.0, 0, null, null];
        yield 'by the quarter of a kilogram' => [0.25, 0.25, 2, null, null];
        yield 'a step finer than the decimals' => [0.25, 0.255, 2, null, null];
        yield 'a minimum of zero' => [0.0, 1.0, 0, null, null];
        yield 'a step of zero' => [1.0, 0.0, 0, null, null];
        yield 'a negative minimum' => [-1.0, 1.0, 0, null, null];
        yield 'a content of 75 cl per litre' => [1.0, 1.0, 0, 0.75, 1.0];
        yield 'a content of zero' => [1.0, 1.0, 0, 0.0, 1.0];
        yield 'a reference quantity of zero' => [1.0, 1.0, 0, 0.75, 0.0];
        yield 'three decimals' => [0.001, 0.001, 3, null, null];
    }

    /**
     * The back office checks the rules before the core to name the field by its label: the copy
     * must accept and refuse exactly what the core does, or it drifts unnoticed.
     */
    #[DataProvider('limits')]
    public function testTheCheckOfTheBackOfficeAgreesWithTheCore(float $minimum, float $step, int $decimals, ?float $content, ?float $reference): void
    {
        $text = static fn (?float $value): string => null === $value ? '' : rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        try {
            new QuantityRule(minQuantity: $minimum, step: $step, decimals: $decimals, unitQuantity: $content, referenceUnitQuantity: $reference);
            $coreAccepts = true;
        } catch (InvalidQuantityRuleException) {
            $coreAccepts = false;
        }

        try {
            $this->submission()->read([
                'min_quantity' => $text($minimum),
                'quantity_step' => $text($step),
                'quantity_decimals' => (string) $decimals,
                'unit_quantity' => $text($content),
                'reference_unit_quantity' => $text($reference),
                'unit_code' => '',
            ]);
            $refusal = null;
        } catch (InvalidQuantityRuleException $exception) {
            $refusal = $exception->getMessage();
        }

        if ($coreAccepts) {
            self::assertNull($refusal, 'The core accepts it, the back office refuses it: '.$refusal);
        } else {
            // The core refuses through the back office anyway: the refusal must be the back office's, which names the field by its label.
            self::assertStringStartsWith('«', (string) $refusal, 'The core refuses it before the back office names the field: '.$refusal);
        }
    }

    private function submission(): QuantityRuleSubmission
    {
        return new QuantityRuleSubmission(new QuantityPolicy(), new IdentityTranslator());
    }
}
