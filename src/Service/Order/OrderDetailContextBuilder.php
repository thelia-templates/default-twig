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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;

final readonly class OrderDetailContextBuilder
{
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{
     *     totals: array{subtotal_ht: float, subtotal_taxes: float, subtotal_ttc: float, postage_ht: float, postage_tax: float, postage_ttc: float, discount: float, grand_total: float},
     *     weight: float,
     *     customer: array{id: int, ref: string, firstname: string, lastname: string, email: string, edit_url: string}|null,
     *     payment: array{module_id: int, module_title: string, transaction_ref: string, invoice_ref: string},
     *     delivery: array{module_id: int, module_title: string, delivery_ref: string},
     *     currency: array{id: int, symbol: string, code: string}
     * }
     */
    public function build(Order $order): array
    {
        $currency = $order->getCurrency();

        $subtotalHt = 0.0;
        $subtotalTaxes = 0.0;
        $weight = 0.0;
        foreach ($order->getOrderProducts() as $orderProduct) {
            $quantity = (float) $orderProduct->getQuantity();
            $subtotalHt += (float) $orderProduct->getPrice() * $quantity;
            $weight += (float) $orderProduct->getWeight() * $quantity;
            foreach ($orderProduct->getOrderProductTaxes() as $orderProductTax) {
                $subtotalTaxes += (float) $orderProductTax->getAmount() * $quantity;
            }
        }

        $postageHt = (float) $order->getPostage();
        $postageTax = (float) $order->getPostageTax();
        $discount = (float) $order->getDiscount();
        $grandTotal = $subtotalHt + $subtotalTaxes + $postageHt - $discount;

        $customer = null;
        $customerModel = $order->getCustomer();
        if ($customerModel !== null) {
            $customer = [
                'id' => (int) $customerModel->getId(),
                'ref' => (string) $customerModel->getRef(),
                'firstname' => (string) $customerModel->getFirstname(),
                'lastname' => (string) $customerModel->getLastname(),
                'email' => (string) $customerModel->getEmail(),
                'edit_url' => $this->urls->generate('admin.customer.update.view', ['customer_id' => (int) $customerModel->getId()]),
            ];
        }

        return [
            'totals' => [
                'subtotal_ht' => $subtotalHt,
                'subtotal_taxes' => $subtotalTaxes,
                'subtotal_ttc' => $subtotalHt + $subtotalTaxes,
                'postage_ht' => $postageHt - $postageTax,
                'postage_tax' => $postageTax,
                'postage_ttc' => $postageHt,
                'discount' => $discount,
                'grand_total' => $grandTotal,
            ],
            'weight' => $weight,
            'customer' => $customer,
            'payment' => [
                'module_id' => (int) $order->getPaymentModuleId(),
                'module_title' => $this->moduleTitle((int) $order->getPaymentModuleId()),
                'transaction_ref' => (string) $order->getTransactionRef(),
                'invoice_ref' => (string) $order->getInvoiceRef(),
            ],
            'delivery' => [
                'module_id' => (int) $order->getDeliveryModuleId(),
                'module_title' => $this->moduleTitle((int) $order->getDeliveryModuleId()),
                'delivery_ref' => (string) $order->getDeliveryRef(),
            ],
            'currency' => [
                'id' => $currency !== null ? (int) $currency->getId() : 0,
                'symbol' => $currency !== null ? (string) $currency->getSymbol() : '',
                'code' => $currency !== null ? (string) $currency->getCode() : '',
            ],
        ];
    }

    private function moduleTitle(int $moduleId): string
    {
        if ($moduleId === 0) {
            return '';
        }
        $module = ModuleQuery::create()->findPk($moduleId);

        return $module !== null ? (string) $module->getTitle() : '';
    }
}
