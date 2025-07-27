<?php

namespace Finnbar\Commander;

use pocketmine\command\CommandSender;

final class CommandContext
{
    public function __construct(
        public CommandSender $sender,
        public string $label,
    ) {}
}
