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
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * Resolves the locale in which i18n fields are edited, from the edit_language_id query parameter.
 *
 * Lets the back-office edit a translatable entity in a language other than the admin's interface
 * language, the way the legacy inner-form-toolbar language switcher did.
 */
final readonly class EditLocaleResolver
{
    public const PARAMETER = 'edit_language_id';

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
        return $this->resolveLang((int) $request->query->get(self::PARAMETER, 0) ?: null);
    }
}
