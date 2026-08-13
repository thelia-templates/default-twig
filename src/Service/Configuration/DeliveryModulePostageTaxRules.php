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

namespace BackOfficeDefaultTwigBundle\Service\Configuration;

use BackOfficeDefaultTwigBundle\Repository\ModuleRepository;
use BackOfficeDefaultTwigBundle\Service\Module\ModuleCapabilityChecker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\TaxRuleQuery;
use Thelia\Module\AbstractDeliveryModule;
use Thelia\Module\BaseModule;

/**
 * Reads and writes the tax rule each delivery module applies to its postage.
 *
 * The value lives in module_config under the key the postage builder reads
 * ({@see AbstractDeliveryModule::POSTAGE_TAX_RULE_CONFIG_KEY}); no value means
 * the module falls back to the shop-wide delivery tax rule.
 */
final readonly class DeliveryModulePostageTaxRules
{
    /**
     * Modules shipping their own tax rule setting name it this way and pass it
     * to buildOrderPostage() themselves, which wins over the module_config key
     * below. Their row is flagged so the back office does not promise otherwise.
     */
    private const OWN_SETTING_SUFFIX = '_tax_rule_id';

    public function __construct(
        private ModuleRepository $modules,
        private ModuleCapabilityChecker $capabilities,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<array{id: int, code: string, title: string, tax_rule_id: int, has_own_setting: bool, configuration_url: string|null}>
     */
    public function rows(string $locale): array
    {
        $modules = $this->modules->findActiveModulesByType(BaseModule::DELIVERY_MODULE_TYPE, $locale);

        $moduleIds = [];
        foreach ($modules as $module) {
            $moduleIds[] = (int) $module->getId();
        }

        $configurations = $this->modules->findConfigurationEntries($moduleIds);

        $rows = [];
        foreach ($modules as $module) {
            $id = (int) $module->getId();
            $code = (string) $module->getCode();
            $entries = $configurations[$id] ?? [];

            $rows[] = [
                'id' => $id,
                'code' => $code,
                'title' => (string) ($module->getTitle() ?: $code),
                'tax_rule_id' => (int) ($entries[AbstractDeliveryModule::POSTAGE_TAX_RULE_CONFIG_KEY] ?? 0),
                'has_own_setting' => $this->hasOwnSetting($entries),
                'configuration_url' => $this->capabilities->isConfigurable($module)
                    ? $this->urls->generate('admin.module.configure', ['module_code' => $code])
                    : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $taxRuleIdByModuleId
     */
    public function save(array $taxRuleIdByModuleId): void
    {
        $deliveryModuleIds = $this->modules->findActiveModuleIdsByType(BaseModule::DELIVERY_MODULE_TYPE);
        $taxRuleIds = array_map('intval', TaxRuleQuery::create()->select('Id')->find()->getData());

        foreach ($taxRuleIdByModuleId as $moduleId => $taxRuleId) {
            $moduleId = (int) $moduleId;
            if (!\in_array($moduleId, $deliveryModuleIds, true)) {
                continue;
            }

            $taxRuleId = (int) $taxRuleId;
            if (!\in_array($taxRuleId, $taxRuleIds, true)) {
                ModuleConfigQuery::create()->deleteConfigValue($moduleId, AbstractDeliveryModule::POSTAGE_TAX_RULE_CONFIG_KEY);

                continue;
            }

            ModuleConfigQuery::create()->setConfigValue(
                $moduleId,
                AbstractDeliveryModule::POSTAGE_TAX_RULE_CONFIG_KEY,
                (string) $taxRuleId,
            );
        }
    }

    /**
     * @param array<string, string|null> $entries
     */
    private function hasOwnSetting(array $entries): bool
    {
        foreach (array_keys($entries) as $name) {
            if ($name !== AbstractDeliveryModule::POSTAGE_TAX_RULE_CONFIG_KEY && str_ends_with($name, self::OWN_SETTING_SUFFIX)) {
                return true;
            }
        }

        return false;
    }
}
