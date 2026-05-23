<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Organization;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<Organization> */
final class OrgSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Organization name',
            'empty_data' => '',
            'constraints' => [
                new NotBlank(message: 'Organization name cannot be empty.'),
                new Length(max: 255, maxMessage: 'Organization name cannot exceed {{ limit }} characters.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Organization::class,
        ]);
    }
}
