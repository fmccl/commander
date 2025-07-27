<?php

namespace Finnbar\Commander;

interface CommanderCommand
{
    public static function getDescription(): string;

    /** @return string[] */
    public static function getPermissions(): array;

    public static function getName(): string;
}
