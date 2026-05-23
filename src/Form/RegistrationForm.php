<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<null> */
class RegistrationForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inviteEmail = $options['invite_email'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Full name',
                'constraints' => [
                    new NotBlank(message: 'Please enter your name'),
                    new Length(min: 2, max: 100),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'data' => $inviteEmail,
                'attr' => $inviteEmail !== null ? ['readonly' => true] : [],
                'constraints' => [
                    new NotBlank(message: 'Please enter an email address'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'Password'],
                'second_options' => ['label' => 'Confirm password'],
                'invalid_message' => 'The passwords do not match.',
                'constraints' => [
                    new NotBlank(message: 'Please enter a password'),
                    new Length(
                        min: 12,
                        max: 4096,
                        minMessage: 'Your password must be at least {{ limit }} characters.',
                    ),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'label' => 'I agree to the Terms of Service and Privacy Policy',
                'constraints' => [
                    new IsTrue(message: 'You must agree to the terms.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'invite_email' => null,
        ]);
        $resolver->setAllowedTypes('invite_email', ['null', 'string']);
    }
}
