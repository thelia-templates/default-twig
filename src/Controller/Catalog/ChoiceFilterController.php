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

namespace BackOfficeDefaultTwigBundle\Controller\Catalog;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ChoiceFilter;
use Thelia\Model\ChoiceFilterQuery;

final class ChoiceFilterController
{
    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/choicefilter/save', name: 'admin.choicefilter.save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::PRODUCT, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $data = (array) $request->request->all('ChoiceFilter');
        [$templateId, $categoryId, $redirectUrl] = $this->resolveScope($data);

        if ($templateId !== null) {
            ChoiceFilterQuery::create()->filterByTemplateId($templateId)->delete();
            $base = (new ChoiceFilter())->setTemplateId($templateId);
        } else {
            \assert($categoryId !== null);
            ChoiceFilterQuery::create()->filterByCategoryId($categoryId)->delete();
            $base = (new ChoiceFilter())->setCategoryId($categoryId);
        }

        foreach ((array) ($data['filter'] ?? []) as $filter) {
            $entity = clone $base;
            $entity
                ->setVisible((int) ($filter['visible'] ?? 0))
                ->setPosition((int) ($filter['position'] ?? 0))
                ->setType((string) ($filter['display_type'] ?? ''));

            $type = (string) ($filter['type'] ?? '');
            $id = (int) ($filter['id'] ?? 0);
            match ($type) {
                'attribute' => $entity->setAttributeId($id),
                'feature' => $entity->setFeatureId($id),
                default => $entity->setOtherId($id),
            };

            $entity->save();
        }

        $this->flash($request, $this->translator->trans('Configuration saved successfully.'));

        return new RedirectResponse($redirectUrl);
    }

    #[Route('/admin/choicefilter/clear', name: 'admin.choicefilter.clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::PRODUCT, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $data = (array) $request->request->all('ChoiceFilter');
        [$templateId, $categoryId, $redirectUrl] = $this->resolveScope($data);

        if ($templateId !== null) {
            ChoiceFilterQuery::create()->filterByTemplateId($templateId)->delete();
        } else {
            \assert($categoryId !== null);
            ChoiceFilterQuery::create()->filterByCategoryId($categoryId)->delete();
        }

        $this->flash($request, $this->translator->trans('Configuration cleared.'));

        return new RedirectResponse($redirectUrl);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: int|null, 1: int|null, 2: string}
     */
    private function resolveScope(array $data): array
    {
        if (!empty($data['template_id'])) {
            $templateId = (int) $data['template_id'];

            return [$templateId, null, $this->urls->generate('admin.configuration.templates.update', ['template_id' => $templateId])];
        }

        if (!empty($data['category_id'])) {
            $categoryId = (int) $data['category_id'];

            return [null, $categoryId, $this->urls->generate('admin.categories.update', ['category_id' => $categoryId, 'current_tab' => 'associations']).'#choice-filter'];
        }

        throw new \RuntimeException('Missing template_id or category_id parameter.');
    }

    private function flash(Request $request, string $message): void
    {
        try {
            $session = $request->getSession();
            if (method_exists($session, 'getFlashBag')) {
                $session->getFlashBag()->add('choice-filter-success', $message);
            }
        } catch (\Throwable) {
        }
    }
}
