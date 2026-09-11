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

namespace BackOfficeDefaultTwigBundle\Service\OrderReturn;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Action\OrderReturn as OrderReturnAction;
use Thelia\Domain\OrderReturn\Exception\ReturnNotAllowedException;
use Thelia\Domain\OrderReturn\Service\RefundAmountCalculator;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Map\OrderReturnTableMap;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnLineQuery;

/**
 * Writes what the merchant pointed at reception on the lines of a return: the
 * quantity actually received, the condition it came back in, and whether it can
 * be sold again.
 *
 * The core moves the return to "received" and restocks from these very columns,
 * so they are written first, in one transaction, and the status change follows.
 */
final readonly class OrderReturnReceptionRecorder
{
    public function __construct(
        private RefundAmountCalculator $refundCalculator,
    ) {
    }

    /**
     * @param array<int, array{quantity_received: float, resellable: bool, condition: string}> $input
     *                                                                                                keyed by return line id
     *
     * @throws ReturnNotAllowedException when a line does not belong to the return,
     *                                   or when more is received than was ever asked for
     */
    public function record(OrderReturn $orderReturn, array $input): void
    {
        $connection = Propel::getWriteConnection(OrderReturnTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $lines = OrderReturnLineQuery::create()
                ->filterByOrderReturnId((int) $orderReturn->getId())
                ->find($connection);

            $known = [];
            foreach ($lines as $line) {
                $known[(int) $line->getId()] = $line;
            }

            foreach ($input as $lineId => $values) {
                $line = $known[$lineId] ?? null;

                if ($line === null) {
                    throw new ReturnNotAllowedException('This return line does not belong to this return.');
                }

                $this->applyToLine($line, $values, $connection);
            }

            // The refundable amount of the return itself is not written here: the
            // core recomputes it from these very columns when the return moves to
            // "received", in the same transaction as the restock.
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * The state the "put back in stock" box starts in, according to the shop
     * setting: always on when the shop restocks everything, always off when it
     * never restocks, and on by default when it follows the resellable flag —
     * the merchant unticks what came back damaged.
     *
     * @return array{checked: bool, editable: bool}
     */
    public function restockDefault(): array
    {
        $mode = (string) ConfigQuery::read(
            OrderReturnAction::RESTOCK_MODE_CONFIG_KEY,
            OrderReturnAction::RESTOCK_MODE_RESELLABLE,
        );

        return match ($mode) {
            OrderReturnAction::RESTOCK_MODE_AUTO => ['checked' => true, 'editable' => false],
            OrderReturnAction::RESTOCK_MODE_NEVER => ['checked' => false, 'editable' => false],
            default => ['checked' => true, 'editable' => true],
        };
    }

    /**
     * @param array{quantity_received: float, resellable: bool, condition: string} $values
     */
    private function applyToLine(OrderReturnLine $line, array $values, ConnectionInterface $connection): void
    {
        $requested = (float) $line->getQuantity();
        $received = $values['quantity_received'];

        if ($received < 0) {
            throw new ReturnNotAllowedException('A received quantity cannot be negative.');
        }

        // What was never asked for cannot come back: the stock would be credited
        // with goods the order never carried.
        if ($received > $requested) {
            throw new ReturnNotAllowedException('The received quantity exceeds the requested quantity of this line.');
        }

        $condition = \in_array($values['condition'], OrderReturnLine::CONDITIONS, true)
            ? $values['condition']
            : OrderReturnLine::CONDITION_GOOD;

        // order_product_id is a required column behind a RESTRICT foreign key.
        $orderProduct = $line->getOrderProduct();

        $line
            ->setQuantityReceived($received)
            ->setReceivedCondition($condition)
            ->setResellable($values['resellable'])
            ->setRefundAmount(number_format(
                round($this->refundCalculator->lineRefundForProduct($orderProduct, $received), 2),
                2,
                '.',
                '',
            ))
            ->save($connection);
    }
}
