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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\Order;

/**
 * The returns block of the order sheet: what has already been returned on this
 * order, and what the merchant may still open a return on.
 *
 * Everything it returns is empty while the feature is off or while the admin
 * lacks the returns permission, so the order sheet simply shows no block.
 */
final readonly class OrderReturnContextBuilder
{
    private const DETAIL_ROUTE = 'admin.order-return.detail';
    private const FALLBACK_STATUS_COLOR = '#6c757d';

    public function __construct(
        private OrderReturnRepository $returns,
        private ReturnEligibilityChecker $eligibility,
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order, string $locale): array
    {
        if (!$this->eligibility->isFeatureEnabled() || !$this->access->canView(AdminResources::ORDER_RETURN)) {
            return [
                'returns_enabled' => false,
                'order_returns' => [],
                'returnable_lines' => [],
                'return_reasons' => [],
                'can_create_return' => false,
            ];
        }

        $canCreate = $this->securityContext->isGranted(
            ['ADMIN'],
            [AdminResources::ORDER_RETURN],
            [],
            [AccessManager::CREATE],
        );

        return [
            'returns_enabled' => true,
            'order_returns' => $this->presentReturns($order, $locale),
            'returnable_lines' => $canCreate ? $this->presentReturnableLines($order) : [],
            'return_reasons' => $canCreate ? $this->returns->findVisibleReasons($locale) : [],
            'can_create_return' => $canCreate,
        ];
    }

    /**
     * @return list<array{id: int, ref: string, status_title: string, status_color: string, url: string}>
     */
    private function presentReturns(Order $order, string $locale): array
    {
        $rows = [];

        foreach ($this->returns->findByOrder((int) $order->getId()) as $orderReturn) {
            // status_id is a required column behind a RESTRICT foreign key: a
            // return always carries its status.
            $status = $orderReturn->getOrderReturnStatus();
            $status->setLocale($locale);

            $rows[] = [
                'id' => (int) $orderReturn->getId(),
                'ref' => (string) ($orderReturn->getRef() ?: '#'.$orderReturn->getId()),
                'status_title' => (string) $status->getTitle(),
                'status_color' => (string) ($status->getColor() ?: self::FALLBACK_STATUS_COLOR),
                'url' => $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => (int) $orderReturn->getId()]),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{order_product_id: int, title: string, ref: string, remaining: float}>
     */
    private function presentReturnableLines(Order $order): array
    {
        $lines = [];

        foreach ($this->eligibility->returnableLines($order) as $line) {
            $orderProduct = $line['order_product'];

            $lines[] = [
                'order_product_id' => (int) $orderProduct->getId(),
                'title' => (string) $orderProduct->getTitle(),
                'ref' => (string) $orderProduct->getProductRef(),
                'remaining' => $line['remaining'],
            ];
        }

        return $lines;
    }
}
