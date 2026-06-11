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

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\ActionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\TheliaFormEvent;
use Thelia\Core\HttpFoundation\Request;

/**
 * Replays the legacy module form-extension contract (FORM_BEFORE_BUILD/AFTER_BUILD
 * + ActionEvent form binding, normally provided by BaseForm) on the Symfony-native
 * mirror forms of this bundle.
 */
final readonly class LegacyFormEventBridge
{
    // Bundle form name => legacy BaseForm name (the per-entity SEO forms all mirror `thelia_seo`).
    private const LEGACY_NAME_ALIASES = [
        'thelia_product_seo' => 'thelia_seo',
        'thelia_category_seo' => 'thelia_seo',
        'thelia_content_seo' => 'thelia_seo',
        'thelia_folder_seo' => 'thelia_seo',
        'thelia_brand_seo_modification' => 'thelia_seo',
    ];

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private RequestStack $requestStack,
    ) {
    }

    public function dispatchBuildEvents(string $name, FormBuilderInterface $builder): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $event = new TheliaFormEvent(new MirroredLegacyForm($builder, $request instanceof Request ? $request : null));

        $names = [$name];
        if (isset(self::LEGACY_NAME_ALIASES[$name])) {
            $names[] = self::LEGACY_NAME_ALIASES[$name];
        }

        foreach ($names as $eventName) {
            $this->dispatcher->dispatch($event, TheliaEvents::FORM_BEFORE_BUILD.'.'.$eventName);
            $this->dispatcher->dispatch($event, TheliaEvents::FORM_AFTER_BUILD.'.'.$eventName);
        }
    }

    public function bindUnmappedFields(ActionEvent $event, FormInterface $form): void
    {
        foreach ($form->all() as $name => $child) {
            $setter = \sprintf('set%s', Container::camelize($name));

            // Fields with a real setter are already mapped by the controller event factory.
            if (method_exists($event, $setter)) {
                continue;
            }

            $event->{$name} = $child->getData();
        }
    }
}
