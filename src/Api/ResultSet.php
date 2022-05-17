<?php

namespace Oilstone\ApiTypesenseIntegration\Api;

use Aggregate\Set;

class ResultSet extends Set
{
    /**
     * @param array $items
     * @return static
     */
    public static function make(array $items): static
    {
        return (new static)->fill(array_map(fn ($item) => Record::make($item), $items));
    }
}
