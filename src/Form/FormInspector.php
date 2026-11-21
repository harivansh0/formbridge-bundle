<?php

declare(strict_types=1);

namespace FormBridge\Form;

use Symfony\Component\Form\Button;
use Symfony\Component\Form\ChoiceList\View\ChoiceGroupView;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Translation\TranslatableInterface;

final class FormInspector
{
    /**
     * True for the type itself and for types built on it (an EntityType is a ChoiceType).
     *
     * @param class-string $typeClass
     */
    public static function isType(FormInterface $field, string $typeClass): bool
    {
        for ($type = $field->getConfig()->getType(); null !== $type; $type = $type->getParent()) {
            if ($type->getInnerType() instanceof $typeClass) {
                return true;
            }
        }

        return false;
    }

    public static function isButton(FormInterface $field): bool
    {
        return $field instanceof Button;
    }

    public static function isChoice(FormInterface $field): bool
    {
        return self::isType($field, ChoiceType::class);
    }

    public static function isGroup(FormInterface $field): bool
    {
        return $field->getConfig()->getCompound() && !self::isChoice($field);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function choices(FormView $view): array
    {
        $choices = [];
        $collect = static function (iterable $items) use (&$collect, &$choices): void {
            foreach ($items as $item) {
                if ($item instanceof ChoiceGroupView) {
                    $collect($item->choices);
                } elseif ($item instanceof ChoiceView) {
                    $choices[$item->value] = [
                        'value' => $item->value,
                        'label' => self::text($item->label) ?? $item->value,
                    ];
                }
            }
        };
        $collect($view->vars['preferred_choices'] ?? []);
        $collect($view->vars['choices'] ?? []);

        return array_values($choices);
    }

    /**
     * Options as "value = label" pairs for a model. Labels are quoted as JSON strings: they often
     * hold text that users entered, and a quoted label cannot pass for a second option.
     *
     * @param list<array{value: string, label: string}> $choices
     */
    public static function describeChoices(array $choices): string
    {
        return implode('; ', array_map(
            static fn (array $choice): string => $choice['value'] === $choice['label']
                ? $choice['value']
                : $choice['value'].' = '.json_encode($choice['label'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            $choices,
        ));
    }

    public static function label(FormInterface $field): ?string
    {
        return self::text($field->getConfig()->getOption('label'));
    }

    public static function help(FormInterface $field): ?string
    {
        return self::text($field->getConfig()->getOption('help'));
    }

    /**
     * Dotted path below the root form, for example "address.city".
     */
    public static function path(FormInterface $field): string
    {
        $segments = [];
        for (; null !== $field->getParent(); $field = $field->getParent()) {
            array_unshift($segments, $field->getName());
        }

        return implode('.', $segments);
    }

    private static function text(mixed $value): ?string
    {
        if ($value instanceof TranslatableInterface || null === $value || false === $value || '' === $value) {
            return null;
        }

        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }
}
