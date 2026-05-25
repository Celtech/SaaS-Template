<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Entitlement;
use App\Entity\EntitlementType as EntitlementTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/** @extends AbstractType<Entitlement> */
final class EntitlementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 255),
                ],
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'help' => 'Lowercase, underscores only. Used in code: entitlement(\'slug\').',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 100),
                    new Regex(
                        pattern: '/^[a-z0-9_]+$/',
                        message: 'Slug may only contain lowercase letters, numbers, and underscores.',
                    ),
                ],
            ])
            ->add('type', EnumType::class, [
                'label' => 'Type',
                'class' => EntitlementTypeEnum::class,
                'choice_label' => static fn (EntitlementTypeEnum $t) => ucfirst($t->value),
                'help' => 'boolean = on/off, integer = numeric limit, unlimited = no limit.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'Shown in plan cards and admin UI.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entitlement::class,
        ]);
    }
}
