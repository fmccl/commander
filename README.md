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
### Automatic UI
Though this is primarily meant for internal tools e.g. config editing, there can be an automatically generated form UI. Just add `implements UICommand`. Then, when the command is used with no arguments, the user will be asked to provide them in a form. UICommand is just a marker interface, it doesn't have any functions that you need to implement.

TODO:
- Track if UI is being used in CommandContext
- Add displayText function that will send a message when used from command mode and send an empty MenuForm when used from UI mode
- Item picker using InvMenu
- Player picker MenuForm
- Escape = Go back
- Easy way to add menu reopening if the menu is closed
- Easy way to add menuforms for enums (soft and hard) and tbh enums need to be easier in commands too. I'll probably do this with parameter attributes.
### Subcommand
Subcommands are supported but I haven't really finalized the way they're going to work. There's one in the Main.php file - I will write some documentation for them once I've finished with them.