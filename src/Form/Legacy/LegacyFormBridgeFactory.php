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

namespace BackOfficeDefaultTwigBundle\Form\Legacy;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Fires the legacy module form events on this bundle's mirror forms only — other
 * forms pass straight through, and BaseForm builds its own isolated factory, so
 * events are never dispatched twice.
 */
#[AsDecorator('form.factory')]
final readonly class LegacyFormBridgeFactory implements FormFactoryInterface
{
    private const BRIDGED_NAME_PREFIX = 'thelia_';
    private const BRIDGED_TYPE_NAMESPACE = 'BackOfficeDefaultTwigBundle\\Form\\';

    public function __construct(
        #[AutowireDecorated]
        private FormFactoryInterface $inner,
        private LegacyFormEventBridge $bridge,
    ) {
    }

    public function createNamed(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormInterface
    {
        return $this->createNamedBuilder($name, $type, $data, $options)->getForm();
    }

    public function createNamedBuilder(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormBuilderInterface
    {
        $builder = $this->inner->createNamedBuilder($name, $type, $data, $options);

        if (str_starts_with($name, self::BRIDGED_NAME_PREFIX) && str_starts_with($type, self::BRIDGED_TYPE_NAMESPACE)) {
            $this->bridge->dispatchBuildEvents($name, $builder);
        }

        return $builder;
    }

    public function create(string $type = FormType::class, mixed $data = null, array $options = []): FormInterface
    {
        return $this->inner->create($type, $data, $options);
    }

    public function createForProperty(string $class, string $property, mixed $data = null, array $options = []): FormInterface
    {
        return $this->inner->createForProperty($class, $property, $data, $options);
    }

    public function createBuilder(string $type = FormType::class, mixed $data = null, array $options = []): FormBuilderInterface
    {
        return $this->inner->createBuilder($type, $data, $options);
    }

    public function createBuilderForProperty(string $class, string $property, mixed $data = null, array $options = []): FormBuilderInterface
    {
        return $this->inner->createBuilderForProperty($class, $property, $data, $options);
    }
}
