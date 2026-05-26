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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Thelia\Domain\Module\Exception\InvalidModuleException;
use Thelia\Module\ModuleManagement;

/**
 * Scans the module directories, registers newly dropped modules and reports descriptor errors.
 *
 * The legacy Smarty back-office ran this on every module list render; here it is triggered on
 * demand (a "Check modules" button) to avoid a disk scan and database writes on each page load.
 */
final readonly class ModuleSynchronizer
{
    public function __construct(
        private ModuleManagement $moduleManagement,
        #[Autowire(service: 'service_container')]
        private ContainerInterface $container,
    ) {
    }

    /**
     * @return string|null a human-readable error summary, or null when every module is valid
     */
    public function synchronize(): ?string
    {
        try {
            $this->moduleManagement->updateModules($this->container);

            return null;
        } catch (InvalidModuleException $exception) {
            return $exception->getErrorsAsString();
        }
    }
}
