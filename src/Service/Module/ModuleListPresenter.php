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

namespace BackOfficeDefaultTwigBundle\Service\Module;

use BackOfficeDefaultTwigBundle\Repository\ModuleRepository;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Module;
use Thelia\Module\BaseModule;
use Thelia\Tools\TokenProvider;

final readonly class ModuleListPresenter
{
    public function __construct(
        private ModuleRepository $modules,
        private ModuleCapabilityChecker $capabilities,
        private UrlGeneratorInterface $urls,
        private TokenProvider $tokens,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildListContext(string $locale): array
    {
        $groups = $this->initialGroups();

        foreach ($this->modules->findAllOrderedByPosition($locale) as $module) {
            \assert($module instanceof Module);
            $type = (int) $module->getType();
            if (!isset($groups[$type])) {
                $groups[$type] = ['slug' => 'other', 'label' => $this->translator->trans('Other modules'), 'rows' => []];
            }
            $groups[$type]['rows'][] = $this->moduleToRow($module);
        }

        return [
            'groups' => $groups,
            'update_position_url' => $this->urls->generate('admin.module.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
        ];
    }

    /**
     * @return array<int, array{slug: string, label: string, rows: list<array<string, mixed>>}>
     */
    private function initialGroups(): array
    {
        return [
            BaseModule::DELIVERY_MODULE_TYPE => ['slug' => 'delivery', 'label' => $this->translator->trans('Delivery modules'), 'rows' => []],
            BaseModule::PAYMENT_MODULE_TYPE => ['slug' => 'payment', 'label' => $this->translator->trans('Payment modules'), 'rows' => []],
            BaseModule::CLASSIC_MODULE_TYPE => ['slug' => 'classic', 'label' => $this->translator->trans('Classic modules'), 'rows' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleToRow(Module $module): array
    {
        $id = (int) $module->getId();
        $code = (string) $module->getCode();
        $activated = (bool) $module->getActivate();
        $type = (int) $module->getType();
        $mandatory = ((int) $module->getMandatory()) === 1;

        return [
            'id' => $id,
            'code' => $code,
            'title' => (string) $module->getTitle(),
            'type' => $type,
            'version' => (string) $module->getVersion(),
            'activated' => $activated,
            'mandatory' => $mandatory,
            'position' => (int) $module->getPosition(),
            'toggle_url' => $this->toggleActivationUrl($id, $mandatory, $activated),
            '_actions' => $this->buildRowActions($module, $id, $code, $type, $activated, $mandatory),
        ];
    }

    private function toggleActivationUrl(int $id, bool $mandatory, bool $activated): ?string
    {
        if ($mandatory && $activated) {
            return null;
        }

        $url = $this->urls->generate('admin.module.toggle-activation', ['module_id' => $id]);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'_token='.$this->tokens->assignToken();
    }

    /**
     * @return list<RowAction>
     */
    private function buildRowActions(Module $module, int $id, string $code, int $type, bool $activated, bool $mandatory): array
    {
        $actions = [];

        $actions[] = new RowAction(
            kind: 'info',
            label: $this->translator->trans('Module information'),
            modalTarget: '#module-information-modal',
            grantedAttribute: AccessManager::VIEW,
            grantedSubject: AdminResources::MODULE,
            dataAttributes: [
                'fetch-url' => $this->urls->generate('admin.module.information', ['module_id' => $id]),
            ],
        );

        $actions[] = new RowAction(
            kind: 'doc',
            label: $this->translator->trans('Module documentation'),
            modalTarget: '#module-documentation-modal',
            grantedAttribute: AccessManager::VIEW,
            grantedSubject: AdminResources::MODULE,
            dataAttributes: [
                'fetch-url' => $this->urls->generate('admin.module.documentation', ['module_id' => $id]),
            ],
        );

        if ($activated && $this->capabilities->isConfigurable($module)) {
            $actions[] = new RowAction(
                kind: 'config',
                label: $this->translator->trans('Configure this module'),
                href: $this->urls->generate('admin.module.configure', ['module_code' => $code]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: $code,
            );
        }

        if ($activated && $this->capabilities->isHookable($module)) {
            $actions[] = new RowAction(
                kind: 'hook',
                label: $this->translator->trans('Manage its hooks'),
                href: $this->urls->generate('admin.module-hook', ['module' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: AdminResources::MODULE_HOOK,
            );
        }

        if ($type === BaseModule::DELIVERY_MODULE_TYPE) {
            $actions[] = new RowAction(
                kind: 'shipping-zones',
                label: $this->translator->trans('Shipping zones'),
                href: $this->urls->generate('admin.configuration.shipping-zones.update.view', ['delivery_module_id' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: AdminResources::AREA,
            );
        }

        $actions[] = new RowAction(
            kind: 'edit',
            label: $this->translator->trans('Edit module info'),
            href: $this->urls->generate('admin.module.update', ['module_id' => $id]),
            grantedAttribute: AccessManager::UPDATE,
            grantedSubject: AdminResources::MODULE,
        );

        if (!$mandatory) {
            $actions[] = new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete this module'),
                modalTarget: '#module-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: AdminResources::MODULE,
                dataAttributes: ['module-id' => $id, 'module-label' => (string) $module->getTitle()],
            );
        }

        return $actions;
    }
}
