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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpFoundation\Session\Session as TheliaSession;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\CustomerTitleQuery;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

final class TranslationsCustomerTitleController
{
    private const RESOURCE = AdminResources::CONFIG;
    private const ROUTE = 'admin.configuration.translations-customers-title';
    private const TEMPLATE = '@BackOfficeDefaultTwig/configuration/translations/customer-title.html.twig';
    private const SHORT_MAX_LENGTH = 10;
    private const LONG_MAX_LENGTH = 45;

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/configuration/translations-customers-title', name: 'admin.configuration.translations-customers-title', methods: ['GET'])]
    public function defaultAction(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $this->editionLocale($request);
        $titles = [];
        foreach (CustomerTitleQuery::create()->orderByPosition()->find() as $title) {
            $title->setLocale($locale);
            $titles[] = [
                'id' => (int) $title->getId(),
                'short' => (string) $title->getShort(),
                'long' => (string) $title->getLong(),
            ];
        }

        return new Response($this->twig->render(self::TEMPLATE, [
            'titles' => $titles,
            'edit_language_id' => $this->editionLanguageId($request),
            'edit_language_locale' => $locale,
            'available_languages' => $this->languageOptions(),
        ]));
    }

    #[Route('/admin/configuration/translations-customers-title/update', name: 'admin.configuration.translations-customers-title.update', methods: ['POST'])]
    public function updateAction(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token'));

        $locale = (string) ($request->request->get('locale') ?? $this->editionLocale($request));
        $titles = CustomerTitleQuery::create()->find();

        // customer_title_i18n.short is VARCHAR(10) and .long is VARCHAR(45). Validate the lengths up
        // front so an over-long value surfaces a friendly error instead of bubbling up as a Propel
        // SQLSTATE[22001] / HTTP 500. Mirrors the Length constraints of the core CustomerTitleI18nType.
        foreach ($titles as $title) {
            $shortValue = $request->request->get('short_title_'.(int) $title->getId());
            $longValue = $request->request->get('long_title_'.(int) $title->getId());
            if ($shortValue !== null && mb_strlen((string) $shortValue) > self::SHORT_MAX_LENGTH) {
                return $this->redirectWithError($request, self::SHORT_MAX_LENGTH, 'short');
            }
            if ($longValue !== null && mb_strlen((string) $longValue) > self::LONG_MAX_LENGTH) {
                return $this->redirectWithError($request, self::LONG_MAX_LENGTH, 'long');
            }
        }

        foreach ($titles as $title) {
            $shortValue = $request->request->get('short_title_'.(int) $title->getId());
            $longValue = $request->request->get('long_title_'.(int) $title->getId());
            if ($shortValue === null && $longValue === null) {
                continue;
            }
            $title->setLocale($locale)
                ->setShort((string) ($shortValue ?? ''))
                ->setLong((string) ($longValue ?? ''))
                ->save();
        }

        if ($request->request->get('save_mode') === 'close') {
            return new RedirectResponse('/admin/configuration');
        }

        return new RedirectResponse($this->urls->generate(self::ROUTE));
    }

    private function redirectWithError(Request $request, int $maxLength, string $field): RedirectResponse
    {
        $this->flashBag($request)?->add('danger', $this->translator->trans(
            'The %field title must not exceed %max characters.',
            ['%field' => $field, '%max' => $maxLength],
        ));

        $languageId = $this->editionLanguageId($request);

        return new RedirectResponse(
            $this->urls->generate(self::ROUTE, $languageId > 0 ? ['edit_language_id' => $languageId] : []),
        );
    }

    private function flashBag(Request $request): ?FlashBagInterface
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        return $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : null;
    }

    /** @return list<array{id: int, title: string, locale: string}> */
    private function languageOptions(): array
    {
        $options = [];
        foreach (LangQuery::create()->orderByPosition()->find() as $lang) {
            $options[] = [
                'id' => (int) $lang->getId(),
                'title' => (string) $lang->getTitle(),
                'locale' => (string) $lang->getLocale(),
            ];
        }

        return $options;
    }

    /**
     * The edition language id travels as a query param on GET navigation (BoLanguageSwitcher links)
     * and as a hidden body field on the update POST. Read both explicitly rather than via the
     * ambiguous $request->get().
     */
    private function editionIdParam(Request $request): ?string
    {
        $value = $request->query->get('edit_language_id') ?? $request->request->get('edit_language_id');

        return $value === null ? null : (string) $value;
    }

    private function editionLocale(Request $request): string
    {
        $editionId = $this->editionIdParam($request);
        if ($editionId !== null && (int) $editionId > 0) {
            $lang = LangQuery::create()->findPk((int) $editionId);
            if ($lang !== null) {
                return (string) $lang->getLocale();
            }
        }

        $session = $request->getSession();
        if ($session instanceof TheliaSession) {
            return (string) $session->getAdminEditionLang()->getLocale();
        }

        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return (string) ($defaultLang?->getLocale() ?? 'en_US');
    }

    private function editionLanguageId(Request $request): int
    {
        $editionId = $this->editionIdParam($request);
        if ($editionId !== null && (int) $editionId > 0) {
            return (int) $editionId;
        }

        $session = $request->getSession();
        if ($session instanceof TheliaSession) {
            return (int) $session->getAdminEditionLang()->getId();
        }

        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return (int) ($defaultLang?->getId() ?? 0);
    }
}
