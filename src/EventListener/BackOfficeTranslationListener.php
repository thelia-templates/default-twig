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

namespace BackOfficeDefaultTwigBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Thelia\Core\Translation\Translator;

/**
 * The Twig `|trans` filter resolves against the Symfony translator (domain
 * 'messages', fed by the translations/ directory), while PHP-side code such as
 * presenters and forms resolves against the Thelia translator (domain 'core').
 * This listener feeds the same catalogue to the Thelia translator so both paths
 * share one source of French strings.
 */
final class BackOfficeTranslationListener
{
    private const LOCALES = ['fr_FR', 'en_US'];

    #[AsEventListener(event: 'kernel.request', priority: 40)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/admin')) {
            return;
        }

        $translator = Translator::getInstance();
        $dir = \dirname(__DIR__, 2).'/translations';

        foreach (self::LOCALES as $locale) {
            $file = $dir.'/messages.'.$locale.'.php';
            if (is_file($file)) {
                $translator->addResource('php', $file, $locale, 'core');
            }
        }
    }
}
