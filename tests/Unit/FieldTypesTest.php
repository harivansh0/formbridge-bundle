<?php

declare(strict_types=1);

namespace FormBridge\Tests\Unit;

use FormBridge\Exception\UnsupportedFieldException;
use FormBridge\Form\ConstraintDescriberInterface;
use FormBridge\Form\FieldDescriberInterface;
use FormBridge\Form\FormInspector;
use FormBridge\Form\FormSchemaFactory;
use FormBridge\FormOutcome;
use FormBridge\FormToolRegistry;
use FormBridge\FormToolRunner;
use FormBridge\FormToolSchemas;
use FormBridge\Tests\Fixtures\EveryFieldType;
use FormBridge\Tests\Fixtures\Level;
use FormBridge\Tests\Fixtures\ScanType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\Extension\Core\CoreExtension;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\ResetType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRegistry;
use Symfony\Component\Form\FormRegistryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\ResolvedFormTypeFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Every kind of field: how it is described to an agent, what it accepts, and what comes back.
 */
final class FieldTypesTest extends TestCase
{
    private const VALID = [
        'title' => 'Team offsite',
        'guests' => 4,
        'room' => 'large',
        'level' => 'advanced',
        'address' => ['city' => 'Lyon'],
    ];

    private ValidatorInterface $validator;
    private FormFactoryInterface $forms;
    private FormRegistryInterface $registry;
    /** @var list<mixed> what the handler was called with */
    private array $handled = [];

    protected function setUp(): void
    {
        $this->validator = Validation::createValidator();
        $this->registry = new FormRegistry([new CoreExtension(), new ValidatorExtension($this->validator)], new ResolvedFormTypeFactory());
        $this->forms = new FormFactory($this->registry);
    }

    public function testEachFieldIsDescribedWithItsTypeAndItsRulesInWords(): void
    {
        $properties = (new FormSchemaFactory($this->validator))->inputSchema($this->forms->createNamed('', EveryFieldType::class))['properties'];

        self::assertSame('string', $properties['title']['type']);
        self::assertSame('Required. Between 3 and 50 characters.', $properties['title']['description']);
        self::assertSame('An email address.', $properties['contact']['description']);
        self::assertSame('A web address.', $properties['website']['description']);
        self::assertSame('integer', $properties['guests']['type']);
        self::assertSame('Between 1 and 10.', $properties['guests']['description']);
        self::assertSame('number', $properties['budget']['type']);
        self::assertSame('0 or more.', $properties['budget']['description']);
        self::assertSame('boolean', $properties['newsletter']['type']);
        self::assertSame('Date as YYYY-MM-DD.', $properties['day']['description']);
        self::assertSame('Date and time as YYYY-MM-DDTHH:MM.', $properties['startsAt']['description']);
        self::assertSame('Time as HH:MM.', $properties['doorsOpen']['description']);
    }

    public function testChoiceFieldsListTheSubmittedValuesWithTheirLabelsAndEnforceNeither(): void
    {
        $properties = (new FormSchemaFactory($this->validator))->inputSchema($this->forms->createNamed('', EveryFieldType::class))['properties'];

        self::assertSame('string', $properties['room']['type']);
        self::assertStringContainsString('small = Small room; large = Large hall', $properties['room']['description']);
        self::assertSame(['type' => 'string'], $properties['extras']['items']);
        self::assertStringContainsString('projector = Projector; catering = Catering; parking = Parking', $properties['extras']['description']);
        self::assertStringContainsString('beginner = Beginner; advanced = Advanced', $properties['level']['description']);
        // The form, not the schema, says whether a choice exists: it has the current list.
        self::assertArrayNotHasKey('enum', $properties['room']);
    }

    public function testAGroupOfFieldsIsAnObjectAndButtonsAreLeftOut(): void
    {
        $properties = (new FormSchemaFactory($this->validator))->inputSchema($this->forms->createNamed('', EveryFieldType::class))['properties'];

        self::assertSame('object', $properties['address']['type']);
        self::assertSame(['street', 'city'], array_keys($properties['address']['properties']));
        self::assertFalse($properties['address']['additionalProperties'], 'A group takes no fields the form does not have.');
        self::assertArrayNotHasKey('save', $properties);
    }

    public function testAFileUploadIsRefusedLoudlyRatherThanLeftOut(): void
    {
        $form = $this->forms->createNamedBuilder('')->add('attachment', FileType::class)->getForm();

        $this->expectException(UnsupportedFieldException::class);

        (new FormSchemaFactory($this->validator))->inputSchema($form);
    }

    public function testACollectionIsRefusedLoudlyToo(): void
    {
        $form = $this->forms->createNamedBuilder('')->add('guests', CollectionType::class)->getForm();

        $this->expectException(UnsupportedFieldException::class);

        (new FormSchemaFactory($this->validator))->inputSchema($form);
    }

    public function testADateSplitIntoSeveralControlsIsRefusedRatherThanDescribedWrongly(): void
    {
        $form = $this->forms->createNamedBuilder('')->add('day', DateType::class, ['widget' => 'choice'])->getForm();

        $this->expectException(UnsupportedFieldException::class);
        $this->expectExceptionMessage('single_text');

        (new FormSchemaFactory($this->validator))->inputSchema($form);
    }

    public function testADateInItsOwnTextFormatIsRefusedAndSecondsAreDescribed(): void
    {
        $withSeconds = $this->forms->createNamedBuilder('')->add('doorsOpen', TimeType::class, ['with_seconds' => true])->getForm();
        self::assertSame('Time as HH:MM:SS.', (new FormSchemaFactory($this->validator))->inputSchema($withSeconds)['properties']['doorsOpen']['description']);

        $ownFormat = $this->forms->createNamedBuilder('')->add('day', DateType::class, ['html5' => false, 'format' => 'dd/MM/yyyy'])->getForm();

        $this->expectException(UnsupportedFieldException::class);
        $this->expectExceptionMessage('html5');

        (new FormSchemaFactory($this->validator))->inputSchema($ownFormat);
    }

    public function testATimeWithoutMinutesIsRefused(): void
    {
        $form = $this->forms->createNamedBuilder('')->add('hour', TimeType::class, ['widget' => 'single_text', 'with_minutes' => false])->getForm();

        $this->expectException(UnsupportedFieldException::class);
        $this->expectExceptionMessage('with_minutes');

        (new FormSchemaFactory($this->validator))->inputSchema($form);
    }

    public function testAnApplicationCanDescribeItsOwnFieldsAndConstraints(): void
    {
        $upload = new class implements FieldDescriberInterface {
            public function describe(FormInterface $field, FormView $view): ?array
            {
                return FormInspector::isType($field, FileType::class) ? ['type' => 'string', 'description' => 'Address of a file that is already uploaded.'] : null;
            }
        };
        $pattern = new class implements ConstraintDescriberInterface {
            public function describe(Constraint $constraint): ?string
            {
                return $constraint instanceof Regex ? 'Letters and digits only.' : null;
            }
        };
        $form = $this->forms->createNamedBuilder('')
            ->add('scan', FileType::class, ['help' => 'The signed form.'])
            ->add('code', TextType::class, ['constraints' => [new Regex(pattern: '/^[a-z0-9]+$/'), new NotBlank()]])
            ->getForm();

        $properties = (new FormSchemaFactory($this->validator, [$upload], [$pattern]))->inputSchema($form)['properties'];

        self::assertSame(['type' => 'string', 'description' => 'Address of a file that is already uploaded. The signed form.'], $properties['scan']);
        self::assertSame('Letters and digits only. Required.', $properties['code']['description']);
    }

    public function testEveryRuleOfTheSameKindIsDescribed(): void
    {
        $pattern = new class implements ConstraintDescriberInterface {
            public function describe(Constraint $constraint): ?string
            {
                return $constraint instanceof Regex ? (string) $constraint->message : null;
            }
        };
        $form = $this->forms->createNamedBuilder('')
            ->add('code', TextType::class, ['constraints' => [
                new Regex(pattern: '/^[a-z]/', message: 'Starts with a letter.'),
                new Regex(pattern: '/[0-9]/', message: 'Contains a digit.'),
            ]])
            ->getForm();

        $description = (new FormSchemaFactory($this->validator, [], [$pattern]))->inputSchema($form)['properties']['code']['description'];

        self::assertSame('Starts with a letter. Contains a digit.', $description);
    }

    public function testADescriberThatReturnsAnUnusableSchemaIsReportedByName(): void
    {
        $broken = new class implements FieldDescriberInterface {
            public function describe(FormInterface $field, FormView $view): ?array
            {
                return ['type' => 'colour'];
            }
        };
        $form = $this->forms->createNamedBuilder('')->add('shade', TextType::class)->getForm();

        $this->expectException(UnsupportedFieldException::class);
        $this->expectExceptionMessage('the field "shade"');

        (new FormSchemaFactory($this->validator, [$broken]))->inputSchema($form);
    }

    public function testAFormThatCannotBeDescribedIsLeftOutInProductionAndAnErrorInDebugMode(): void
    {
        $tools = new FormToolRegistry(
            [
                'plan_event' => ['form' => EveryFieldType::class, 'description' => 'Plan an event.', 'title' => null, 'submit_label' => 'Save', 'method' => '__invoke'],
                'send_scan' => ['form' => ScanType::class, 'description' => 'Send a scan.', 'title' => null, 'submit_label' => 'Send', 'method' => '__invoke'],
            ],
            new ServiceLocator([]),
        );
        $runner = new FormToolRunner($this->forms, $this->registry, $tools);
        $factory = new FormSchemaFactory($this->validator);

        $described = (new FormToolSchemas($tools, $runner, $factory, debug: false))->all();
        self::assertSame(['plan_event'], array_map(static fn (array $entry): string => $entry[0]->name, $described));

        $this->expectException(UnsupportedFieldException::class);
        (new FormToolSchemas($tools, $runner, $factory, debug: true))->all();
    }

    public function testEveryKindOfButtonIsLeftOut(): void
    {
        $form = $this->forms->createNamedBuilder('')
            ->add('title', TextType::class)
            ->add('save', SubmitType::class)
            ->add('clear', ResetType::class)
            ->add('help', ButtonType::class)
            ->getForm();

        $schema = (new FormSchemaFactory($this->validator))->inputSchema($form);

        self::assertSame(['title'], array_keys($schema['properties']));
    }

    public function testAFormWithNoFieldsIsStillAValidSchema(): void
    {
        $form = $this->forms->createNamedBuilder('')->add('confirm', SubmitType::class)->getForm();

        $schema = (new FormSchemaFactory($this->validator))->inputSchema($form);

        self::assertSame('{"type":"object","properties":{},"additionalProperties":false}', json_encode($schema, \JSON_THROW_ON_ERROR));
    }

    public function testARuleThatDoesNotApplyByDefaultIsNotDescribed(): void
    {
        $form = $this->forms->createNamedBuilder('')
            ->add('reference', TextType::class, ['required' => false, 'constraints' => [new NotBlank(groups: ['invoice'])]])
            ->getForm();

        $schema = (new FormSchemaFactory($this->validator))->inputSchema($form);

        self::assertArrayNotHasKey('description', $schema['properties']['reference']);
    }

    public function testValuesOfEveryKindAreAcceptedTheWayAnAgentSendsThem(): void
    {
        $outcome = $this->draft([
            'summary' => 'Two days of planning.',
            'contact' => 'ana@example.com',
            'website' => 'https://example.com/offsite',
            'budget' => 1250.5,
            'newsletter' => true,
            'extras' => ['projector', 'parking'],
            'day' => '2027-03-14',
            'startsAt' => '2027-03-14T09:30',
            'doorsOpen' => '09:00',
            'address' => ['street' => '1 Rue de la Paix', 'city' => 'Lyon'],
        ] + self::VALID);

        self::assertSame([], $outcome->errors);
        self::assertTrue($outcome->valid);
        self::assertSame(4, $outcome->values['guests']);
        self::assertSame(1250.5, $outcome->values['budget']);
        self::assertTrue($outcome->values['newsletter']);
        self::assertSame(['projector', 'parking'], $outcome->values['extras']);
        self::assertSame('advanced', $outcome->values['level']);
        self::assertSame('2027-03-14', $outcome->values['day']);
        self::assertSame(['street' => '1 Rue de la Paix', 'city' => 'Lyon'], $outcome->values['address']);
    }

    public function testValuesOfEveryKindAreAcceptedTheWayABrowserPostsThem(): void
    {
        $outcome = $this->draft([
            'title' => 'Team offsite', 'guests' => '4', 'budget' => '1250.5', 'newsletter' => '1',
            'room' => 'large', 'extras' => ['projector'], 'level' => 'advanced', 'address' => ['city' => 'Lyon'],
        ]);

        self::assertTrue($outcome->valid);
        self::assertSame(4, $outcome->values['guests']);
        self::assertTrue($outcome->values['newsletter']);
    }

    public function testAnUncheckedBoxAndAnEmptyOptionalFieldAreFine(): void
    {
        $outcome = $this->draft(['newsletter' => false, 'summary' => ''] + self::VALID);

        self::assertTrue($outcome->valid);
        self::assertFalse($outcome->values['newsletter']);
        self::assertNull($outcome->values['summary']);
    }

    public function testEachWrongValueIsReportedOnItsOwnField(): void
    {
        $outcome = $this->draft([
            'title' => 'Hi',
            'contact' => 'not-an-email',
            'guests' => 40,
            'budget' => 'a lot',
            'room' => 'rooftop',
            'extras' => ['projector', 'fireworks'],
            'level' => 'expert',
            'day' => 'next Tuesday',
            'address' => ['street' => 'Somewhere'],
        ]);

        $fields = array_column($outcome->errors, 'field');
        self::assertFalse($outcome->valid);
        self::assertEqualsCanonicalizing(
            ['title', 'contact', 'guests', 'budget', 'room', 'extras', 'level', 'day', 'address.city'],
            $fields,
        );
        $errors = array_column($outcome->errors, null, 'field');
        self::assertSame('Which city?', $errors['address.city']['message']);
        self::assertSame(['small', 'large'], array_column($errors['room']['choices'], 'value'));
    }

    public function testAValidFormIsHandedToTheHandlerAsTheFormsData(): void
    {
        $outcome = $this->runner()->submit('plan_event', ['newsletter' => true] + self::VALID);

        self::assertTrue($outcome->saved);
        self::assertSame('Saved.', $outcome->result);
        self::assertSame('Team offsite', $this->handled[0]['title']);
        self::assertSame(Level::Advanced, $this->handled[0]['level']);
        self::assertSame(['street' => null, 'city' => 'Lyon'], $this->handled[0]['address']);
    }

    public function testAHandlersRefusalAboutANestedFieldLandsOnThatField(): void
    {
        $refuse = static function (mixed $data): never {
            throw new ValidationFailedException($data, new ConstraintViolationList([new ConstraintViolation('We do not host events in this city yet.', null, [], $data, 'address.city', 'Lyon')]));
        };

        $outcome = $this->runner($refuse)->submit('plan_event', self::VALID);

        self::assertSame('address.city', $outcome->errors[0]['field']);
    }

    public function testAHandlerThatRefusesAfterAllShowsItsMessageOnTheForm(): void
    {
        $refuse = static function (mixed $data): never {
            throw new ValidationFailedException($data, new ConstraintViolationList([new ConstraintViolation('The large hall was taken a moment ago.', null, [], $data, 'room', 'large')]));
        };

        $outcome = $this->runner($refuse)->submit('plan_event', self::VALID);

        self::assertFalse($outcome->saved);
        self::assertFalse($outcome->valid);
        self::assertSame('room', $outcome->errors[0]['field']);
        self::assertSame('The large hall was taken a moment ago.', $outcome->errors[0]['message']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function draft(array $values): FormOutcome
    {
        return $this->runner()->draft('plan_event', $values);
    }

    private function runner(?\Closure $handler = null): FormToolRunner
    {
        $handler ??= new class($this->handled) {
            /** @param list<mixed> $handled */
            public function __construct(private array &$handled)
            {
            }

            public function __invoke(mixed $data): void
            {
                $this->handled[] = $data;
            }
        };
        $tools = new FormToolRegistry(
            ['plan_event' => ['form' => EveryFieldType::class, 'description' => 'Plan an event.', 'title' => null, 'submit_label' => 'Save', 'method' => '__invoke']],
            new ServiceLocator(['plan_event' => static fn () => $handler]),
        );

        return new FormToolRunner($this->forms, $this->registry, $tools);
    }
}
