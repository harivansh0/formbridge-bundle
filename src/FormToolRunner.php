<?php

declare(strict_types=1);

namespace FormBridge;

use FormBridge\Event\FormDraftedEvent;
use FormBridge\Event\FormSavedEvent;
use FormBridge\Event\FormSubmitEvent;
use FormBridge\Form\FormInspector;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRegistryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Puts values through a form tool's form. Every caller ends up here: MCP clients, Symfony AI
 * agents, the form in the conversation and the review page.
 */
final class FormToolRunner
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly FormRegistryInterface $formRegistry,
        private readonly FormToolRegistry $tools,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The form is unnamed, so that its fields are called "seats" rather than "booking[seats]".
     */
    public function createForm(FormTool $tool): FormInterface
    {
        $options = [];
        // No browser session here. The review page and the submit tool carry their own tokens.
        if ($this->formRegistry->getType($tool->form)->getOptionsResolver()->isDefined('csrf_protection')) {
            $options['csrf_protection'] = false;
        }

        return $this->formFactory->createNamed('', $tool->form, null, $options);
    }

    /**
     * Fills the form in and validates it on behalf of an agent. Never saves.
     *
     * @param array<string, mixed> $values
     */
    public function draft(string $toolName, array $values): FormOutcome
    {
        $outcome = $this->review($toolName, $values);

        $this->logger->info('Form tool "{tool}" was filled in.', ['tool' => $toolName, 'valid' => $outcome->valid]);
        $this->dispatcher?->dispatch(new FormDraftedEvent($outcome));

        return $outcome;
    }

    /**
     * Validates values for display, without saving and without announcing a new draft.
     *
     * @param array<string, mixed> $values
     */
    public function review(string $toolName, array $values): FormOutcome
    {
        $tool = $this->tools->get($toolName);
        $form = $this->createForm($tool);
        $form->submit($values);

        return $this->outcome($tool, $form);
    }

    /**
     * Validates the form and, if it is valid, runs the application's handler with its data.
     *
     * A listener of FormSubmitEvent may refuse first. The handler may still refuse by throwing a
     * ValidationFailedException, before it writes; either way the messages are put on the form.
     *
     * @param array<string, mixed> $values
     */
    public function submit(string $toolName, array $values): FormOutcome
    {
        $tool = $this->tools->get($toolName);
        $form = $this->createForm($tool);
        $form->submit($values);

        if (!$form->isValid()) {
            return $this->outcome($tool, $form);
        }

        $data = $form->getData();

        $submit = new FormSubmitEvent($tool, $data);
        $this->dispatcher?->dispatch($submit);
        if (null !== $refusal = $submit->getRefusal()) {
            $form->addError(new FormError($refusal));
            $this->logger->notice('Form tool "{tool}" was refused by a listener.', ['tool' => $toolName]);

            return $this->outcome($tool, $form);
        }

        try {
            $result = $this->tools->handle($tool, $data);
        } catch (ValidationFailedException $refused) {
            foreach ($refused->getViolations() as $violation) {
                $this->fieldAt($form, $violation->getPropertyPath())->addError(new FormError((string) $violation->getMessage()));
            }
            $this->logger->notice('Form tool "{tool}" was refused by its handler.', ['tool' => $toolName]);

            return $this->outcome($tool, $form);
        }

        $outcome = $this->outcome($tool, $form, saved: true, result: match (true) {
            \is_string($result) => $result,
            $result instanceof \Stringable => (string) $result,
            default => 'Saved.',
        });

        $this->logger->info('Form tool "{tool}" was saved.', ['tool' => $toolName]);
        $this->dispatcher?->dispatch(new FormSavedEvent($outcome, $data, $result));

        return $outcome;
    }

    /**
     * Resolves "seats", "address.city" or "address[city]"; falls back to the form itself.
     */
    private function fieldAt(FormInterface $form, string $path): FormInterface
    {
        $field = $form;
        foreach (preg_split('/[.\[\]]+/', $path, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            if (!$field->has($name)) {
                return $form;
            }
            $field = $field->get($name);
        }

        return $field;
    }

    private function outcome(FormTool $tool, FormInterface $form, bool $saved = false, ?string $result = null): FormOutcome
    {
        $view = $form->createView();

        return new FormOutcome(
            $tool,
            $form,
            $form->isValid(),
            $saved,
            $this->values($form),
            $this->errors($form, $view),
            $result,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function values(FormInterface $form): array
    {
        $values = [];
        foreach ($form->all() as $name => $field) {
            if (FormInspector::isButton($field)) {
                continue;
            }

            $values[$name] = match (true) {
                FormInspector::isGroup($field) => $this->values($field),
                FormInspector::isType($field, CheckboxType::class) => true === $field->getData(),
                FormInspector::isType($field, IntegerType::class),
                FormInspector::isType($field, NumberType::class) => $field->isSynchronized() ? $field->getData() : $field->getViewData(),
                default => '' === $field->getViewData() ? null : $field->getViewData(),
            };
        }

        return $values;
    }

    /**
     * Each error with its field and, for a choice field, the choices the form offers right now.
     *
     * @return list<array{field: string, message: string, choices?: list<array{value: string, label: string}>}>
     */
    private function errors(FormInterface $form, FormView $view): array
    {
        $errors = [];
        foreach ($form->getErrors(true, true) as $error) {
            if (!$error instanceof FormError) {
                continue;
            }
            $origin = $error->getOrigin() ?? $form;
            $entry = ['field' => FormInspector::path($origin), 'message' => $error->getMessage()];

            if (FormInspector::isChoice($origin)) {
                $fieldView = $view;
                foreach (explode('.', $entry['field']) as $segment) {
                    $fieldView = $fieldView[$segment];
                }
                $entry['choices'] = FormInspector::choices($fieldView);
            }

            $errors[] = $entry;
        }

        return $errors;
    }
}
