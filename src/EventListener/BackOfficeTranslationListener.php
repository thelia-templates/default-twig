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
use Thelia\Core\EventListener\SessionGuardTrait;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Translation\Translator;

/**
 * The Twig `|trans` filter resolves against the Symfony translator (domain
 * 'messages', auto-fed by every translations/messages.<locale>.php), while
 * PHP-side code such as presenters, controllers and forms resolves against the
 * Thelia translator (domain 'core'). This listener feeds the same catalogues to
 * the Thelia translator so both paths share one source of strings — for every
 * shipped locale, not just the default ones.
 *
 * It also aligns the request locale with the resolved back-office language so
 * the Twig `|trans` filter follows the admin (or, when logged out, the store
 * default) language. Without this, the request locale stays at Symfony's hard
 * 'en' default and the Twig chrome never localizes, even though the PHP side
 * already does.
 */
final class BackOfficeTranslationListener
{
    use SessionGuardTrait;

    #[AsEventListener(event: 'kernel.request', priority: 40)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        $translator = Translator::getInstance();
        $dir = \dirname(__DIR__, 2).'/translations';

        foreach (glob($dir.'/messages.*.php') ?: [] as $file) {
            if (preg_match('#/messages\.([a-z]{2}_[A-Z]{2})\.php$#', $file, $m)) {
                $translator->addResource('php', $file, $m[1], 'core');
            }
        }

        // Run before Symfony's TranslatorListener (priority 10), which copies the
        // request locale onto the translator used by the Twig |trans filter.
        if ($this->isSessionUsable($request)) {
            $session = $request->getSession();

            if ($session instanceof Session && null !== $lang = $session->getLang()) {
                $request->setLocale($lang->getLocale());
            }
        }
    }
}
