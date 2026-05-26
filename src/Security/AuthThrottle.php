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

namespace BackOfficeDefaultTwigBundle\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Model\ConfigQuery;

final readonly class AuthThrottle
{
    private const DEFAULT_ATTEMPTS = 10;

    private const DEFAULT_WINDOW_SECONDS = 600;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private RequestStack $requests,
    ) {
    }

    public function consume(string $action): bool
    {
        $item = $this->cache->getItem($this->key($action));
        $state = $item->isHit() ? $item->get() : ['attempts' => 0, 'startedAt' => time()];

        $window = $this->windowSeconds();
        if ((time() - $state['startedAt']) > $window) {
            $state = ['attempts' => 0, 'startedAt' => time()];
        }

        ++$state['attempts'];
        $item->set($state);
        $item->expiresAfter($window);
        $this->cache->save($item);

        return $state['attempts'] <= $this->maxAttempts();
    }

    public function reset(string $action): void
    {
        $this->cache->deleteItem($this->key($action));
    }

    private function maxAttempts(): int
    {
        return (int) ConfigQuery::read('form_firewall_bruteforce_attempts', self::DEFAULT_ATTEMPTS);
    }

    private function windowSeconds(): int
    {
        return ((int) ConfigQuery::read('form_firewall_bruteforce_time_to_wait', 0)) * 60
            ?: self::DEFAULT_WINDOW_SECONDS;
    }

    private function key(string $action): string
    {
        $request = $this->requests->getCurrentRequest();
        $fingerprint = sha1(($request?->getClientIp() ?? 'unknown').'|'.$action);

        return 'bo_auth_throttle.'.$fingerprint;
    }
}
