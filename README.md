# FormBridge bundle

FormBridge exposes Symfony forms as MCP tools, as forms a person completes inside the conversation
(MCP Apps), and as tools of Symfony AI agents. An agent fills a form in and gets the form's own
validation back; none of the tools an agent is given can save. Saving requires the form shown to the
person or the review page.

```php
use FormBridge\Attribute\AsFormTool;

#[AsFormTool(
    name: 'create_booking',
    form: BookingType::class,
    description: 'Book seats for one person in a workshop session.',
    submitLabel: 'Book',
)]
final class CreateBookingHandler
{
    public function __invoke(BookingRequest $request): Booking
    {
        // Runs when a valid form was submitted. To refuse after all, throw a
        // ValidationFailedException before writing anything.
    }
}
```

## How it works

![Sequence of one form tool call. 1: an agent or MCP client calls the tool with values. 2: FormToolRunner::draft() has the form type validate them, returns a FormOutcome and dispatches FormDraftedEvent; the handler is not called. 3: DraftStore::open() stores a draft with a review token, a submit token and an expiry. 4: the client gets content and structuredContent with the form's validation messages. 5: the rendered form travels in _meta and reaches a person as an MCP App at ui://formbridge-form, or behind the review link. 6: the person's submit calls the submit tool with the fields and the token; that tool is not offered to the model. 7: the token is checked and DraftStore::claim() locks and closes the draft. 8: FormToolRunner::submit() validates again, dispatches FormSubmitEvent and calls the handler, then dispatches FormSavedEvent. 9: the person gets saved: true and the handler's result](docs/images/formbridge-sequence.svg)

`Agent\FormToolbox` (Symfony AI agents) and `Ai\FormFiller` (free text) enter at step 2.
`Controller\ReviewController` takes steps 6 to 9 for clients that cannot show an MCP App.
[How saving is guarded](docs/index.md#how-saving-is-guarded) explains each step.

## Installation

```bash
composer require formbridge/formbridge-bundle
```

## Requirements

PHP 8.2+, Symfony 7.4+ with Form, Validator, Twig, Lock and CSRF protection, `symfony/mcp-bundle`
1.0 (beta) and `mcp/sdk` 0.9. `symfony/ai-agent`, `symfony/ai-platform` and `symfony/rate-limiter`
are optional.

## Documentation

[docs/index.md](docs/index.md) covers installation, configuration, routes, how saving is guarded,
events, extension points, translations, the supported field types and the limitations.

## Tests

```bash
composer update
vendor/bin/phpunit
```

The suite runs against a small application in `tests/App`, on PHP 8.2 with Symfony 7.4 and on
PHP 8.4 with Symfony 8.1.

## License

MIT, see [LICENSE](LICENSE).
