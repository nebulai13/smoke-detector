<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use InvalidArgumentException;
use Throwable;

/**
 * The command runner behind bin/console: finds the command by name, runs it and turns the outcome
 * into an exit code. Deliberately tiny: no options, no interaction.
 */
final readonly class Console
{
    /** @var array<string, Command> */
    private array $commands;

    public function __construct(Command ...$commands)
    {
        $byName = [];
        foreach ($commands as $command) {
            if (isset($byName[$command->name()])) {
                throw new InvalidArgumentException("There are two console commands named \"{$command->name()}\".");
            }
            $byName[$command->name()] = $command;
        }
        ksort($byName);

        $this->commands = $byName;
    }

    /**
     * @return list<Command> Every command, by name.
     */
    public function commands(): array
    {
        return array_values($this->commands);
    }

    /**
     * @param list<string> $arguments The command line without the script name: the command, then its arguments.
     *
     * @return int The exit code of the process.
     */
    public function run(array $arguments, Output $output): int
    {
        $name = $arguments[0] ?? null;
        if ($name === null) {
            $this->listCommands($output);

            return 0;
        }

        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            // A failure, so that a script with a mistyped command stops.
            $output->error("Unknown command \"{$name}\". Run php bin/console without a command for the list.");

            return 1;
        }

        try {
            return $command->run(array_slice($arguments, 1), $output);
        } catch (Throwable $failure) {
            // Class and message only. A stack trace would print the arguments of every call.
            $output->error($name . ' failed: ' . $failure::class . ': ' . $failure->getMessage());

            return 1;
        }
    }

    private function listCommands(Output $output): void
    {
        $output->line('Usage: php bin/console <command>');
        $output->line('');
        $output->line('Commands:');

        $width = max(array_map(strlen(...), array_keys($this->commands)) ?: [0]);
        foreach ($this->commands as $name => $command) {
            $output->line('  ' . str_pad($name, $width) . '  ' . $command->description());
        }
    }
}
