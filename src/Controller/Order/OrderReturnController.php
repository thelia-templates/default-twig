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

namespace BackOfficeDefaultTwigBundle\Controller\Order;

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnDetailPresenter;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnFilterPresenter;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnFilters;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnListRowPresenter;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnOpener;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnReceptionRecorder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\OrderReturn\OrderReturnEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\OrderReturn\OrderReturnStateMachine;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The merchant side of the product returns: the queue of requests, the sheet of
 * one request with the actions of its cycle, and the reception of the parcel.
 *
 * Every route of this controller answers 404 while the shop has not turned the
 * feature on, exactly as the API of the core does: a shop that never enabled
 * returns has no return screen at all.
 */
final class OrderReturnController
{
    private const RESOURCE = AdminResources::ORDER_RETURN;
    private const LIST_ROUTE = 'admin.order-return.list';
    private const DETAIL_ROUTE = 'admin.order-return.detail';
    private const ORDER_DETAIL_ROUTE = 'admin.order.update.view';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/order-return/list.html.twig';
    private const DETAIL_TEMPLATE = '@BackOfficeDefaultTwig/order-return/detail.html.twig';
    private const RECEPTION_TEMPLATE = '@BackOfficeDefaultTwig/order-return/reception.html.twig';
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly EventDispatcherInterface $events,
        private readonly ReturnEligibilityChecker $eligibility,
        private readonly OrderReturnStateMachine $stateMachine,
        private readonly OrderReturnRepository $returns,
        private readonly OrderReturnListRowPresenter $rowPresenter,
        private readonly OrderReturnFilterPresenter $filterPresenter,
        private readonly OrderReturnDetailPresenter $detailPresenter,
        private readonly OrderReturnReceptionRecorder $reception,
        private readonly OrderReturnOpener $opener,
    ) {
    }

    #[Route('/admin/returns', name: 'admin.order-return.list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $locale = $request->getLocale();
        $filters = OrderReturnFilters::fromRequest($request);

        $paginated = $this->returns->findPaginated($filters, $page, self::PAGE_SIZE);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->rowPresenter->presentAll($paginated['rows'], $locale),
            'total' => $paginated['total'],
            'pages' => $paginated['lastPage'],
            'current_page' => min($page, $paginated['lastPage']),
            'filters' => $this->filterPresenter->present($filters, $locale),
            'query_params' => $filters->toQueryParams(),
            'status_counts' => $this->returns->findStatusesWithCounts($locale),
        ]));
    }

    #[Route('/admin/return/{order_return_id}', name: 'admin.order-return.detail', methods: ['GET'], requirements: ['order_return_id' => '\d+'])]
    public function detail(Request $request, int $order_return_id): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $orderReturn = $this->returns->findById($order_return_id);

        if ($orderReturn === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $navigation = $this->returns->findPreviousNext($orderReturn);

        return new Response($this->twig->render(self::DETAIL_TEMPLATE, array_merge(
            $this->detailPresenter->present($orderReturn, $request->getLocale()),
            [
                'token' => $this->tokens->assignToken(),
                'reception_url' => $this->urls->generate('admin.order-return.reception.view', ['order_return_id' => $order_return_id]),
                'order_url' => $orderReturn->getOrderId() !== null
                    ? $this->urls->generate(self::ORDER_DETAIL_ROUTE, ['order_id' => (int) $orderReturn->getOrderId()])
                    : null,
                'prev_url' => $navigation['previous'] !== null
                    ? $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $navigation['previous']])
                    : null,
                'next_url' => $navigation['next'] !== null
                    ? $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $navigation['next']])
                    : null,
            ],
        )));
    }

    /**
     * One cycle action: the button carries the status it moves to, the core
     * refuses any move its state machine does not authorize.
     */
    #[Route('/admin/return/{order_return_id}/transition', name: 'admin.order-return.transition', methods: ['POST'], requirements: ['order_return_id' => '\d+'])]
    public function transition(Request $request, int $order_return_id): Response
    {
        $this->assertFeatureEnabled();

        $orderReturn = $this->returns->findById($order_return_id);

        if ($orderReturn === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $event = new OrderReturnEvent($orderReturn);
        $event->setTargetStatusId((int) $request->request->get('status_id', 0));

        $refusalReason = trim((string) $request->request->get('refusal_reason', ''));
        if ($refusalReason !== '') {
            $event->setRefusalReason($refusalReason);
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ORDER_RETURN_UPDATE_STATUS,
            actionLabel: 'Return status update',
            successRoute: self::DETAIL_ROUTE,
            successParameters: ['order_return_id' => $order_return_id],
            describeForLog: static fn (OrderReturnEvent $dispatched): array => [
                \sprintf(
                    'Return %s moved to status "%s"',
                    (string) $dispatched->getOrderReturn()->getRef(),
                    (string) $dispatched->getOrderReturn()->getStatusCode(),
                ),
                (int) $dispatched->getOrderReturn()->getId(),
            ],
        );
    }

    #[Route('/admin/return/{order_return_id}/reception', name: 'admin.order-return.reception.view', methods: ['GET'], requirements: ['order_return_id' => '\d+'])]
    public function receptionView(Request $request, int $order_return_id): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $orderReturn = $this->returns->findById($order_return_id);

        if ($orderReturn === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $receivedStatusId = $this->returns->findStatusIdByCode(OrderReturnStatus::CODE_RECEIVED);

        if ($receivedStatusId === null || !$this->canReceive($orderReturn)) {
            return new RedirectResponse($this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $order_return_id]));
        }

        return new Response($this->twig->render(self::RECEPTION_TEMPLATE, [
            'return' => $orderReturn,
            'lines' => $this->detailPresenter->presentLines($orderReturn),
            'restock' => $this->reception->restockDefault(),
            'conditions' => OrderReturnLine::CONDITIONS,
            'token' => $this->tokens->assignToken(),
            'detail_url' => $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $order_return_id]),
        ]));
    }

    #[Route('/admin/return/{order_return_id}/reception', name: 'admin.order-return.reception.save', methods: ['POST'], requirements: ['order_return_id' => '\d+'])]
    public function receptionSave(Request $request, int $order_return_id): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $orderReturn = $this->returns->findById($order_return_id);

        if ($orderReturn === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $detailUrl = $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $order_return_id]);

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));

            $receivedStatusId = $this->returns->findStatusIdByCode(OrderReturnStatus::CODE_RECEIVED);

            if ($receivedStatusId === null || !$this->canReceive($orderReturn)) {
                return new RedirectResponse($detailUrl);
            }

            // What the merchant pointed is written first: the core restocks from
            // these very columns when the return moves to "received".
            $this->reception->record($orderReturn, $this->readReceptionInput($request));

            $event = new OrderReturnEvent($orderReturn);
            $event->setTargetStatusId($receivedStatusId);
            $this->events->dispatch($event, TheliaEvents::ORDER_RETURN_UPDATE_STATUS);

            $this->adminLogger->log(
                self::RESOURCE,
                AccessManager::UPDATE,
                \sprintf('Return %s received', (string) $orderReturn->getRef()),
                $order_return_id,
            );
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup(
                'Return reception',
                $exception->getMessage(),
                null,
                $exception,
            );

            return new RedirectResponse(
                $this->urls->generate('admin.order-return.reception.view', ['order_return_id' => $order_return_id]),
            );
        }

        return new RedirectResponse($detailUrl);
    }

    /**
     * A return the merchant opens himself, for the parcel announced by phone.
     */
    #[Route('/admin/order/{order_id}/return/open', name: 'admin.order-return.open', methods: ['POST'], requirements: ['order_id' => '\d+'])]
    public function openFromOrder(Request $request, int $order_id): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        $order = OrderQuery::create()->findPk($order_id);
        $orderUrl = $this->urls->generate(self::ORDER_DETAIL_ROUTE, ['order_id' => $order_id]);

        if ($order === null) {
            return new RedirectResponse($this->urls->generate('admin.order.list'));
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));

            $reasonId = (int) $request->request->get('reason_id', 0);

            $orderReturn = $this->opener->open(
                $order,
                $this->readOpeningQuantities($request),
                $reasonId > 0 ? $reasonId : null,
                trim((string) $request->request->get('comment', '')),
                (bool) $request->request->get('include_postage', false),
            );

            $this->adminLogger->log(
                self::RESOURCE,
                AccessManager::CREATE,
                \sprintf('Return %s opened on order %s', (string) $orderReturn->getRef(), (string) $order->getRef()),
                (int) $orderReturn->getId(),
            );

            $this->events->dispatch(new OrderReturnEvent($orderReturn), TheliaEvents::ORDER_RETURN_SEND_STATUS_EMAIL);

            return new RedirectResponse(
                $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => (int) $orderReturn->getId()]),
            );
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup('Return opening', $exception->getMessage(), null, $exception);

            return new RedirectResponse($orderUrl);
        }
    }

    /**
     * @return array<int, array{quantity_received: float, resellable: bool, condition: string}>
     */
    private function readReceptionInput(Request $request): array
    {
        $quantities = $request->request->all('quantity_received');
        $resellables = $request->request->all('resellable');
        $conditions = $request->request->all('received_condition');

        $input = [];
        foreach ($quantities as $lineId => $quantity) {
            $id = (int) $lineId;

            if ($id <= 0) {
                continue;
            }

            $input[$id] = [
                'quantity_received' => (float) str_replace(',', '.', (string) $quantity),
                'resellable' => (bool) ($resellables[$lineId] ?? false),
                'condition' => (string) ($conditions[$lineId] ?? OrderReturnLine::CONDITION_GOOD),
            ];
        }

        return $input;
    }

    /**
     * @return array<int, float>
     */
    private function readOpeningQuantities(Request $request): array
    {
        $quantities = [];
        foreach ($request->request->all('quantity') as $orderProductId => $quantity) {
            $id = (int) $orderProductId;

            if ($id <= 0) {
                continue;
            }

            $quantities[$id] = (float) str_replace(',', '.', (string) $quantity);
        }

        return $quantities;
    }

    /**
     * Whether the return may still move to "received" from where it stands.
     */
    private function canReceive(OrderReturn $orderReturn): bool
    {
        $status = OrderReturnStatusQuery::create()->findPk((int) $orderReturn->getStatusId());

        return $status !== null
            && $this->stateMachine->canTransition($status->getEffectiveCode(), OrderReturnStatus::CODE_RECEIVED);
    }

    /**
     * The screens follow the shop setting the API of the core follows: while the
     * feature is off, nothing of the returns exists for anybody.
     */
    private function assertFeatureEnabled(): void
    {
        if (!$this->eligibility->isFeatureEnabled()) {
            throw new NotFoundHttpException();
        }
    }
}
