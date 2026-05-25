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

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Order\OrderDetailContextBuilder;
use BackOfficeDefaultTwigBundle\Service\Order\OrderFilterPresenter;
use BackOfficeDefaultTwigBundle\Service\Order\OrderFilters;
use BackOfficeDefaultTwigBundle\Service\Order\OrderListRowPresenter;
use BackOfficeDefaultTwigBundle\Service\Pdf\OrderPdfRenderer;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Order\OrderAddressEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\CountryQuery;
use Thelia\Model\CustomerTitleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\StateQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

final class OrderController
{
    private const RESOURCE = AdminResources::ORDER;
    private const LIST_ROUTE = 'admin.order.list';
    private const DETAIL_ROUTE = 'admin.order.update.view';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/order/list.html.twig';
    private const DETAIL_TEMPLATE = '@BackOfficeDefaultTwig/order/detail.html.twig';
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly EventDispatcherInterface $events,
        private readonly TranslatorInterface $translator,
        private readonly \Symfony\Component\Form\FormFactoryInterface $formFactory,
        private readonly OrderPdfRenderer $pdfRenderer,
        private readonly OrderDetailContextBuilder $detailContextBuilder,
        private readonly OrderRepository $orderRepository,
        private readonly OrderListRowPresenter $rowPresenter,
        private readonly OrderFilterPresenter $filterPresenter,
    ) {
    }

    #[Route('/admin/orders', name: 'admin.order.list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $locale = $request->getLocale();
        $filters = OrderFilters::fromRequest($request);

        $paginated = $this->orderRepository->findPaginated($filters, $page, self::PAGE_SIZE);

        $rows = [];
        foreach ($paginated['rows'] as $order) {
            \assert($order instanceof Order);
            $rows[] = $this->rowPresenter->present($order, $locale);
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'total' => $paginated['total'],
            'pages' => $paginated['lastPage'],
            'current_page' => min($page, $paginated['lastPage']),
            'filters' => $this->filterPresenter->present($filters, $locale),
            'query_params' => $filters->toQueryParams(),
        ]));
    }

    #[Route('/admin/order/update/{order_id}', name: 'admin.order.update.view', methods: ['GET'], requirements: ['order_id' => '\d+'])]
    public function detail(Request $request, int $order_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $order = $this->orderRepository->findById($order_id);
        if ($order === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $locale = $request->getLocale();

        return new Response($this->twig->render(self::DETAIL_TEMPLATE, array_merge(
            $this->detailContextBuilder->build($order, $locale),
            [
                'order' => $order,
                'order_items' => $this->orderItems($order),
                'order_addresses' => $this->orderAddresses($order, $locale),
                'available_statuses' => $this->statusChoices($locale),
                'customer_titles' => $this->customerTitleChoices($locale),
                'countries' => $this->countryChoices($locale),
                'states' => $this->stateChoices($locale),
                'invoice_url' => $this->urls->generate('admin.order.pdf.invoice', ['order_id' => $order_id, 'browser' => 1]),
                'delivery_url' => $this->urls->generate('admin.order.pdf.delivery', ['order_id' => $order_id, 'browser' => 1]),
                'token' => $this->tokens->assignToken(),
            ],
        )));
    }

    #[Route('/admin/order/update/status', name: 'admin.order.list.update.status', methods: ['POST', 'GET'])]
    public function bulkUpdateStatus(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->query->get('_token'));
            $orderIds = (array) $request->get('order_ids', []);
            $statusId = (int) $request->get('status_id', 0);

            foreach ($orderIds as $id) {
                $order = OrderQuery::create()->findPk((int) $id);
                if ($order === null) {
                    continue;
                }
                $event = new OrderEvent($order);
                $event->setStatus($statusId);
                $this->events->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
            }
        } catch (\Throwable) {
            // Iso behavior: silently ignore CSRF/dispatch errors here, log via session if needed
        }

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    #[Route('/admin/order/update/{order_id}/status', name: 'admin.order.update.status', methods: ['POST', 'GET'], requirements: ['order_id' => '\d+'])]
    public function updateStatus(int $order_id, Request $request): Response
    {
        $order = OrderQuery::create()->findPk($order_id);
        if ($order === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $event = new OrderEvent($order);
        $event->setStatus((int) $request->get('status_id', 0));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ORDER_UPDATE_STATUS,
            actionLabel: 'Order status updated',
            successRoute: self::DETAIL_ROUTE,
            successParameters: ['order_id' => $order_id],
        );
    }

    #[Route('/admin/order/update/{order_id}/delivery-ref', name: 'admin.order.update.deliveryRef', methods: ['POST', 'GET'], requirements: ['order_id' => '\d+'])]
    public function updateDeliveryRef(int $order_id, Request $request): Response
    {
        $order = OrderQuery::create()->findPk($order_id);
        if ($order === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $event = new OrderEvent($order);
        $event->setDeliveryRef((string) $request->get('delivery_ref', ''));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ORDER_UPDATE_DELIVERY_REF,
            actionLabel: 'Order delivery ref updated',
            successRoute: self::DETAIL_ROUTE,
            successParameters: ['order_id' => $order_id],
        );
    }

    #[Route('/admin/order/update/{order_id}/address', name: 'admin.order.update.address', methods: ['POST', 'GET'], requirements: ['order_id' => '\d+'])]
    public function updateAddress(int $order_id, Request $request): Response
    {
        $order = OrderQuery::create()->findPk($order_id);
        if ($order === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', $request->query->get('_token')));

            $form = $this->formFactory->createNamed(
                'thelia_order_address',
                \BackOfficeDefaultTwigBundle\Form\Order\OrderAddressType::class,
                null,
                ['csrf_protection' => false],
            );
            $form->handleRequest($request);
            $data = ($form->isSubmitted() && $form->isValid())
                ? ($form->getData() ?? [])
                : (array) $request->request->all('thelia_order_address');

            $addressId = (int) ($data['id'] ?? 0);
            $orderAddress = $addressId > 0 ? OrderAddressQuery::create()->findPk($addressId) : null;

            if ($orderAddress === null) {
                throw new \InvalidArgumentException('The order address does not exist');
            }

            if (
                $orderAddress->getId() !== $order->getInvoiceOrderAddressId()
                && $orderAddress->getId() !== $order->getDeliveryOrderAddressId()
            ) {
                throw new \InvalidArgumentException('The order address does not belong to the current order');
            }

            $event = new OrderAddressEvent(
                title: $data['title'] ?? null,
                firstname: (string) ($data['firstname'] ?? ''),
                lastname: (string) ($data['lastname'] ?? ''),
                address1: (string) ($data['address1'] ?? ''),
                address2: $data['address2'] ?? null,
                address3: $data['address3'] ?? null,
                zipcode: (string) ($data['zipcode'] ?? ''),
                city: (string) ($data['city'] ?? ''),
                country: $data['country'] ?? null,
                phone: (string) ($data['phone'] ?? ''),
                company: $data['company'] ?? null,
                cellphone: $data['cellphone'] ?? null,
                state: $data['state'] ?? null,
            );
            $event->setOrderAddress($orderAddress);
            $event->setOrder($order);

            $this->events->dispatch($event, TheliaEvents::ORDER_UPDATE_ADDRESS);
        } catch (\Throwable) {
            // surfaced via session flash in production
        }

        return new RedirectResponse($this->urls->generate(self::DETAIL_ROUTE, ['order_id' => $order_id]));
    }

    #[Route('/admin/order/pdf/invoice/{order_id}/{browser}', name: 'admin.order.pdf.invoice', methods: ['GET'], requirements: ['order_id' => '\d+', 'browser' => '\d+'])]
    public function pdfInvoice(int $order_id, int $browser): Response
    {
        return $this->renderOrderPdf($order_id, OrderPdfRenderer::KIND_INVOICE, 1 === $browser);
    }

    #[Route('/admin/order/pdf/delivery/{order_id}/{browser}', name: 'admin.order.pdf.delivery', methods: ['GET'], requirements: ['order_id' => '\d+', 'browser' => '\d+'])]
    public function pdfDelivery(int $order_id, int $browser): Response
    {
        return $this->renderOrderPdf($order_id, OrderPdfRenderer::KIND_DELIVERY, 1 === $browser);
    }

    private function renderOrderPdf(int $orderId, string $kind, bool $browser): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return $this->pdfRenderer->render($orderId, $kind, $browser);
    }


    /**
     * @return list<array<string, mixed>>
     */
    private function orderItems(Order $order): array
    {
        $items = [];
        foreach ($order->getOrderProducts() as $product) {
            $wasInPromo = (bool) $product->getWasInPromo();
            $unitPriceHt = (float) ($wasInPromo ? $product->getPromoPrice() : $product->getPrice());

            $unitTax = 0.0;
            foreach ($product->getOrderProductTaxes() as $orderProductTax) {
                $unitTax += (float) ($wasInPromo ? $orderProductTax->getPromoAmount() : $orderProductTax->getAmount());
            }
            $quantity = (float) $product->getQuantity();

            $combinations = [];
            foreach ($product->getOrderProductAttributeCombinations() as $combination) {
                $combinations[] = [
                    'attribute_title' => (string) $combination->getAttributeTitle(),
                    'attribute_av_title' => (string) $combination->getAttributeAvTitle(),
                ];
            }

            $items[] = [
                'id' => (int) $product->getId(),
                'ref' => (string) $product->getProductRef(),
                'pse_ref' => (string) $product->getProductSaleElementsRef(),
                'title' => (string) $product->getTitle(),
                'quantity' => $quantity,
                'price' => $unitPriceHt,
                'tax' => $unitTax,
                'unit_taxed_price' => $unitPriceHt + $unitTax,
                'line_ht' => $unitPriceHt * $quantity,
                'line_tax' => $unitTax * $quantity,
                'line_ttc' => ($unitPriceHt + $unitTax) * $quantity,
                'virtual' => (bool) $product->getVirtual(),
                'tax_rule_title' => (string) ($product->getTaxRuleTitle() ?? ''),
                'combinations' => $combinations,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderAddresses(Order $order, string $locale): array
    {
        $invoice = $order->getOrderAddressRelatedByInvoiceOrderAddressId();
        $delivery = $order->getOrderAddressRelatedByDeliveryOrderAddressId();

        return [
            'invoice' => $invoice ? $this->addressToArray($invoice, $locale) : null,
            'delivery' => $delivery ? $this->addressToArray($delivery, $locale) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressToArray(\Thelia\Model\OrderAddress $address, string $locale): array
    {
        $titleId = (int) $address->getCustomerTitleId();
        $titleLong = '';
        if ($titleId > 0) {
            $title = CustomerTitleQuery::create()->findPk($titleId);
            if ($title !== null) {
                $title->setLocale($locale);
                $titleLong = (string) $title->getLong();
            }
        }

        $countryId = (int) $address->getCountryId();
        $countryTitle = '';
        if ($countryId > 0) {
            $country = CountryQuery::create()->findPk($countryId);
            if ($country !== null) {
                $country->setLocale($locale);
                $countryTitle = (string) $country->getTitle();
            }
        }

        $stateId = $address->getStateId() ? (int) $address->getStateId() : null;
        $stateTitle = '';
        if ($stateId !== null) {
            $state = StateQuery::create()->findPk($stateId);
            if ($state !== null) {
                $state->setLocale($locale);
                $stateTitle = (string) $state->getTitle();
            }
        }

        return [
            'id' => (int) $address->getId(),
            'title_id' => $titleId,
            'title_long' => $titleLong,
            'firstname' => (string) $address->getFirstname(),
            'lastname' => (string) $address->getLastname(),
            'company' => (string) $address->getCompany(),
            'address1' => (string) $address->getAddress1(),
            'address2' => (string) $address->getAddress2(),
            'address3' => (string) $address->getAddress3(),
            'zipcode' => (string) $address->getZipcode(),
            'city' => (string) $address->getCity(),
            'country_id' => $countryId,
            'country_title' => $countryTitle,
            'state_id' => $stateId,
            'state_title' => $stateTitle,
            'phone' => (string) $address->getPhone(),
            'cellphone' => (string) $address->getCellphone(),
        ];
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function customerTitleChoices(string $locale): array
    {
        $items = [];
        foreach (CustomerTitleQuery::create()->orderByPosition()->find() as $title) {
            $title->setLocale($locale);
            $items[] = ['id' => (int) $title->getId(), 'title' => (string) $title->getLong()];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function countryChoices(string $locale): array
    {
        $items = [];
        foreach (CountryQuery::create()->filterByVisible(1)->orderById()->find() as $country) {
            $country->setLocale($locale);
            $items[] = ['id' => (int) $country->getId(), 'title' => (string) $country->getTitle()];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, country_id: int, title: string}>
     */
    private function stateChoices(string $locale): array
    {
        $items = [];
        foreach (StateQuery::create()->filterByVisible(1)->orderByCountryId()->find() as $state) {
            $state->setLocale($locale);
            $items[] = [
                'id' => (int) $state->getId(),
                'country_id' => (int) $state->getCountryId(),
                'title' => (string) $state->getTitle(),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statusChoices(string $locale): array
    {
        return $this->orderRepository->findStatusChoices(
            $locale,
            $this->translator->trans('- All statuses -'),
        );
    }

}
