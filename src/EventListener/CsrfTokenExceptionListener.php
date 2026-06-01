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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\Security\Exception\TokenAuthenticationException;

/**
 * Turns a failed CSRF token check on an admin route into a clean 403 (JSON for
 * XHR, plain otherwise) instead of a 500. Hand-rolled controllers call
 * TokenProvider::checkToken() which throws; without this the rejection of a
 * forged or stale request would surface as an Internal Server Error.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class CsrfTokenExceptionListener
{
    private const ADMIN_PATH_PREFIX = '/admin';

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof TokenAuthenticationException) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), self::ADMIN_PATH_PREFIX)) {
            return;
        }

        $message = 'Invalid or expired security token. Please reload the page and try again.';

        $event->setResponse($request->isXmlHttpRequest()
            ? new JsonResponse(['error' => $message], Response::HTTP_FORBIDDEN)
            : new Response($message, Response::HTTP_FORBIDDEN));
    }
}
