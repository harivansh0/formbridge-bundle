# FormBridge bundle

Symfony forms as tools for AI agents: as MCP tools, as forms a person completes inside the
conversation (MCP Apps), and as tools of Symfony AI agents. An agent fills a form in and gets the
form's own validation back; none of the tools an agent is given can save. Saving takes the form
shown to the person, or the review page.

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

## Requirements

PHP 8.2+, Symfony 7.3+ with Form, Validator, Twig, Lock and CSRF protection, `symfony/mcp-bundle`
1.0 (beta) and `mcp/sdk` 0.9. `symfony/ai-agent`, `symfony/ai-platform` and `symfony/rate-limiter`
are optional.

## Documentation

[docs/index.md](docs/index.md) covers installation, configuration, routes, how saving is guarded,
events, the supported field types and the limitations.

## License

MIT, see [LICENSE](LICENSE).
