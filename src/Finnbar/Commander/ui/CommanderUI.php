<?php

namespace Finnbar\Commander\ui;

use Closure;
use dktapps\pmforms\CustomForm;
use dktapps\pmforms\CustomFormResponse;
use dktapps\pmforms\element\Input;
use dktapps\pmforms\MenuForm;
use dktapps\pmforms\MenuOption;
use Finnbar\Commander\arg\Subcommand;
use Finnbar\Commander\CommandContext;
use Finnbar\Commander\Commander;
use Finnbar\Commander\CommanderCommand;
use Finnbar\Commander\error\ArgumentParsingError;
use pocketmine\player\Player;
use Reflection;
use ReflectionClass;
use ReflectionParameter;

final class CommanderUI
{

    /** @var array<string, callable(Player $player, $defaultValue, callable(mixed $output): void):void $callback> */
    private static array $prompts = [];

    /** @param callable(Player $player, $defaultValue, callable(mixed $output): void):void $prompt */
    public static function registerPrompt(string $name, callable $prompt): void
    {
        if (isset(self::$prompts[$name])) {
            throw new \InvalidArgumentException("Prompt $name already registered");
        }
        self::$prompts[$name] = $prompt;
    }

    public static function open(CommanderCommand $cmd, CommandContext $ctx, array $predefinedArgs = [])
    {
        $class = new ReflectionClass($cmd);
        $params = $class->getMethod("execute")->getParameters();

        $args = [...$predefinedArgs];

        $params = array_slice($params, count($predefinedArgs));

        if (count($params) === 0) {
            $cmd->{"execute"}($args);
            return;
        }

        $first = array_shift($params);
        self::getAllParams($cmd::class, $args, $first, $params, $ctx, function (array $args) use ($cmd) {
            $cmd->{"execute"}(...$args);
        });
    }

    /** @param ReflectionParameter[] $params
     * @param Closure(array) $done
     * @param class-string<CommanderCommand> $cmd
     */
    private static function getAllParams(string $cmd, array $args, ReflectionParameter $param, array $params, CommandContext $ctx, Closure $done): void
    {
        $default = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
        if ($param->getType()->getName() === Subcommand::class) {
            $buttons = [];
            if ($param->isOptional()) {
                $buttons[] = new MenuOption("Done");
            }
            foreach ($cmd::{"getSubcommands"}() as $subcommand) {
                $buttons[] = new MenuOption($subcommand::getName());
            }
            if (!$ctx->sender instanceof Player) {
                throw new \InvalidArgumentException("Player required");
            }
            $ctx->sender->sendForm(
                new MenuForm($param->getName(), "", $buttons, function (Player $player, int $selected) use ($cmd, $args, $param, $done, $ctx): void {
                    if ($param->isOptional()) $selected -= 1;
                    if ($param->isOptional() && $selected === -1) {
                        $done($args);
                    } else {
                        $chosen = $cmd::{"getSubcommands"}()[$selected];
                        /** @var class-string<CommanderCommand> $chosen */
                        $newParams = (new ReflectionClass($chosen))->getMethod("execute")->getParameters();
                        $newArgs = [];
                        if (count($newParams) === 0) {
                            $args[] = new Subcommand($chosen::getName(), [], $cmd);
                            $done($args);
                        }
                        $first = array_shift($newParams);
                        self::getAllParams($chosen, $newArgs, $first, $newParams, $ctx, function (array $newArgs) use ($cmd, $done, $args, $chosen) {
                            $args[] = new Subcommand($chosen, $newArgs, $cmd);
                            $done($args);
                        });
                    }
                }),
            );
        } else
        if (isset(self::$prompts[$param->getType()->getName()])) {
            self::$prompts[$param->getType()->getName()]($ctx->sender, $default, function (mixed $output) use ($cmd, $args, $params, $done, $ctx) {
                $args[] = $output;
                if (count($params) === 0) {
                    $done($args);
                } else {
                    $next = array_shift($params);
                    self::getAllParams($cmd, $args, $next, $params, $ctx, $done);
                }
            });
        } else {
            if (!$ctx->sender instanceof Player) {
                throw new \InvalidArgumentException("Player required");
            }
            $ctx->sender->sendForm(
                new CustomForm(
                    str_replace("_", " ", $param->getName()),
                    [new Input("input", $param->getName() . "\nType: " . Commander::getUnqualifiedNameFromReflectionType($param->getType()) . "\n", $default ?? "", $default ?? "")],
                    function (Player $player, CustomFormResponse $res) use ($args, $param, $params, $done, $ctx, $cmd): void {
                        $a = 0;
                        try {
                            $args[] = Commander::parseArgument($ctx, $param->getType()->getName(), $a, [$res->getString("input")]);
                        } catch (ArgumentParsingError $err) {
                            $ctx->sender->sendMessage($err->getMessage());
                            return;
                        }
                        if (count($params) === 0) {
                            $done($args);
                        } else {
                            $next = array_shift($params);
                            self::getAllParams($cmd, $args, $next, $params, $ctx, $done);
                        }
                    }
                )
            );
        }
    }
}
