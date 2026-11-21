<?php

declare(strict_types=1);

namespace FormBridge\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The free text a model reads into a form on the "paste a message" page.
 */
final class MessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('text', TextareaType::class, [
            'label' => 'The message',
            'constraints' => [
                new NotBlank(message: 'Paste the message first.'),
                new Length(max: 5000),
            ],
        ]);
    }
}
