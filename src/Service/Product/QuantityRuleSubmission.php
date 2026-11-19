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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Catalog\Quantity\InvalidQuantityRuleException;
use Thelia\Domain\Catalog\Quantity\QuantityPolicy;
use Thelia\Domain\Catalog\Quantity\QuantityRule;
use Thelia\Domain\Catalog\Quantity\UnitCode;
use Thelia\Domain\Catalog\Quantity\UnreadableQuantityException;

/**
 * Reads the quantity rules of a combination as the combinations tab posts them: a minimum,
 * a step, the decimals, what one unit sold contains and the reference quantity, and the
 * unit. The core reads the numbers ("1,25" as well as "1.25") and judges the rule; the
 * form is only a help.
 *
 * The grid keys each field by the id of its combination, so a combination hidden or
 * removed from the page never shifts the values of another one. A combination none of
 * whose fields were posted (a module's own form, an older page) keeps its rules.
 */
final readonly class QuantityRuleSubmission
{
    public const MIN_QUANTITY = 'min_quantity';
    public const QUANTITY_STEP = 'quantity_step';
    public const QUANTITY_DECIMALS = 'quantity_decimals';
    public const UNIT_QUANTITY = 'unit_quantity';
    public const REFERENCE_UNIT_QUANTITY = 'reference_unit_quantity';
    public const UNIT_CODE = 'unit_code';

    private const FIELDS = [
        self::MIN_QUANTITY,
        self::QUANTITY_STEP,
        self::QUANTITY_DECIMALS,
        self::UNIT_QUANTITY,
        self::REFERENCE_UNIT_QUANTITY,
        self::UNIT_CODE,
    ];

    /** Reads a quantity of the unit itself: 0,75 l, 1,5 kg. */
    private const UNIT_READING = 3;

    public function __construct(
        private QuantityPolicy $policy,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The rule of one row of the grid, null when the row posted none of its fields.
     *
     * @throws InvalidQuantityRuleException when the values make no rule
     * @throws UnreadableQuantityException  when a number cannot be read
     * @throws UnknownUnitCodeException     when the unit is not one the core knows
     */
    public function fromGrid(InputBag $form, int $combinationId): ?QuantityRule
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $column = $form->all($field);
            if (\array_key_exists($combinationId, $column)) {
                $values[$field] = \is_scalar($column[$combinationId]) ? (string) $column[$combinationId] : '';
            }
        }

        return $this->read($values);
    }

    /**
     * The rule of the default combination's form, whose fields are not arrays.
     *
     * @throws InvalidQuantityRuleException
     * @throws UnreadableQuantityException
     * @throws UnknownUnitCodeException
     */
    public function fromFields(InputBag $form): ?QuantityRule
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            if ($form->has($field)) {
                $value = $form->all()[$field];
                $values[$field] = \is_scalar($value) ? (string) $value : '';
            }
        }

        return $this->read($values);
    }

    /**
     * @param array<string, string> $values the posted fields, by name
     *
     * @throws InvalidQuantityRuleException
     * @throws UnreadableQuantityException
     * @throws UnknownUnitCodeException
     */
    public function read(array $values): ?QuantityRule
    {
        if ([] === array_intersect_key($values, array_flip(self::FIELDS))) {
            return null;
        }

        $decimals = $this->decimals($values[self::QUANTITY_DECIMALS] ?? '');
        // The decimals tell "1,250" (one and a quarter, sold by the gram) from "1,250" (a
        // thousand two hundred and fifty) where both readings are possible.
        $quantities = new QuantityRule(decimals: max(0, min(self::UNIT_READING, $decimals)));
        $units = new QuantityRule(decimals: self::UNIT_READING);

        return new QuantityRule(
            minQuantity: $this->number($values[self::MIN_QUANTITY] ?? '', $quantities) ?? 1.0,
            step: $this->number($values[self::QUANTITY_STEP] ?? '', $quantities) ?? 1.0,
            decimals: $decimals,
            unitQuantity: $this->number($values[self::UNIT_QUANTITY] ?? '', $units),
            referenceUnitQuantity: $this->number($values[self::REFERENCE_UNIT_QUANTITY] ?? '', $units),
            unitCode: $this->unitCode($values[self::UNIT_CODE] ?? ''),
        );
    }

    private function number(string $input, QuantityRule $reading): ?float
    {
        return '' === trim($input) ? null : $this->policy->parse($input, $reading);
    }

    /**
     * @throws UnreadableQuantityException
     */
    private function decimals(string $input): int
    {
        if ('' === trim($input)) {
            return 0;
        }

        $decimals = filter_var(trim($input), \FILTER_VALIDATE_INT);
        if (false === $decimals) {
            throw new UnreadableQuantityException($input);
        }

        return $decimals;
    }

    /**
     * @throws UnknownUnitCodeException
     */
    private function unitCode(string $input): ?UnitCode
    {
        if ('' === trim($input)) {
            return null;
        }

        return UnitCode::tryFrom(trim($input)) ?? throw new UnknownUnitCodeException($this->translator->trans('The unit "%unit%" is not one of the units a combination can be sold in.', ['%unit%' => $input]));
    }
}
