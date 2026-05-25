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

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Order;
use Thelia\Model\OrderStatusQuery;

/**
 * Maps an Order model to the DataTable row shape used by `order/list.html.twig`.
 * Keeps the controller thin and the formatting logic (status colour, total
 * with currency symbol, customer block, date display) in a single place.
 */
final readonly class OrderListRowPresenter
{
    private const RESOURCE = 'admin.order';
    private const DETAIL_ROUTE = 'admin.order.update.view';
    private const INVOICE_PDF_ROUTE = 'admin.order.pdf.invoice';
    private const DELIVERY_PDF_ROUTE = 'admin.order.pdf.delivery';
    private const CANCEL_FROM_LIST_ROUTE = 'admin.order.list.cancel';
    private const FALLBACK_STATUS_COLOR = '#6c757d';
    private const URGENT_THRESHOLD_HOURS = 48;
    private const URGENT_STATUS_CODE = 'not_paid';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private OrderRepository $orderRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Order $order, string $locale): array
    {
        $orderId = (int) $order->getId();
        $status = OrderStatusQuery::create()->findPk((int) $order->getStatusId());
        $status?->setLocale($locale);

        $paymentModule = $order->getModuleRelatedByPaymentModuleId();
        $paymentModule?->setLocale($locale);
        $deliveryModule = $order->getModuleRelatedByDeliveryModuleId();
        $deliveryModule?->setLocale($locale);

        $isUrgent = $this->isUrgent($order, $status?->getCode());
        $cancelStatus = OrderStatusQuery::getCancelledStatus();
        $cancelStatusId = $cancelStatus !== null ? (int) $cancelStatus->getId() : 0;
        $isCanceled = $cancelStatusId > 0 && (int) $order->getStatusId() === $cancelStatusId;

        return [
            'id' => $orderId,
            'ref' => (string) $order->getRef(),
            'ref_html' => $this->renderRef((string) $order->getRef(), $isUrgent),
            'status' => (string) ($status?->getTitle() ?? '-'),
            'status_code' => (string) ($status?->getCode() ?? ''),
            'status_color' => (string) ($status?->getColor() ?: self::FALLBACK_STATUS_COLOR),
            'customer_html' => $this->renderCustomer($order),
            'items_html' => $this->renderItems($orderId),
            'payment_html' => $this->renderModule($paymentModule?->getTitle(), 'bi-credit-card'),
            'delivery_html' => $this->renderDelivery($order, $deliveryModule?->getTitle()),
            'amount' => $this->formatAmount($order),
            'date_html' => $this->renderDate($order),
            'is_urgent' => $isUrgent,
            '_row_class' => $isUrgent ? 'bo-order-row--urgent' : '',
            '_actions' => $this->buildActions($orderId, $cancelStatusId, $isCanceled, (string) $order->getRef()),
        ];
    }

    private function renderRef(string $ref, bool $isUrgent): string
    {
        if (!$isUrgent) {
            return htmlspecialchars($ref);
        }

        $tooltip = $this->translator->trans('Unpaid for more than 48 hours - follow up needed');

        return \sprintf(
            '<span class="bo-order-urgent" data-bs-toggle="tooltip" data-bs-placement="right" title="%s"><i class="bi bi-exclamation-triangle-fill text-danger me-1" aria-hidden="true"></i>%s</span>',
            htmlspecialchars($tooltip),
            htmlspecialchars($ref),
        );
    }

    private function renderCustomer(Order $order): string
    {
        $customer = $order->getCustomer();
        if ($customer === null) {
            return '<span class="text-muted fst-italic">'.htmlspecialchars($this->translator->trans('Anonymous')).'</span>';
        }

        $firstname = htmlspecialchars((string) $customer->getFirstname());
        $lastname = htmlspecialchars((string) $customer->getLastname());
        $email = htmlspecialchars((string) $customer->getEmail());
        $flag = $this->countryFlag($order->getOrderAddressRelatedByDeliveryOrderAddressId()?->getCountry()?->getIsoalpha2());

        return \sprintf(
            '<div class="bo-order-customer"><div class="fw-semibold">%s%s %s</div><div class="text-muted small text-truncate">%s</div></div>',
            $flag,
            $firstname,
            $lastname,
            $email,
        );
    }

    private function renderItems(int $orderId): string
    {
        $count = $this->orderRepository->countItemsForOrder($orderId);
        $label = $this->translator->trans(
            $count === 1 ? '%count% article' : '%count% articles',
            ['%count%' => $count],
        );

        return \sprintf(
            '<span class="badge text-bg-light bo-order-items"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>%s</span>',
            htmlspecialchars($label),
        );
    }

    private function renderModule(?string $title, string $icon): string
    {
        $trimmed = trim((string) $title);
        if ($trimmed === '') {
            return '<span class="text-muted">-</span>';
        }

        return \sprintf(
            '<span class="bo-order-module"><i class="bi %s me-1" aria-hidden="true"></i>%s</span>',
            htmlspecialchars($icon),
            htmlspecialchars($trimmed),
        );
    }

    private function renderDelivery(Order $order, ?string $deliveryTitle): string
    {
        $base = $this->renderModule($deliveryTitle, 'bi-truck');
        $tracking = trim((string) $order->getDeliveryRef());
        if ($tracking === '') {
            return $base;
        }

        return $base.\sprintf(
            '<div class="text-muted small mt-1"><i class="bi bi-upc-scan me-1" aria-hidden="true"></i>%s</div>',
            htmlspecialchars($tracking),
        );
    }

    private function renderDate(Order $order): string
    {
        $createdAt = $order->getCreatedAt();
        if ($createdAt === null) {
            return '<span class="text-muted">-</span>';
        }

        $iso = $createdAt->format(\DateTimeInterface::ATOM);
        $display = $createdAt->format('d/m/Y H:i');
        $relative = $this->relativeTime($createdAt);

        return \sprintf(
            '<time datetime="%s" title="%s"><div>%s</div><div class="text-muted small">%s</div></time>',
            htmlspecialchars($iso),
            htmlspecialchars($display),
            htmlspecialchars($display),
            htmlspecialchars($relative),
        );
    }

    private function relativeTime(\DateTimeInterface $createdAt): string
    {
        $diffSeconds = time() - $createdAt->getTimestamp();
        if ($diffSeconds < 60) {
            return $this->translator->trans('Just now');
        }
        if ($diffSeconds < 3600) {
            $minutes = (int) floor($diffSeconds / 60);

            return $this->translator->trans('%count% min ago', ['%count%' => $minutes]);
        }
        if ($diffSeconds < 86400) {
            $hours = (int) floor($diffSeconds / 3600);

            return $this->translator->trans('%count% h ago', ['%count%' => $hours]);
        }
        $days = (int) floor($diffSeconds / 86400);
        if ($days < 30) {
            return $this->translator->trans('%count% d ago', ['%count%' => $days]);
        }

        return $createdAt->format('d/m/Y');
    }

    private function countryFlag(?string $iso): string
    {
        if ($iso === null || \strlen($iso) !== 2) {
            return '';
        }

        $upper = strtoupper($iso);
        $offset = 0x1F1E6 - \ord('A');
        $flag = '';
        for ($i = 0, $len = \strlen($upper); $i < $len; ++$i) {
            $flag .= mb_chr(\ord($upper[$i]) + $offset);
        }

        return $flag.' ';
    }

    private function formatAmount(Order $order): string
    {
        $total = number_format((float) $order->getTotalAmount(), 2, ',', ' ');
        $symbol = (string) ($order->getCurrency() ? $order->getCurrency()->getSymbol() : '');

        return $symbol === '' ? $total : $total.' '.$symbol;
    }

    private function isUrgent(Order $order, ?string $statusCode): bool
    {
        if ($statusCode !== self::URGENT_STATUS_CODE) {
            return false;
        }
        $createdAt = $order->getCreatedAt();
        if ($createdAt === null) {
            return false;
        }
        $hoursOld = (time() - $createdAt->getTimestamp()) / 3600;

        return $hoursOld >= self::URGENT_THRESHOLD_HOURS;
    }

    /**
     * @return list<RowAction>
     */
    private function buildActions(int $orderId, int $cancelStatusId, bool $isCanceled, string $orderRef): array
    {
        $actions = [
            new RowAction(
                kind: 'view',
                label: $this->translator->trans('View order'),
                href: $this->urls->generate(self::DETAIL_ROUTE, ['order_id' => $orderId]),
                grantedAttribute: AccessManager::VIEW,
                grantedSubject: self::RESOURCE,
            ),
            new RowAction(
                kind: 'invoice',
                label: $this->translator->trans('Download invoice PDF'),
                href: $this->urls->generate(self::INVOICE_PDF_ROUTE, ['order_id' => $orderId, 'browser' => 1]),
                grantedAttribute: AccessManager::VIEW,
                grantedSubject: self::RESOURCE,
            ),
            new RowAction(
                kind: 'delivery-slip',
                label: $this->translator->trans('Download delivery slip PDF'),
                href: $this->urls->generate(self::DELIVERY_PDF_ROUTE, ['order_id' => $orderId, 'browser' => 1]),
                grantedAttribute: AccessManager::VIEW,
                grantedSubject: self::RESOURCE,
            ),
        ];

        if ($cancelStatusId > 0 && !$isCanceled) {
            $actions[] = new RowAction(
                kind: 'cancel',
                label: $this->translator->trans('Cancel order'),
                modalTarget: '#order-cancel-modal',
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: self::RESOURCE,
                dataAttributes: [
                    'order-id' => $orderId,
                    'order-ref' => $orderRef,
                    'order-cancel-url' => $this->urls->generate(self::CANCEL_FROM_LIST_ROUTE, ['order_id' => $orderId]),
                ],
            );
        }

        return $actions;
    }
}
