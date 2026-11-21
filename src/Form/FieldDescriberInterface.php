<?php

declare(strict_types=1);

namespace FormBridge\Form;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * Describes fields FormBridge does not know, such as an application's own form types.
 *
 * Implementations are consulted before the built-in descriptions, in the order of their priority.
 */
interface FieldDescriberInterface
{
    /**
     * @return array<string, mixed>|null the field's JSON Schema, or null to leave it to the next describer
     */
    public function describe(FormInterface $field, FormView $view): ?array;
}
