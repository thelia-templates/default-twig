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

namespace BackOfficeDefaultTwigBundle;

use BackOfficeDefaultTwigBundle\DependencyInjection\Compiler\BackOfficeTwigOnlyCompilerPass;
use BackOfficeDefaultTwigBundle\Hook\Attribute\AsHook;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class BackOfficeDefaultTwigBundle extends AbstractBundle
{
    public const ACTIVE_TEMPLATE_NAME = 'default-twig';

    private const ADMIN_TEMPLATE_PARAMETER = 'thelia_admin_template';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(
            new BackOfficeTwigOnlyCompilerPass(self::ACTIVE_TEMPLATE_NAME),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
        );

        $container->registerAttributeForAutoconfiguration(
            AsHook::class,
            static function (ChildDefinition $definition, AsHook $attribute, \Reflector $reflector): void {
                \assert($reflector instanceof \ReflectionMethod);
                $tag = [
                    'event' => $attribute->event,
                    'type' => $attribute->type,
                    'method' => $reflector->getName(),
                ];

                if (null !== $attribute->priority) {
                    $tag['priority'] = $attribute->priority;
                }

                $definition->addTag('hook.event_listener', $tag);
            },
        );
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isActive($builder)) {
            return;
        }

        $resourcePath = $this->getResourcePath();

        $container->services()
            ->load('BackOfficeDefaultTwigBundle\\', $resourcePath)
            ->exclude([
                $resourcePath.'/BackOfficeDefaultTwigBundle.php',
                $resourcePath.'/DTO/',
                $resourcePath.'/Hook/Attribute/',
                $resourcePath.'/DependencyInjection/',
                $resourcePath.'/Form/Legacy/MirroredLegacyForm.php',
            ])
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->alias(\Thelia\Core\Template\ParserHelperInterface::class, 'thelia.parser.helper');

        $container->services()
            ->alias(\Thelia\Core\Security\Resource\AdminResources::class, 'thelia.admin.resources');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isActive($builder)) {
            return;
        }

        $container->extension('twig', [
            'paths' => [
                $this->getViewsPath() => 'BackOfficeDefaultTwig',
            ],
        ]);

        $translationsPath = $this->getViewsPath().'/translations';
        if (is_dir($translationsPath)) {
            $container->extension('framework', [
                'translator' => [
                    'paths' => [$translationsPath],
                ],
            ]);
        }

        $packagesPath = $this->getConfigPath().'/packages';
        if (is_dir($packagesPath)) {
            $container->import($packagesPath.'/*.yaml');
        }
    }

    private function isActive(ContainerBuilder $builder): bool
    {
        if (!$builder->hasParameter(self::ADMIN_TEMPLATE_PARAMETER)) {
            return false;
        }

        return self::ACTIVE_TEMPLATE_NAME === $builder->getParameter(self::ADMIN_TEMPLATE_PARAMETER);
    }

    private function getResourcePath(): string
    {
        return __DIR__;
    }

    private function getConfigPath(): string
    {
        return \dirname(__DIR__).'/config';
    }

    private function getViewsPath(): string
    {
        // Templates live at the bundle root so the Thelia ParserResolver picks them up
        // automatically (`templates/backOffice/<active>/<name>.html.twig`).
        return \dirname(__DIR__);
    }
}
