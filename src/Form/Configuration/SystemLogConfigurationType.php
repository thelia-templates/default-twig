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

namespace BackOfficeDefaultTwigBundle\Form\Configuration;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Log\Tlog;

final class SystemLogConfigurationType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('level', ChoiceType::class, [
                'choices' => [
                    $this->translator->trans('Disabled') => Tlog::MUET,
                    $this->translator->trans('Debug') => Tlog::DEBUG,
                    $this->translator->trans('Information') => Tlog::INFO,
                    $this->translator->trans('Notices') => Tlog::NOTICE,
                    $this->translator->trans('Warnings') => Tlog::WARNING,
                    $this->translator->trans('Errors') => Tlog::ERROR,
                    $this->translator->trans('Critical') => Tlog::CRITICAL,
                    $this->translator->trans('Alerts') => Tlog::ALERT,
                    $this->translator->trans('Emergency') => Tlog::EMERGENCY,
                ],
                'label' => $this->translator->trans('Log level'),
            ])
            ->add('format', TextType::class, [
                'label' => $this->translator->trans('Log format'),
            ])
            ->add('show_redirections', ChoiceType::class, [
                'choices' => [
                    $this->translator->trans('Yes') => 1,
                    $this->translator->trans('No') => 0,
                ],
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Show redirections'),
            ])
            ->add('files', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Activate logs only for these files'),
            ])
            ->add('ip_addresses', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Activate logs only for these IP addresses'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'admin.configuration.system-logs',
        ]);
    }
}
