<?php

namespace Oilstone\ApiTypesenseIntegration;

use Laravel\Scout\Builder as ScoutBuilder;

class Builder extends ScoutBuilder
{
    /**
     * Create a new search builder instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $query
     * @param  \Closure|null  $callback
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct($model, $query, $callback = null, $softDelete = false)
    {
        $this->model = $model;
        $this->query = $query;
        $this->callback = $callback;

        if ($softDelete) {
            $this->wheres[] = ['__soft_deleted', 0];
        }
    }

    /**
     * Add a constraint to the search query.
     *
     * @param  string  $field
     * @param  mixed  $value
     * @return $this
     */
    public function where($field, $value)
    {
        $this->wheres[] = [$field, $value];

        return $this;
    }

    /**
     * Add a "where in" constraint to the search query.
     *
     * @param  string  $field
     * @param  array  $values
     * @return $this
     */
    public function whereIn($field, array $values)
    {
        $this->whereIns[] = [$field, $values];

        return $this;
    }

    /**
     * @param string $query
     * @return static
     */
    public function setQuery(string $query): static
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->engine()
            ->getTotalCount($this->engine()
                ->search($this));
    }

    /**
     * @param string $column
     * @param float $lat
     * @param float $lng
     * @param string $direction
     * @return static
     */
    public function orderByLocation(string $column, float $lat, float $lng, string $direction = 'asc'): static
    {
        $this->engine()
            ->orderByLocation($column, $lat, $lng, $direction);

        return $this;
    }

    /**
     * @param array|string $groupBy
     * @return static
     */
    public function groupBy(array|string $groupBy): static
    {
        $groupBy = is_array($groupBy) ? $groupBy : func_get_args();

        $this->engine()
            ->groupBy($groupBy);

        return $this;
    }

    /**
     * @param int $groupByLimit
     * @return static
     */
    public function groupByLimit(int $groupByLimit): static
    {
        $this->engine()
            ->groupByLimit($groupByLimit);

        return $this;
    }

    /**
     * @param string $startTag
     * @return static
     */
    public function setHighlightStartTag(string $startTag): static
    {
        $this->engine()
            ->setHighlightStartTag($startTag);

        return $this;
    }

    /**
     * @param string $endTag
     * @return static
     */
    public function setHighlightEndTag(string $endTag): static
    {
        $this->engine()
            ->setHighlightEndTag($endTag);

        return $this;
    }

    /**
     * @param int $limitHits
     * @return static
     */
    public function limitHits(int $limitHits): static
    {
        $this->engine()
            ->limitHits($limitHits);

        return $this;
    }

    /**
     * Include soft deleted records in the results.
     *
     * @return $this
     */
    public function withTrashed()
    {
        $this->wheres = array_values(array_filter($this->wheres, fn(array $where) => $where[0] !== '__soft_deleted'));

        return $this;
    }

    /**
     * Include only soft deleted records in the results.
     *
     * @return $this
     */
    public function onlyTrashed()
    {
        return tap($this->withTrashed(), function () {
            $this->wheres[] = ['__soft_deleted', 1];
        });
    }
}