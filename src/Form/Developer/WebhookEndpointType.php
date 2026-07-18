<?php

declare(strict_types=1);

namespace App\Form\Developer;

use App\Enum\WebhookEvent;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

/** @extends AbstractType<array<string, mixed>> */
final class WebhookEndpointType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $eventChoices = [];
        foreach (WebhookEvent::cases() as $event) {
            $eventChoices[$event->description()] = $event->value;
        }

        $builder
            ->add('url', UrlType::class, [
                'label' => 'Endpoint URL',
                'default_protocol' => 'https',
                'constraints' => [
                    new NotBlank(),
                    new Url(protocols: ['https'], message: 'The endpoint URL must use https://.'),
                ],
                'attr' => ['placeholder' => 'https://example.com/webhooks/incoming'],
            ])
            ->add('events', ChoiceType::class, [
                'label' => 'Events',
                'choices' => $eventChoices,
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Count(min: 1, minMessage: 'Select at least one event.')],
            ]);

        if ($options['include_active_toggle']) {
            $builder->add('isActive', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'include_active_toggle' => false,
        ]);
    }
}
