<?php

namespace Finnbar\Commander;

use Finnbar\Commander\arg\Subcommand;
use pocketmine\item\Item;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase
{
    public function onEnable(): void
    {
        $commander = new Commander("commander", $this);

        $commander->registerCommand(MyGiveCommand::class);
    }
}

class MyGiveCommand implements CommanderCommand
{
    public function __construct(private CommandContext $ctx) {}

    public static function getDescription(): string
    {
        return "This is a test command";
    }

    public static function getPermissions(): array
    {
        return [
            "commander",
        ];
    }

    public function execute(Item $a, Subcommand $b,): void
    {
        if ($b->name === "foo") {
            $b->execute($this->ctx, new AsSubcommand($this->ctx, $a));
        }
    }

    /** @return class-string<CommanderCommand>[] */
    public static function getSubcommands(): array
    {
        return [
            AsSubcommand::class,
        ];
    }

    public static function getName(): string
    {
        return "execute";
    }
}

class AsSubcommand implements CommanderCommand
{
    public function __construct(private CommandContext $ctx, Item $a) {}

    public static function getDescription(): string
    {
        return "This is a test command";
    }

    public static function getPermissions(): array
    {
        return [
            "commander",
        ];
    }

    public function execute(int $b): void {}


    public static function getName(): string
    {
        return "foo";
    }
}
