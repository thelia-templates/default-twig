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
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MessageType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('message_name', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Message code'),
            ])
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Title'),
            ])
            ->add('secured', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Secured (system message)'),
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ]);

        if ($options['include_id']) {
            $builder->add('id', HiddenType::class, [
                'constraints' => [new NotBlank(), new GreaterThan(0)],
            ]);
        }

        if ($options['include_body']) {
            $builder
                ->add('subject', TextType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Mail subject'),
                ])
                ->add('html_layout_file_name', ChoiceType::class, [
                    'required' => false,
                    'placeholder' => $this->translator->trans('Use default layout'),
                    'choices' => $this->choices($options['layout_choices']),
                    'label' => $this->translator->trans('HTML layout file'),
                ])
                ->add('html_template_file_name', ChoiceType::class, [
                    'required' => false,
                    'placeholder' => $this->translator->trans('Use HTML message defined below'),
                    'choices' => $this->choices($options['html_template_choices']),
                    'label' => $this->translator->trans('HTML template file'),
                ])
                ->add('html_message', TextareaType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('HTML message body'),
                ])
                ->add('text_layout_file_name', ChoiceType::class, [
                    'required' => false,
                    'placeholder' => $this->translator->trans('Use default layout'),
                    'choices' => $this->choices($options['layout_choices']),
                    'label' => $this->translator->trans('Text layout file'),
                ])
                ->add('text_template_file_name', ChoiceType::class, [
                    'required' => false,
                    'placeholder' => $this->translator->trans('Use text message defined below'),
                    'choices' => $this->choices($options['text_template_choices']),
                    'label' => $this->translator->trans('Text template file'),
                ])
                ->add('text_message', TextareaType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Plain text message body'),
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'include_id' => false,
                'include_body' => false,
                'csrf_token_id' => 'admin.message',
                'layout_choices' => [],
                'html_template_choices' => [],
                'text_template_choices' => [],
            ])
            ->setAllowedTypes('include_id', 'bool')
            ->setAllowedTypes('include_body', 'bool')
            ->setAllowedTypes('layout_choices', 'array')
            ->setAllowedTypes('html_template_choices', 'array')
            ->setAllowedTypes('text_template_choices', 'array');
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, string>
     */
    private function choices(array $files): array
    {
        $choices = [];
        foreach ($files as $name) {
            $choices[$name] = $name;
        }

        return $choices;
    }
}
