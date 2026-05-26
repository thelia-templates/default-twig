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

namespace BackOfficeDefaultTwigBundle\Service\I18n;

use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Session\Session as TheliaSession;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * Resolves the locale used to edit translatable entities in the back-office.
 *
 * Order of precedence:
 *  1. `edit_language_id` query parameter (explicit switch via BoLanguageSwitcher).
 *  2. Last choice stored in the admin session.
 *  3. Language matching the admin UI locale (`Request::getLocale()`) on first visit.
 *  4. Default shop language.
 *
 * The resolved language is always persisted in the admin session so subsequent
 * pages without `edit_language_id` keep the previous choice.
 */
final readonly class EditLocaleResolver
{
    public const PARAMETER = 'edit_language_id';

    private const SESSION_KEY = 'thelia.admin.edition.lang';

    public function resolveLang(?int $editLanguageId): Lang
    {
        if ($editLanguageId !== null && $editLanguageId > 0) {
            $lang = LangQuery::create()->findPk($editLanguageId);
            if ($lang !== null) {
                return $lang;
            }
        }

        return Lang::getDefaultLanguage();
    }

    public function resolveLocale(?int $editLanguageId): string
    {
        return $this->resolveLang($editLanguageId)->getLocale() ?? 'en_US';
    }

    public function resolveFromRequest(Request $request): Lang
    {
        $queryParam = (int) $request->query->get(self::PARAMETER, 0);
        $session = $this->session($request);

        if ($queryParam > 0) {
            $lang = LangQuery::create()->findPk($queryParam);
            if ($lang !== null) {
                $session?->setAdminEditionLang($lang);

                return $lang;
            }
        }

        $stored = $session?->get(self::SESSION_KEY);
        if ($stored instanceof Lang) {
            return $stored;
        }

        $uiLang = LangQuery::create()->findOneByLocale($request->getLocale());
        if ($uiLang !== null) {
            $session?->setAdminEditionLang($uiLang);

            return $uiLang;
        }

        $default = Lang::getDefaultLanguage();
        $session?->setAdminEditionLang($default);

        return $default;
    }

    private function session(Request $request): ?TheliaSession
    {
        if (!$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();

        return $session instanceof TheliaSession ? $session : null;
    }
}
