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

namespace BackOfficeDefaultTwigBundle\Form\Sale;

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
use Thelia\Model\Sale;

final class SaleType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', HiddenType::class, [
                'constraints' => [new NotBlank(), new GreaterThan(0)],
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ])
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Sale name or title'),
            ])
            ->add('label', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Sale label'),
            ])
            ->add('chapo', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Summary'),
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Description'),
            ])
            ->add('postscriptum', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Conclusion'),
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Activate this sale'),
            ])
            ->add('display_initial_price', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Display initial product prices on the front-office'),
            ])
            ->add('start_date', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Start date'),
            ])
            ->add('end_date', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('End date'),
            ])
            ->add('price_offset_type', ChoiceType::class, [
                'constraints' => [new NotBlank()],
                'choices' => [
                    $this->translator->trans('Percentage') => Sale::OFFSET_TYPE_PERCENTAGE,
                    $this->translator->trans('Constant amount') => Sale::OFFSET_TYPE_AMOUNT,
                ],
                'label' => $this->translator->trans('Discount type'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'admin.sale.modification',
        ]);
    }
}
