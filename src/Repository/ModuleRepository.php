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

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\Module;
use Thelia\Model\ModuleHookQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\OrderQuery;

final readonly class ModuleRepository
{
    /**
     * @return ObjectCollection<int, Module>
     */
    public function findAllOrderedByPosition(string $locale): ObjectCollection
    {
        /** @var ObjectCollection<int, Module> $modules */
        $modules = ModuleQuery::create()->orderByPosition()->find();

        foreach ($modules as $module) {
            $module->setLocale($locale);
        }

        return $modules;
    }

    /**
     * @return list<array{id: int, title: string, code: string}>
     */
    public function findActiveByType(int $type, string $locale): array
    {
        /** @var ObjectCollection<int, Module> $modules */
        $modules = ModuleQuery::create()
            ->filterByType($type)
            ->filterByActivate(1)
            ->orderByPosition()
            ->find();

        $items = [];
        foreach ($modules as $module) {
            $module->setLocale($locale);
            $items[] = [
                'id' => (int) $module->getId(),
                'title' => (string) ($module->getTitle() ?: $module->getCode()),
                'code' => (string) $module->getCode(),
            ];
        }

        return $items;
    }

    /**
     * Ids of the modules at least one order was placed with, as a payment or a delivery method.
     *
     * `order` keeps a live foreign key to `module` for both, with ON DELETE RESTRICT, so these
     * modules cannot be deleted. Two grouped reads rather than one count per module: the module
     * list renders every module on the same page.
     *
     * @return list<int>
     */
    public function findModuleIdsUsedByOrders(): array
    {
        $paymentIds = OrderQuery::create()->select('PaymentModuleId')->distinct()->find()->getData();
        $deliveryIds = OrderQuery::create()->select('DeliveryModuleId')->distinct()->find()->getData();

        $ids = array_unique(array_map('intval', array_merge($paymentIds, $deliveryIds)));
        // sort() reindexes, so the result is already the list the signature promises.
        sort($ids);

        return $ids;
    }

    public function countHooksForModule(int $moduleId): int
    {
        return ModuleHookQuery::create()
            ->filterByModuleId($moduleId)
            ->count();
    }

    public function countActiveConfigurationHooksForModule(int $moduleId, int $hookType): int
    {
        return ModuleHookQuery::create()
            ->filterByModuleId($moduleId)
            ->filterByActive(true)
            ->useHookQuery()
                ->filterByCode('module.configuration')
                ->filterByType($hookType)
            ->endUse()
            ->count();
    }
}
