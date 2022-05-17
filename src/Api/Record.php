<?php

namespace Oilstone\ApiTypesenseIntegration\Api;

use Aggregate\Map;
use Api\Result\Contracts\Record as Contract;

class Record extends Map implements Contract
{
    /**
     * @param array $item
     * @return static
     */
    public static function make(array $item): static
    {
        return (new static)->fill($item);
    }

    /**
     * @return iterable
     */
    public function getRelations(): iterable
    {
        return [];
    }

    /**
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->all();
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getAttribute(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
