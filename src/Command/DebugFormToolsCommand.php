<?php

declare(strict_types=1);

namespace FormBridge\Command;

use FormBridge\Exception\UnsupportedFieldException;
use FormBridge\FormTool;
use FormBridge\FormToolRegistry;
use FormBridge\FormToolSchemas;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'debug:form-tools', description: 'List the forms exposed as tools, or show what an agent is told about one')]
final class DebugFormToolsCommand extends Command
{
    public function __construct(
        private readonly FormToolRegistry $tools,
        private readonly FormToolSchemas $schemas,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'A tool name, to show its description and input schema');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        if (null === $name) {
            $io->table(['Tool', 'Submit tool', 'Form', 'Title', 'Button'], array_map(
                static fn (FormTool $tool): array => [$tool->name, $tool->submitToolName(), $tool->form, $tool->title, $tool->submitLabel],
                $this->tools->all(),
            ));

            return Command::SUCCESS;
        }

        if (!$this->tools->has($name)) {
            $io->error(\sprintf('No form tool is named "%s".', $name));

            return Command::FAILURE;
        }

        $tool = $this->tools->get($name);
        try {
            $schema = $this->schemas->of($tool);
        } catch (UnsupportedFieldException $unsupported) {
            $io->error($unsupported->getMessage());

            return Command::FAILURE;
        }

        $io->title($tool->name);
        $io->text($tool->agentDescription());
        $io->section('Input schema');
        $output->writeln(json_encode($schema, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
