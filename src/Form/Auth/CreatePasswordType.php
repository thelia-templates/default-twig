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

namespace BackOfficeDefaultTwigBundle\Form\Auth;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Model\ConfigQuery;

final class CreatePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('password', PasswordType::class, [
                'label' => 'New password',
                'constraints' => [new NotBlank()],
            ])
            ->add('password_confirm', PasswordType::class, [
                'label' => 'Confirm new password',
                'constraints' => [
                    new NotBlank(),
                    new Callback($this->verifyPasswordPair(...)),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'admin.create-password',
        ]);
    }

    public function verifyPasswordPair(mixed $value, ExecutionContextInterface $context): void
    {
        $data = $context->getRoot()->getData();
        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirm'] ?? '');

        if ($password !== $confirm) {
            $context->addViolation('The two passwords do not match.');

            return;
        }

        $minLength = (int) ConfigQuery::getMinimuAdminPasswordLength();
        if (\strlen($password) < $minLength) {
            $context->addViolation(\sprintf('Password must be at least %d characters long.', $minLength));
        }
    }
}
