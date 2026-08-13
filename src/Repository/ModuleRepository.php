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

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\Module;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\ModuleHookQuery;
use Thelia\Model\ModuleQuery;

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
     * @return ObjectCollection<int, Module>
     */
    public function findActiveModulesByType(int $type, string $locale): ObjectCollection
    {
        /** @var ObjectCollection<int, Module> $modules */
        $modules = ModuleQuery::create()
            ->filterByType($type)
            ->filterByActivate(1)
            ->orderByPosition()
            ->find();

        foreach ($modules as $module) {
            $module->setLocale($locale);
        }

        return $modules;
    }

    /**
     * @return list<int>
     */
    public function findActiveModuleIdsByType(int $type): array
    {
        $ids = ModuleQuery::create()
            ->filterByType($type)
            ->filterByActivate(1)
            ->select('Id')
            ->find()
            ->getData();

        return array_map('intval', $ids);
    }

    /**
     * Configuration entries of the given modules, read in one go and indexed by module id.
     *
     * @param list<int> $moduleIds
     *
     * @return array<int, array<string, string|null>>
     */
    public function findConfigurationEntries(array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }

        $entries = [];
        $configs = ModuleConfigQuery::create()
            ->filterByModuleId($moduleIds, Criteria::IN)
            ->find();

        foreach ($configs as $config) {
            $entries[(int) $config->getModuleId()][(string) $config->getName()] = $config->getValue();
        }

        return $entries;
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
