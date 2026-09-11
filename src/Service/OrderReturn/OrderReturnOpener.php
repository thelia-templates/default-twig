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

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use Propel\Runtime\Propel;
use Thelia\Domain\OrderReturn\Exception\ReturnNotAllowedException;
use Thelia\Domain\OrderReturn\Service\RefundAmountCalculator;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\Map\OrderReturnTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnReasonQuery;
use Thelia\Model\OrderReturnStatus;

/**
 * Opens a return on the merchant's own initiative, for the parcel announced by
 * phone: the customer is taken from the order, the return is flagged as opened
 * by the merchant, and the lines still go through the eligibility gate of the
 * core - the same one the customer path goes through.
 */
final readonly class OrderReturnOpener
{
    public function __construct(
        private ReturnEligibilityChecker $eligibility,
        private RefundAmountCalculator $refundCalculator,
        private OrderReturnRepository $returns,
    ) {
    }

    /**
     * @param array<int, float> $quantitiesByOrderProduct the requested quantity, keyed by order product id
     *
     * @throws ReturnNotAllowedException
     */
    public function open(
        Order $order,
        array $quantitiesByOrderProduct,
        ?int $reasonId,
        string $comment,
        bool $includePostage,
    ): OrderReturn {
        $customer = $order->getCustomer();

        $statusId = $this->returns->findStatusIdByCode(OrderReturnStatus::CODE_REQUESTED);

        if ($statusId === null) {
            throw new ReturnNotAllowedException('No status stands for a requested return.');
        }

        $claimed = array_filter($quantitiesByOrderProduct, static fn (float $quantity): bool => $quantity > 0);

        if ($claimed === []) {
            throw new ReturnNotAllowedException('A return needs at least one line.');
        }

        // The eligibility gate reads what other returns already hold from the
        // database; the rows it reads are locked for the whole transaction, so the
        // read and the insert that consumes it are one atomic step.
        $connection = Propel::getWriteConnection(OrderReturnTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $orderReturn = (new OrderReturn())
                ->setOrder($order)
                ->setCustomer($customer)
                ->setStatusId($statusId)
                ->setCreatedByAdmin(true)
                ->setCustomerComment($comment)
                ->setIncludePostage($includePostage);

            $this->attachReason($orderReturn, $reasonId, $order);

            $total = 0.0;

            foreach ($claimed as $orderProductId => $quantity) {
                $orderProduct = OrderProductQuery::create()->findPk($orderProductId, $connection);

                if ($orderProduct === null) {
                    throw new ReturnNotAllowedException('Unknown order product in a return line.');
                }

                $this->eligibility->lockLine($orderProduct);
                $this->eligibility->assertReturnable($order, $customer, $orderProduct, $quantity);

                $lineRefund = $this->refundCalculator->lineRefundForProduct($orderProduct, $quantity);
                $total += $lineRefund;

                $orderReturn->addOrderReturnLine(
                    (new OrderReturnLine())
                        ->setOrderProduct($orderProduct)
                        ->setProductSaleElementsId($orderProduct->getProductSaleElementsId())
                        ->setQuantity($quantity)
                        ->setRefundAmount(self::decimal($lineRefund)),
                );
            }

            if ($includePostage) {
                $this->eligibility->assertPostageNotAlreadyReturned($order);
                $total += (float) $order->getPostage() + (float) $order->getPostageTax();
            }

            $orderReturn->setRefundAmount(self::decimal($total));
            $orderReturn->save($connection);

            $connection->commit();

            return $orderReturn;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * The decimal columns of a return are typed as strings by Propel: a float
     * handed to them would be rejected outright.
     */
    private static function decimal(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    private function attachReason(OrderReturn $orderReturn, ?int $reasonId, Order $order): void
    {
        if ($reasonId === null) {
            return;
        }

        $reason = OrderReturnReasonQuery::create()->findPk($reasonId);

        if ($reason === null) {
            return;
        }

        $reason->setLocale($order->getLang()->getLocale());

        // The label is frozen on the return: deleting the reason later must not
        // erase what the merchant recorded.
        $orderReturn
            ->setOrderReturnReason($reason)
            ->setReasonTitle((string) $reason->getTitle());
    }
}
