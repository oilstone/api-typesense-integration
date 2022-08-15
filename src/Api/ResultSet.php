<?php

namespace Oilstone\ApiTypesenseIntegration\Api;

use Aggregate\Set;
use Api\Result\Contracts\Collection;

class ResultSet extends Set implements Collection
{
    protected iterable $meta = [];

    /**
     * @param array $items
     * @return static
     */
    public static function make(array $items, array $meta = []): static
    {
        return (new static)->setMetaData($meta)->fill(array_map(fn ($item) => Record::make($item), $items));
    }

    /**
     * @param ResultSet $merge
     * @return static
     */
    public function append(ResultSet $merge): static
    {
        $this->items = array_merge($this->items, $merge->all());

        if (!$this->getMetaData()) {
            $this->setMetaData($merge->getMetaData());
        }

        return $this;
    }

    /**
     * @return iterable
     */
    public function getItems(): iterable
    {
        return $this->all();
    }

    /**
     * @param iterable $items
     * @return static
     */
    public function setItems(iterable $items): static
    {
        $this->items = $items;

        return $this;
    }

    /**
     * @return iterable
     */
    public function getMetaData(): iterable
    {
        return $this->meta;
    }

    /**
     * @return static
     */
    public function setMetaData(iterable $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * @return static
     */
    public function addMetaData(string $key, mixed $value): static
    {
        $this->meta[$key] = $value;

        return $this;
    }
}
