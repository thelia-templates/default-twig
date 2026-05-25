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

use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Order;
use Thelia\Model\OrderStatusQuery;

/**
 * Maps an Order model to the DataTable row shape used by `order/list.html.twig`.
 * Keeps the controller thin and the formatting logic (status colour, total
 * with currency symbol) in a single place.
 */
final readonly class OrderListRowPresenter
{
    private const RESOURCE = 'admin.order';
    private const DETAIL_ROUTE = 'admin.order.update.view';
    private const FALLBACK_STATUS_COLOR = '#6c757d';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{
     *     id: int,
     *     ref: string,
     *     status: string,
     *     status_code: string,
     *     status_color: string,
     *     amount: string,
     *     date: string,
     *     _actions: list<RowAction>
     * }
     */
    public function present(Order $order, string $locale): array
    {
        $status = OrderStatusQuery::create()->findPk((int) $order->getStatusId());
        $status?->setLocale($locale);

        return [
            'id' => (int) $order->getId(),
            'ref' => (string) $order->getRef(),
            'status' => (string) ($status?->getTitle() ?? '—'),
            'status_code' => (string) ($status?->getCode() ?? ''),
            'status_color' => (string) ($status?->getColor() ?: self::FALLBACK_STATUS_COLOR),
            'amount' => $this->formatAmount($order),
            'date' => $order->getCreatedAt()?->format('Y-m-d') ?? '—',
            '_actions' => [
                new RowAction(
                    kind: 'edit',
                    label: $this->translator->trans('View order'),
                    href: $this->urls->generate(self::DETAIL_ROUTE, ['order_id' => (int) $order->getId()]),
                    grantedAttribute: AccessManager::VIEW,
                    grantedSubject: self::RESOURCE,
                ),
            ],
        ];
    }

    private function formatAmount(Order $order): string
    {
        $total = number_format((float) $order->getTotalAmount(), 2, ',', ' ');
        $symbol = (string) ($order->getCurrency() ? $order->getCurrency()->getSymbol() : '');

        return $symbol === '' ? $total : $total.' '.$symbol;
    }
}
