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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Form\Configuration\TagType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Tagging\Service\TagService;
use Thelia\Model\Tag;
use Thelia\Model\TagQuery;
use Twig\Environment;

/**
 * The tag vocabulary of the shop: what an administrator may put on a customer.
 *
 * Its own admin resource rather than the customer one: a profile allowed to
 * rename or delete a tag reaches every customer carrying it, which is not the
 * same reach as editing one customer.
 */
#[Route('/admin/configuration/tags', name: 'admin.configuration.tags.')]
final class TagController
{
    private const RESOURCE = AdminResources::TAG;
    private const LIST_ROUTE = 'admin.configuration.tags.default';
    private const EDIT_ROUTE = 'admin.configuration.tags.update';
    private const FORM_NAME = 'thelia_tag_update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/tag/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/tag/edit.html.twig';

    /**
     * The same shape the API resource validates a colour against.
     *
     * Re-checked here on the way out, not only on the way in: this value ends up
     * inside a style attribute, and a row written by an import, a module or a
     * hand-run SQL statement never passed the API validator.
     */
    private const COLOR_CODE_SHAPE = '/^#[0-9A-Fa-f]{6}$/';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly TagService $tags,
        private readonly FormFactoryInterface $formFactory,
        private readonly AdminFormValidator $validator,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $sort = ListSort::fromRequest($request, ['id', 'label'], 'label');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;

        $query = TagQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            default => $query->orderByLabel($criteria),
        };

        // One grouped query for the whole table rather than a count per row: the
        // screen shows every tag, so a per-row count would be one query per line.
        $customerCounts = $this->tags->countCustomersByTag();

        $rows = [];
        foreach ($query->find() as $tag) {
            \assert($tag instanceof Tag);
            $rows[] = [
                'id' => (int) $tag->getId(),
                'label' => (string) $tag->getLabel(),
                'color_code' => (string) $tag->getColorCode(),
                'color_swatch' => $this->colorSwatch((string) $tag->getColorCode()),
                'customer_count' => $customerCounts[$tag->getId()] ?? 0,
                '_actions' => [
                    new RowAction(
                        kind: 'edit',
                        label: $this->translator->trans('Edit'),
                        href: $this->urls->generate(self::EDIT_ROUTE, ['tag_id' => (int) $tag->getId()]),
                        grantedAttribute: AccessManager::UPDATE,
                        grantedSubject: self::RESOURCE,
                    ),
                ],
            ];
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/update', name: 'update', methods: ['GET'])]
    public function update(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $tag = TagQuery::create()->findPk($request->query->getInt('tag_id'));

        if (!$tag instanceof Tag) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'form' => $this->buildForm($tag)->createView(),
            'tag' => [
                'id' => (int) $tag->getId(),
                'label' => (string) $tag->getLabel(),
                'customer_count' => $this->tags->countCustomersByTag()[$tag->getId()] ?? 0,
            ],
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $this->buildForm();

        try {
            $validated = $this->validator->validate($form);
            $data = $validated->getData();

            $tag = TagQuery::create()->findPk((int) $data['id'])
                ?? throw new \InvalidArgumentException('This tag no longer exists.');

            // The checkbox is what expresses "no colour": a native colour input has
            // no empty state and always posts a value, black by default.
            $colorCode = ($data['noColor'] ?? false) === true
                ? null
                : (($data['colorCode'] ?? '') === '' ? null : (string) $data['colorCode']);

            $this->tags->rename($tag, (string) $data['label'], $colorCode);

            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'Tag renamed', (int) $tag->getId());

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup(
                $this->translator->trans('Tag update failed.'),
                $exception->getMessage(),
                $form,
                $exception,
            );

            return new Response(
                $this->twig->render(self::EDIT_TEMPLATE, [
                    'form' => $form->createView(),
                    'tag' => ['id' => 0, 'label' => '', 'customer_count' => 0],
                ]),
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    private function buildForm(?Tag $tag = null): FormInterface
    {
        return $this->formFactory->createNamed(self::FORM_NAME, TagType::class, $tag instanceof Tag ? [
            'id' => (int) $tag->getId(),
            'label' => (string) $tag->getLabel(),
            'colorCode' => $tag->getColorCode(),
            'noColor' => $tag->getColorCode() === null || $tag->getColorCode() === '',
        ] : []);
    }

    /**
     * A coloured square, or nothing at all when the stored value is not a colour.
     */
    private function colorSwatch(string $colorCode): string
    {
        if (preg_match(self::COLOR_CODE_SHAPE, $colorCode) !== 1) {
            return '';
        }

        return \sprintf(
            '<span class="d-inline-block rounded border" style="width:1rem;height:1rem;background-color:%s" aria-hidden="true"></span>',
            $colorCode,
        );
    }
}
