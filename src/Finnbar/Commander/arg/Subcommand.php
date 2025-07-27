<?php

namespace Finnbar\Commander\arg;

use Finnbar\Commander\CommandContext;
use Finnbar\Commander\CommanderCommand;
use Finnbar\Commander\CommanderPMCommand;

class Subcommand
{
    /** @param class-string<CommanderCommand> $parentCommand */
    public function __construct(public string $name, private array $args, private string $parentCommand) {}

    public function execute(CommandContext $ctx, CommanderCommand $cmd): void
    {
        CommanderPMCommand::executeWithArgs($cmd, $ctx, $this->args, $this->parentCommand);
    }
}
