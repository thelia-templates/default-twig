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

namespace BackOfficeDefaultTwigBundle\Form;

use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Domain\Legal\CompanyIdentifier;
use Thelia\Domain\Legal\CompanyIdentifierRules;
use Thelia\Domain\Legal\CompanyIdentifierViolation;
use Thelia\Model\CountryQuery;

/**
 * The two legal identifiers of an address, for the back-office forms that carry a company name.
 *
 * Normalized on submit, the way ConfigStoreType handles the identifiers of the shop itself, so
 * that a number pasted from an official document is stored in one canonical form. The obligation
 * depends on the company name of the same form, so it is a form-level Callback reported on each
 * field, and never a NotBlank.
 */
trait LegalIdentifierFieldsTrait
{
    private function addLegalIdentifierFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('siret', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Company registration number'),
                'help' => $this->translator->trans('Required as soon as a company name is given. In France, the 14 digits of the SIRET.'),
            ])
            ->add('vat_number', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('VAT number'),
                'help' => $this->translator->trans('Required as soon as a company name is given. Country code followed by the national number.'),
            ]);

        $builder->get('siret')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $value): string => \is_string($value) ? $value : '',
            static fn (mixed $value): ?string => CompanyIdentifier::normalizeSiret(\is_string($value) ? $value : null),
        ));

        $builder->get('vat_number')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $value): string => \is_string($value) ? $value : '',
            static fn (mixed $value): ?string => CompanyIdentifier::normalizeVatNumber(\is_string($value) ? $value : null),
        ));
    }

    public function checkLegalIdentifiers(mixed $value, ExecutionContextInterface $context): void
    {
        if (!\is_array($value)) {
            return;
        }

        $violations = CompanyIdentifierRules::violationsFor(
            \is_string($value['company'] ?? null) ? $value['company'] : null,
            \is_string($value['siret'] ?? null) ? $value['siret'] : null,
            \is_string($value['vat_number'] ?? null) ? $value['vat_number'] : null,
            self::legalIdentifierCountryCode($value['country'] ?? null),
        );

        foreach ($violations as $violation) {
            $context
                ->buildViolation($this->translator->trans($violation->message, $violation->parameters))
                ->atPath(\sprintf('children[%s]', $violation->isAboutSiret() ? 'siret' : 'vat_number'))
                ->addViolation();
        }
    }

    /**
     * Whether a company name calls for identifiers that are not there, which the screens report
     * as a warning rather than blocking: an address saved before the columns existed is legitimate.
     *
     * @param array<string, mixed>|null $address
     */
    public static function legalIdentifiersAreIncomplete(?array $address): bool
    {
        if (null === $address || !CompanyIdentifier::hasCompany($address['company'] ?? null)) {
            return false;
        }

        return ($address['siret'] ?? null) === null || ($address['vat_number'] ?? null) === null
            || '' === $address['siret'] || '' === $address['vat_number'];
    }

    private static function legalIdentifierCountryCode(mixed $countryId): ?string
    {
        if (null === $countryId || '' === $countryId) {
            return null;
        }

        return CountryQuery::create()->findPk($countryId)?->getIsoalpha2();
    }

    /**
     * @return list<string>
     */
    private static function legalIdentifierFieldNames(): array
    {
        return [CompanyIdentifierViolation::FIELD_SIRET, 'vat_number'];
    }
}
