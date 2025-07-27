# Command parsing library for PocketMine-MP
Commander uses reflection to allow commands to be written more declaritively.
## Example
```php
class Main extends PluginBase
{
    public function onEnable(): void
    {
        $commander = new Commander("myfallbackprefix", $this);

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
            "myplugin.command.mygivecommand",
        ];
    }

    // Command parameters are parsed depending on their types, in the order you define them.
    // In this case, the usage message, which will be generated automatically will be:
    // /mygivecommand <player: Player> <item: Item> [count: int]
    public function execute(Player $player, Item $item, int $count = 1): void {
        $i = $item->setCount($count);
        $player->getInventory()->addItem($i);
    }

    public static function getName(): string
    {
        return "mygivecommand";
    }
}
```
As explained in the comment above, parsed depending on their types, in the order you define them. As a bonus, this enables us to add auto-completion, like a vanilla command! Players will be able to tab-complete player and item names as they would with vanilla commands
## Documentation
### TrailingString
The `TrailingString` class should be used as a parameter type when you want to allow spaces in a string - for example in the /say command. Since it allows spaces, it must be the last parameter, otherwise parameters after it will not be usable.
### Subcommand
Subcommands are supported but I haven't really finalized the way they're going to work. There's one in the Main.php file - I will write some documentation for them once I've finished with them.