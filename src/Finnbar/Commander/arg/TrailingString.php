<?php

namespace Finnbar\Commander\arg;

class TrailingString
{
    public function __toString()
    {
        return $this->value;
    }

    public function __construct(public string $value) {}
}
