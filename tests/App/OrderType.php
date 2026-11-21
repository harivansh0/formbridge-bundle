<?php

declare(strict_types=1);

namespace FormBridge\Tests\App;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class OrderType extends AbstractType
{
    public function __construct(
        private readonly Orders $orders,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('product', ChoiceType::class, [
                'choices' => $this->orders->choices(),
                'placeholder' => 'Choose a product',
                'constraints' => [new Assert\NotBlank(message: 'Choose a product.')],
            ])
            ->add('quantity', IntegerType::class, [
                'help' => 'Bags of 250 g.',
                'constraints' => [new Assert\NotBlank(), new Assert\Range(min: 1, max: 5)],
            ])
            ->add('email', EmailType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->add('gift', CheckboxType::class, ['required' => false])
            ->add(
                $builder->create('delivery', FormType::class)
                    ->add('street', TextType::class, ['required' => false])
                    ->add('city', TextType::class, ['constraints' => [new Assert\NotBlank(message: 'Which city?')]]),
            );
    }
}
