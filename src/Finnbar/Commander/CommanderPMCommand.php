<?php

namespace Finnbar\Commander;

use Exception;
use Finnbar\Commander\arg\Subcommand;
use Finnbar\Commander\Error\ArgumentParsingError;
use Finnbar\Commander\ui\CommanderUI;
use Finnbar\Commander\ui\UICommand;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;

class CommanderPMCommand extends Command
{
    /** @param class-string<CommanderCommand> $command */
    public function __construct(private string $command, array $aliases)
    {
        parent::__construct($command::getName(), $command::getDescription(), Commander::genUsage($command, $command::getName()), $aliases);
        $this->setPermissions($command::getPermissions());
    }

    public static function executeWithArgs(CommanderCommand $command, CommandContext $ctx, array $args, ?string $parentCommand = null)
    {
        $sender = $ctx->sender;
        $obj = new ReflectionClass($command);
        $params = $obj->getMethod("execute")->getParameters();

        if ($parentCommand === null) {
            $parentCommand = $command::class;
        }

        if (
            $ctx->sender instanceof Player &&
            $command instanceof UICommand &&
            count($args) === 0 &&
            0 !== count(array_filter($params, fn($p) => !$p->isOptional()),)
        ) {
            CommanderUI::open($command, $ctx);
            return;
        }

        $parsedArgs = [];

        $curArg = 0;

        $foundOptional = false;

        // throw an exception if there is an argument with a default value before an argument with no default value
        foreach ($params as $param) {
            if ($param->isDefaultValueAvailable()) {
                $foundOptional = true;
            } else {
                if ($foundOptional) {
                    throw new Exception("Argument with no default value must come before an argument with a default value. Argument: {$param->getName()} of {$command::getName()}");
                }
            }
        }

        foreach ($params as $param) {
            $type = $param->getType();

            if ($param->getPosition() === 0 && $type instanceof ReflectionNamedType && $type->getName() === CommandSender::class) {
                $parsedArgs[] = $sender;
                continue;
            }

            if ($type instanceof ReflectionIntersectionType || $type instanceof ReflectionUnionType) {
                throw new Exception("Parameter is not a concrete type. Intersections and unions are unsupported. Parameter: {$param->getName()} of {$command::getName()}");
            }
            if ($type === null) {
                throw new Exception("Parameters not typed. Parameter: {$param->getName()} of {$command::getName()}");
            }

            if ($curArg >= count($args)) {
                if ($param->isDefaultValueAvailable()) {
                    $parsedArgs[] = $param->getDefaultValue();
                    continue;
                } else {
                    $sender->sendMessage(TextFormat::RED . "Missing argument: {$param->getName()}\n" . Commander::genUsage($parentCommand, $ctx->label));
                    return;
                }
            }

            if ($type->getName() === Subcommand::class) {
                $found = false;
                foreach ($command::{"getSubcommands"}() as $subcommand) {
                    /** @var class-string<CommanderCommand> $subcommand */
                    if ($subcommand::getName() === $args[$curArg]) {
                        $parsedArgs[] = new Subcommand($subcommand, array_slice($args, $curArg + 1), $parentCommand);
                        $curArg++;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $sender->sendMessage(TextFormat::RED . "Unknown subcommand: {$args[$curArg]}\n" . Commander::genUsage($parentCommand, $ctx->label));
                    return;
                }
                break;
            }

            try {
                $a = Commander::parseArgument($ctx, $type->getName(), $curArg, $args);
                $parsedArgs[] = $a;
            } catch (ArgumentParsingError $e) {
                $sender->sendMessage(TextFormat::RED . $e->getMessage() . "\n" . Commander::genUsage($parentCommand, $ctx->label)());
                return;
            }
        }

        $command->{"execute"}(...$parsedArgs);
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args)
    {
        $ctx = new CommandContext($sender, $commandLabel);
        self::executeWithArgs(new $this->command($ctx), $ctx, $args);
    }

    public function getPermissions(): array
    {
        return $this->command::getPermissions();
    }
}
