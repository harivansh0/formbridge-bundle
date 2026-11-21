# FormBridge bundle documentation

## Installation

```bash
composer config repositories.formbridge vcs https://github.com/harivansh0/formbridge-bundle
composer require formbridge/formbridge-bundle:dev-main
```

The first line is only needed while the package is not listed on Packagist. The application must
allow beta packages (`"minimum-stability": "beta"`, `"prefer-stable": true`), because the bundle
needs `symfony/mcp-bundle` 1.0 (beta) and `mcp/sdk` 0.9.

```php
// config/bundles.php
return [
    // ...
    FormBridge\FormBridgeBundle::class => ['all' => true],
];
```

```yaml
# config/routes/form_bridge.yaml
form_bridge:
    resource: '@FormBridgeBundle/config/routes.php'
```

The application also needs `framework.lock` and CSRF protection enabled.

## Declaring a form tool

Put `#[AsFormTool]` on the service that handles the form's data. The service must be
autoconfigured, as application services are by default.

```php
use FormBridge\Attribute\AsFormTool;

#[AsFormTool(
    name: 'create_booking',
    form: BookingType::class,
    description: 'Book seats for one person in a workshop session.',
    title: 'Book a workshop',
    submitLabel: 'Book',
)]
final class CreateBookingHandler
{
    public function __invoke(BookingRequest $request): Booking
    {
        // ...
    }
}
```

| Argument | Meaning |
|---|---|
| `name` | Tool name. `<name>_submit` is taken as well |
| `form` | The form type the handler works with |
| `description` | What the form does, written for the agent |
| `title` | Heading above the form. Defaults to a readable version of the name |
| `submitLabel` | Text of the button a person presses. Defaults to "Submit" |
| `method` | Handler method that receives the form's data. Defaults to `__invoke` |

The handler runs when a valid form was submitted. A string or `Stringable` return value is shown
to the person as the confirmation. To refuse a valid form after all, throw a
`ValidationFailedException` **before writing anything**: its messages are shown on the form, and
the form stays open and can be submitted again.

## Configuration

```yaml
# config/packages/form_bridge.yaml
form_bridge:
    agents: ['assistant']
    mcp_servers: ['default']
    review_page: true
    drafts:
        lifetime: 3600
        cache_pool: cache.app
    fill:
        platform: 'ai.platform.openai'
        model: 'gpt-5-mini'
        rate_limiter: assistant
```

| Key | Default | Meaning |
|---|---|---|
| `agents` | `[]` | Symfony AI agents (names under `ai.agent`) whose toolbox gets every form tool. Needs `symfony/ai-agent` |
| `mcp_servers` | `[]` | MCP servers (names under `mcp.servers`) that expose the form tools. Empty: every server |
| `review_page` | `true` | Whether a filled-in form is also offered as a link to a web page. Turn it off if all your clients show forms in the conversation |
| `drafts.lifetime` | `3600` | Seconds, counted from the moment a form was filled in, that it stays open for the person |
| `drafts.cache_pool` | own pool on `cache.app` | Service id of the PSR-6 cache pool that keeps filled-in forms |
| `fill.platform`, `fill.model` | off | Enables the "paste a message" page. Needs `symfony/ai-platform` and a model with structured output |
| `fill.rate_limiter` | none | A limiter under `framework.rate_limiter`, applied per client address. Needs `symfony/rate-limiter` |

## Routes

| Route | Path | Purpose |
|---|---|---|
| `form_bridge_review` | `/_formbridge/review/{token}` | The drafted form on a web page, for clients that cannot show forms |
| `form_bridge_fill` | `/_formbridge/fill/{tool}` | The "paste a message" page |
| `form_bridge_preview` | `/_formbridge/preview` | Development preview of a tool call. Debug mode only |

## How saving is guarded

- The tool a model calls fills the form in and validates it. It never runs the handler.
- A Symfony AI agent gets no other tool. Over MCP, each form has a second tool, `<name>_submit`,
  marked as visible to the form's MCP App only.
- The submit tool also needs a token that is placed inside the rendered form. The form travels in
  the tool result's `_meta`, which clients keep out of the model's context.
- A draft can be saved once. It is closed before the handler runs and reopened, with its original
  expiry, only if the form was not valid or the handler refused it.
- The review page needs a CSRF token issued when the page was loaded. That is not authentication:
  put the route behind your access control to decide who may save.

## Events and logging

| Event | When |
|---|---|
| `FormBridge\Event\FormDraftedEvent` | An agent, or the form filler, filled a form in. Nothing was saved |
| `FormBridge\Event\FormSubmitEvent` | A valid form is about to be handed to its handler. A listener may refuse it |
| `FormBridge\Event\FormSavedEvent` | A form submitted through FormBridge was saved. Carries the form data and the handler's result |

`FormSubmitEvent` is the place to decide who may save, whichever way the form was submitted:

```php
use FormBridge\Event\FormSubmitEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class OnlyStaffMaySave
{
    public function __construct(private readonly Security $security) {}

    #[AsEventListener]
    public function __invoke(FormSubmitEvent $event): void
    {
        if (!$this->security->isGranted('ROLE_STAFF')) {
            $event->refuse('Sign in as a member of staff to save this form.');
        }
    }
}
```

The handler does not run for a refused form, and the message is shown on the form.

Both steps are logged on the `formbridge` channel, without field values. The MCP SDK logs
complete tool arguments at debug level on its own `mcp` channel; keep that channel above debug.

## Inspecting

```bash
bin/console debug:form-tools                  # the exposed forms
bin/console debug:form-tools create_booking   # what an agent is told about one, with its input schema
```

## Extending

**Your own field types.** A field FormBridge does not know is described as text. To describe it
yourself, or to support a type FormBridge refuses, implement `FormBridge\Form\FieldDescriberInterface`
in an autoconfigured service; it is asked before the built-in descriptions:

```php
use FormBridge\Form\FieldDescriberInterface;
use FormBridge\Form\FormInspector;

final class ColorFieldDescriber implements FieldDescriberInterface
{
    public function describe(FormInterface $field, FormView $view): ?array
    {
        return FormInspector::isType($field, ColorType::class)
            ? ['type' => 'string', 'description' => 'A colour as #RRGGBB.']
            : null;
    }
}
```

**Your own constraints.** Implement `FormBridge\Form\ConstraintDescriberInterface` to put a rule into
words for the agent, for example `Regex` or a custom constraint. The form enforces the rule either
way; the description saves the agent a round of trial and error.

**Templates.** The form, the review page and the "paste a message" page are Twig templates and can
be overridden in `templates/bundles/FormBridgeBundle/`.

## Deployment

- **Authenticate the MCP endpoint** if it is reachable by anyone but you. Every call of a form
  tool opens a draft, and the form tools describe your data (the options of choice fields).
- **Decide who may save**: put the review route behind your access control or turn `review_page`
  off, and use `FormSubmitEvent` for rules per form.
- **On more than one server**, drafts and locks must be shared: point `drafts.cache_pool` at a
  shared pool (Redis, a database) and configure `framework.lock` with a shared store.
- **Drafts live in a cache.** Clearing that pool closes every open form; people then ask the
  assistant to fill the form in again.
- A form with a field that cannot be described is an error in debug mode. Outside debug mode it is
  logged as critical and left out, so that one form cannot take the other tools down with it.

## Services

Service ids start with `form_bridge.`. These classes can be autowired: `FormToolRunner`,
`FormToolRegistry`, `FormToolSchemas`, `DraftStore`, `Form\FormSchemaFactory`, `Agent\DraftLog`,
`Mcp\FormToolResponder` and, when `fill` is enabled, `Ai\FormFiller`.

## Supported fields

Text, textarea, email, URL, integer, number, checkbox, single and multiple choice, enums, Doctrine
entities, date, date and time, time (each as one text value in the standard HTML5 format), and
nested groups. Buttons are left out. File uploads, collections, dates or times split over several
controls, times without minutes, and dates in a custom text format raise an
`UnsupportedFieldException` rather than being described wrongly.

The schema fixes field names and types. Choices, required fields, ranges and lengths are written
into the field descriptions and left to the form, so that a wrong value still reaches the form and
is answered in the application's own words.

## Limitations

- The bundle's own texts (form headings, messages, the review page) are English only.
- Forms that change their fields while being submitted (form events) are not handled specially.
- Labels given as translatable objects are not carried into the schema.
- Only rules of the default validation group are described, and only these constraints: `NotBlank`,
  `NotNull`, `Range`, `Positive`, `PositiveOrZero`, `Length` and `Email`.
- A choice field lists all its options in the tool description.
- An empty nested group sent as `{}` is rejected by the MCP SDK before the form runs.
- Number fields that use digit grouping in a locale where "." is the grouping sign are not supported.
- A handler's refusal is put on the field with the same name as its path; forms that rename fields
  with `property_path` get it on the form as a whole.
- A clash between a form tool's name and a `#[McpTool]` service fails at compile time; tools
  registered in other ways are not checked.
- The bundle is tested through the application in this repository, on PHP 8.4 and Symfony 8.1.
