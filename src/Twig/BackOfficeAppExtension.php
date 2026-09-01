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

namespace BackOfficeDefaultTwigBundle\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagAwareSessionInterface;
use Thelia\Core\Security\SecurityContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * TwigEngine\Template\TwigParser::render() (vendor/thelia/modules) unconditionally
 * injects a local 'app' context variable — a bare stdClass exposing only
 * environment/request/session/debug — into every render() call. In Twig, a local
 * render-call variable shadows an environment global of the same name, so this
 * silently replaces Symfony's real AppVariable (app.flashes(), app.user, ...) for
 * the whole render, breaking any template relying on it.
 *
 * This can't be fixed in vendor/, so this theme avoids app.flashes()/app.user
 * entirely and uses these two functions instead, which read the same underlying
 * state (session flash bag, Thelia's admin security context) directly.
 */
final class BackOfficeAppExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SecurityContext $securityContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bo_flashes', $this->getFlashes(...)),
            new TwigFunction('bo_current_admin', $this->getCurrentAdmin(...)),
        ];
    }

    /**
     * Mirrors Symfony\Bridge\Twig\AppVariable::getFlashes() exactly, including its
     * type-dependent shape: no type given returns every message grouped by type;
     * a single type (string) returns that type's messages as a flat list; several
     * types (array) return messages grouped by type, one entry per requested type.
     */
    public function getFlashes(array|string|null $types = null): array
    {
        $request = $this->requestStack->getMainRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return [];
        }

        $flashBag = $session->getFlashBag();

        if (null === $types) {
            return $flashBag->all();
        }

        if (\is_string($types)) {
            return $flashBag->get($types);
        }

        $result = [];
        foreach ($types as $type) {
            $result[$type] = $flashBag->get($type);
        }

        return $result;
    }

    public function getCurrentAdmin(): mixed
    {
        return $this->securityContext->getAdminUser();
    }
}
