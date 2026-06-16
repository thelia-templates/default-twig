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

namespace BackOfficeDefaultTwigBundle\Translation;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Translation\Translator as TheliaTranslator;

/**
 * Module hook templates rendered in the Twig back-office call `|trans` with a
 * module domain (e.g. 'seone', 'admincomment.bo.default'). That filter resolves
 * against the Symfony translator, which only carries the theme 'messages'
 * catalogue, so those keys fall back to their English source.
 *
 * This decorator routes module domains to the Thelia translator, which already
 * holds every module and template catalogue plus the admin locale. Theme and
 * framework domains ('messages', 'validators', no domain) keep using the
 * Symfony translator untouched, and the delegation only applies to /admin
 * requests so the front office is left as-is.
 */
#[AsDecorator('translator')]
final readonly class ModuleAwareTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    public function __construct(
        #[AutowireDecorated]
        private TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
        private RequestStack $requestStack,
    ) {
    }

    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if (!$this->isModuleDomain($domain) || !$this->isAdminRequest()) {
            return $this->inner->trans($id, $parameters, $domain, $locale);
        }

        try {
            $theliaTranslator = TheliaTranslator::getInstance();
        } catch (\RuntimeException) {
            return $this->inner->trans($id, $parameters, $domain, $locale);
        }

        $translated = $theliaTranslator->trans(
            (string) $id,
            $parameters,
            $domain,
            $locale ?? $this->inner->getLocale(),
            false,
        );

        return '' !== $translated
            ? $translated
            : $this->inner->trans($id, $parameters, $domain, $locale);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->inner instanceof WarmableInterface) {
            return $this->inner->warmUp($cacheDir, $buildDir);
        }

        return [];
    }

    private function isModuleDomain(?string $domain): bool
    {
        return null !== $domain && 'messages' !== $domain && 'validators' !== $domain;
    }

    private function isAdminRequest(): bool
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request && str_starts_with($request->getPathInfo(), '/admin');
    }
}
