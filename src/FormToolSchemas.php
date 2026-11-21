<?php

declare(strict_types=1);

namespace FormBridge;

use FormBridge\Exception\UnsupportedFieldException;
use FormBridge\Form\FormSchemaFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The input schema of each form tool.
 */
final class FormToolSchemas
{
    public function __construct(
        private readonly FormToolRegistry $tools,
        private readonly FormToolRunner $runner,
        private readonly FormSchemaFactory $factory,
        private readonly bool $debug = true,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UnsupportedFieldException
     */
    public function of(FormTool $tool): array
    {
        return $this->factory->inputSchema($this->runner->createForm($tool));
    }

    /**
     * Every form tool that can be described, with its schema.
     *
     * A form with an unsupported field is an error in debug mode. Otherwise it is logged and left
     * out, so that one form cannot take the other tools down with it.
     *
     * @return list<array{FormTool, array<string, mixed>}>
     */
    public function all(): array
    {
        $described = [];
        foreach ($this->tools->all() as $tool) {
            try {
                $described[] = [$tool, $this->of($tool)];
            } catch (UnsupportedFieldException $unsupported) {
                if ($this->debug) {
                    throw $unsupported;
                }
                $this->logger->critical('Form tool "{tool}" is not exposed: {message}', ['tool' => $tool->name, 'message' => $unsupported->getMessage()]);
            }
        }

        return $described;
    }
}
