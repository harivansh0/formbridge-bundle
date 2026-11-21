<?php

declare(strict_types=1);

namespace FormBridge\Tests\Fixtures;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One field of every kind FormBridge describes, to test them side by side.
 */
final class EveryFieldType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(min: 3, max: 50)]])
            ->add('summary', TextareaType::class, ['required' => false])
            ->add('contact', EmailType::class, ['required' => false, 'constraints' => [new Assert\Email()]])
            ->add('website', UrlType::class, ['required' => false, 'default_protocol' => null])
            ->add('guests', IntegerType::class, ['constraints' => [new Assert\Range(min: 1, max: 10)]])
            ->add('budget', NumberType::class, ['required' => false, 'constraints' => [new Assert\PositiveOrZero()]])
            ->add('newsletter', CheckboxType::class, ['required' => false])
            ->add('room', ChoiceType::class, ['choices' => ['Small room' => 'small', 'Large hall' => 'large']])
            ->add('extras', ChoiceType::class, ['choices' => ['Projector' => 'projector', 'Catering' => 'catering', 'Parking' => 'parking'], 'multiple' => true, 'required' => false])
            ->add('level', EnumType::class, ['class' => Level::class])
            ->add('day', DateType::class, ['required' => false])
            ->add('startsAt', DateTimeType::class, ['required' => false])
            ->add('doorsOpen', TimeType::class, ['required' => false])
            ->add(
                $builder->create('address', FormType::class)
                    ->add('street', TextType::class, ['required' => false])
                    ->add('city', TextType::class, ['constraints' => [new Assert\NotBlank(message: 'Which city?')]])
            )
            ->add('save', SubmitType::class);
    }
}
