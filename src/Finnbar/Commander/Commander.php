<?php

namespace Finnbar\Commander;

use Exception;
use Finnbar\Commander\arg\Subcommand;
use pocketmine\network\mcpe\protocol\types\command\CommandData;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandParameterTypes;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionType;
use Finnbar\Commander\arg\TrailingString;
use Finnbar\Commander\error\ArgumentParsingError;
use Finnbar\Commander\listener\AvailableCommandsListener;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\network\mcpe\protocol\types\command\CommandEnum;

class Commander
{

    /** @var array<string, class-string<CommanderCommand>> */
    private static array $commands = [];

    public function __construct(private string $fallbackPrefix, Plugin $plugin)
    {
        Server::getInstance()->getPluginManager()->registerEvents(new AvailableCommandsListener, $plugin);

        Commander::registerTypeParser("string", function (CommandContext $context, int &$arg, array $args) {
            return $args[$arg++];
        }, fn($name, $isOptional) => CommandParameter::standard($name, CommandParameterTypes::ID, 0, $isOptional));

        Commander::registerTypeParser(TrailingString::class, function (CommandContext $context, int &$arg, array $args) {
            $arg += count(array_slice($args, $arg));
            return new TrailingString(implode(" ", array_slice($args, $arg)));
        }, fn($name, $isOptional) => CommandParameter::standard($name, CommandParameterTypes::MESSAGE_ROOT, 0, $isOptional));

        Commander::registerTypeParser("int", function (CommandContext $context, int &$arg, array $args) {
            $value = $args[$arg++];
            if (!is_numeric($value) || (string)(int)$value !== trim((string)$value)) {
                throw new ArgumentParsingError("Expected an integer, got " . $value);
            }
            return (int) $value;
        }, fn($name, $isOptional) => CommandParameter::standard($name, CommandParameterTypes::INT, 0, $isOptional));

        Commander::registerTypeParser("bool", function (CommandContext $context, int &$arg, array $args) {
            $val = strtolower($args[$arg++]);
            if ($val === "true") return true;
            if ($val === "false") return false;
            throw new ArgumentParsingError("Expected a boolean, got $val");
        }, fn($name, $isOptional) => CommandParameter::enum($name, new CommandEnum("bool", ["true", "false"], false), 0, $isOptional));

        Commander::registerTypeParser(Player::class, function (CommandContext $context, int &$arg, array $args) {
            $p = Server::getInstance()->getPlayerByPrefix($args[$arg++]);
            if ($p !== null) {
                return $p;
            }
            throw new ArgumentParsingError("Player not found: " . $args[$arg - 1]);
        }, fn($name, $isOptional) => CommandParameter::standard($name, CommandParameterTypes::SELECTION, 0, $isOptional));

        $itemEnum = new CommandEnum("itemName", StringToItemParser::getInstance()->getKnownAliases(), false);

        Commander::registerTypeParser(Item::class, function (CommandContext $context, int &$arg, array $args) {

            $item = StringToItemParser::getInstance()->parse($args[$arg++]);
            if ($item !== null) {
                return $item;
            }
            throw new ArgumentParsingError("Item not found: " . $args[$arg - 1]);
        }, fn($name, $isOptional) => CommandParameter::enum($name, $itemEnum, 0, $isOptional));
    }

    /** @var array<string, callable(CommandContext $context, int &$arg, string[] $args)> */
    private static array $typeParsers = [];

    /** @var array<string, callable(string $name, bool $isOptional): CommandParameter> */
    private static array $phpTypeToCommandArgument = [];

    /** @param callable(CommandContext $context, int &$arg, string[] $args) $parser 
     * @param callable(string $name, bool $isOptional): CommandParameter $createCommandParameter
     */
    public static function registerTypeParser(string $type, callable $parser, ?callable $createCommandParameter = null): void
    {
        if (isset(self::$typeParsers[$type])) {
            throw new Exception("Type parser already registered for type $type");
        }

        if ($createCommandParameter !== null) {
            self::$phpTypeToCommandArgument[$type] = $createCommandParameter;
        }

        self::$typeParsers[$type] = $parser;
    }

    public static function parseArgument(CommandContext $context, string $type, int &$arg, array $args): mixed
    {
        if (isset(self::$typeParsers[$type])) {
            return self::$typeParsers[$type]($context, $arg, $args);
        } else {
            throw new Exception("No type parser registered for type $type");
        }
    }

    /** @param class-string<CommanderCommand> $command */
    public function registerCommand(string $command): void
    {
        if (method_exists($command, "getAliases")) {
            $aliases = $command::{"getAliases"}();
            foreach ($aliases as $alias) {
                self::$commands[$alias] = $command;
            }
        } else {
            $aliases = [];
        }
        self::$commands[$command::getName()] = $command;
        Server::getInstance()->getCommandMap()->register($this->fallbackPrefix, new CommanderPMCommand($command, $aliases));
    }

    /** @return ?class-string<CommanderCommand> */
    public static function getCommand(string $name): ?string
    {
        return self::$commands[$name] ?? null;
    }

    /** @param class-string<CommanderCommand> $command */
    public static function addOverloads(string $command, Player $recipient, CommandData $commandData, array $doneSubcommands = []): void
    {
        $obj = new ReflectionClass($command);
        $params = $obj->getMethod("execute")->getParameters();

        $subcommandParam = null;

        $overloadParams = [];

        foreach ($params as $param) {
            if ($param->getType()->getName() === Subcommand::class) {
                if ($subcommandParam !== null) {
                    throw new Exception("Subcommand must be the last argument");
                }
                $subcommandParam = $param;
            } else {
                if (isset(self::$phpTypeToCommandArgument[$param->getType()->getName()])) {
                    $overloadParams[] = self::$phpTypeToCommandArgument[$param->getType()->getName()]($param->getName(), $param->isDefaultValueAvailable());
                } else {
                    $overloadParams[] = CommandParameter::standard($param->getName(), CommandParameterTypes::ID, $param->isDefaultValueAvailable());
                }
            }
        }

        $n = 0;

        if ($subcommandParam !== null) {
            $commandData->overloads = [];
            foreach ($command::{"getSubcommands"}() as $subcommand) {

                if (in_array($subcommand, $doneSubcommands)) {
                    var_dump("STOP HERE!");
                    continue;
                }

                /** @var class-string<CommanderCommand> $subcommand */
                $canUse = false;
                foreach ($subcommand::getPermissions() as $permission) {
                    if ($recipient->hasPermission($permission)) {
                        $canUse = true;
                        break;
                    }
                }
                if (!$canUse) {
                    var_dump("STOP THERE!");
                    continue;
                }
                $cmdData = new CommandData($subcommand::getName(), $subcommand::getDescription(), $commandData->flags, $commandData->permission, null, [], []);
                self::addOverloads($subcommand, $recipient, $cmdData, [...$doneSubcommands, $command]);
                var_dump($cmdData->overloads);
                foreach ($cmdData->overloads as $subOverload) {
                    var_dump("SUB OVERLOAD!");
                    $commandData->overloads[] = new CommandOverload(false, [
                        ...$overloadParams,
                        CommandParameter::enum(
                            $command::getName() . "_subcommand_" . $n++,
                            new CommandEnum(
                                $command::getName() . "_subcommand_" . $n,
                                [$subcommand::getName()],
                                false,
                            ),
                            0,
                            $subcommandParam->isOptional(),
                        ),
                        ...$subOverload->getParameters(),
                    ]);
                }
            }
        } else {
            $commandData->overloads = [new CommandOverload(false, $overloadParams)];
        }
    }

    /** @param class-string<CommanderCommand> $command */
    public static function genUsage(string $command, string $name): string
    {
        $usage = "/" . $name;

        $usage .= " " . self::genParams($command);

        return $usage;
    }

    /** @param class-string<CommanderCommand> $command */
    private static function genParams(string $command, $doneSubcommands = [], int $indentation = 0): string
    {
        $obj = new ReflectionClass($command);
        $params = $obj->getMethod("execute")->getParameters();

        $usage = "";

        foreach ($params as $param) {
            try {
                if ($param->getType()->getName() === Subcommand::class) {
                    /** @var class-string<CommanderCommand>[] */
                    $subcommands = $command::{"getSubcommands"}();
                    // if ($indentation > 0) {
                    //     $usage .= TextFormat::RESET . str_repeat(" ", $indentation);
                    // }
                    $usage .= "{subcommand}\n";

                    $allUnavailableSubcommands = true;
                    foreach ($subcommands as $subcommand) {
                        if (!in_array($subcommand, $doneSubcommands)) {
                            $allUnavailableSubcommands = false;
                        }
                    }

                    if ($allUnavailableSubcommands) {
                        continue;
                    }
                    if ($indentation > 0) {
                        $usage .= TextFormat::RESET . str_repeat(" ", $indentation);
                    }
                    $usage .= "Available subcommands: \n";

                    foreach ($subcommands as $subcommand) {
                        if (in_array($subcommand, $doneSubcommands)) {
                            continue;
                        }
                        $doneSubcommands[] = $subcommand;
                        $indentation += 2;
                        $usage .= TextFormat::RED . str_repeat(" ", $indentation) . $subcommand::getName() . " " . Commander::genParams($subcommand, $doneSubcommands, $indentation);
                        $indentation -= 2;
                        $usage .=  TextFormat::DARK_GRAY . str_repeat(" ", $indentation) . $command::getDescription() . "\n";
                    }
                    continue;
                }
            } catch (ReflectionException $e) {
                $usage .= "Failed to get subcommands.";
            }


            $typeName = self::getUnqualifiedNameFromReflectionType($param->getType());
            if ($param->isDefaultValueAvailable()) {
                $usage .= "[{$param->getName()}: {$typeName}] ";
            } else {
                $usage .= "<{$param->getName()}: {$typeName}> ";
            }
        }
        return $usage;
    }

    public static function getUnqualifiedNameFromReflectionType(ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $fqcn = $type->getName();
            return substr(strrchr($fqcn, '\\'), 1) ?: $fqcn;
        }
        return $type->__toString();
    }
}
