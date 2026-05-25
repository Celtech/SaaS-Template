<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Plan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/** @extends AbstractType<Plan> */
final class PlanType extends AbstractType
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
                'help' => 'Lowercase, hyphens only. Used in code and URLs.',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 100),
                    new Regex(
                        pattern: '/^[a-z0-9-]+$/',
                        message: 'Slug may only contain lowercase letters, numbers, and hyphens.',
                    ),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('monthlyPriceCents', IntegerType::class, [
                'label' => 'Monthly price (cents)',
                'help' => 'e.g. 1200 = $12.00. Set to 0 for free plans.',
                'constraints' => [new GreaterThanOrEqual(0)],
            ])
            ->add('annualPriceCents', IntegerType::class, [
                'label' => 'Annual price (cents)',
                'help' => 'e.g. 9900 = $99.00. Set to 0 to disable annual billing.',
                'constraints' => [new GreaterThanOrEqual(0)],
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'Sort order',
                'help' => 'Lower numbers appear first on the pricing page.',
            ])
            ->add('isFree', CheckboxType::class, [
                'label' => 'Mark as free plan',
                'required' => false,
                'help' => 'Free plans skip Stripe checkout.',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
                'help' => 'Inactive plans are hidden from the pricing page.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Plan::class,
        ]);
    }
}
