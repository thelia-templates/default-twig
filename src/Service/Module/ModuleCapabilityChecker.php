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
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Model\Module;

final readonly class ModuleCapabilityChecker
{
    public function __construct(
        private ModuleRepository $modules,
        private RouterInterface $router,
    ) {
    }

    public function isHookable(Module $module): bool
    {
        return $this->modules->countHooksForModule((int) $module->getId()) > 0;
    }

    public function isConfigurable(Module $module): bool
    {
        if (!$module->getActivate()) {
            return false;
        }

        $configurationHooks = $this->modules->countActiveConfigurationHooksForModule(
            (int) $module->getId(),
            TemplateDefinition::BACK_OFFICE,
        );

        if ($configurationHooks > 0) {
            return true;
        }

        return $this->moduleAdminRouteExists((string) $module->getCode());
    }

    private function moduleAdminRouteExists(string $code): bool
    {
        try {
            $this->router->match('/admin/module/'.$code);

            return true;
        } catch (ResourceNotFoundException) {
            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
