<?php

declare(strict_types=1);

namespace App\Form\Developer;

use App\Security\OAuth\OAuthScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class OAuthClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $grantChoices = [
            'Client Credentials (M2M / server-to-server)' => 'client_credentials',
            'Authorization Code + PKCE (web / mobile apps)' => 'authorization_code',
            'Device Authorization (CLI / desktop apps)' => 'urn:ietf:params:oauth:grant-type:device_code',
            'Refresh Token' => 'refresh_token',
        ];

        $scopeChoices = [];
        foreach (OAuthScope::cases() as $scope) {
            $scopeChoices[ucfirst($scope->description())] = $scope->value;
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'Application name',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'What does this application do?'],
                'constraints' => [new Length(max: 500)],
            ])
            ->add('allowedGrants', ChoiceType::class, [
                'label' => 'Grant types',
                'choices' => $grantChoices,
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Count(min: 1, minMessage: 'Select at least one grant type.')],
            ])
            ->add('allowedScopes', ChoiceType::class, [
                'label' => 'Scopes',
                'choices' => $scopeChoices,
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Count(min: 1, minMessage: 'Select at least one scope.')],
            ])
            ->add('redirectUrisRaw', TextareaType::class, [
                'label' => 'Redirect URIs',
                'required' => false,
                'mapped' => false,
                'attr' => ['rows' => 3, 'placeholder' => "https://app.example.com/callback\nhttps://localhost:3000/callback"],
                'help' => 'One URI per line. Required for Authorization Code flow.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
