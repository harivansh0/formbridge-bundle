<?php

declare(strict_types=1);

namespace FormBridge;

use Psr\Container\ContainerInterface;

final class FormToolRegistry
{
    /**
     * @param array<string, array{form: class-string, description: string, title: ?string, submit_label: string, method: string}> $definitions
     * @param ContainerInterface                                                                                                  $handlers    handler services keyed by tool name
     */
    public function __construct(
        private readonly array $definitions,
        private readonly ContainerInterface $handlers,
    ) {
    }

    /**
     * @return list<FormTool>
     */
    public function all(): array
    {
        return array_map($this->get(...), array_keys($this->definitions));
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function get(string $name): FormTool
    {
        $definition = $this->definitions[$name]
            ?? throw new \InvalidArgumentException(\sprintf('No form tool is named "%s".', $name));

        return new FormTool(
            $name,
            $definition['form'],
            $definition['description'],
            $definition['title'] ?? ucfirst(str_replace('_', ' ', $name)),
            $definition['submit_label'],
            $definition['method'],
        );
    }

    public function handle(FormTool $tool, mixed $data): mixed
    {
        return $this->handlers->get($tool->name)->{$tool->method}($data);
    }
}
