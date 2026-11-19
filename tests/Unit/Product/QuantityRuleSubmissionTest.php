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
        self::assertNull($this->submission()->fromFields(new InputBag(['quantity' => '7'])));
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
        $rule = $this->submission()->fromFields(new InputBag(['min_quantity' => '', 'quantity_step' => ' ', 'quantity_decimals' => '', 'unit_code' => '']));

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

    private function submission(): QuantityRuleSubmission
    {
        return new QuantityRuleSubmission(new QuantityPolicy(), new IdentityTranslator());
    }
}
