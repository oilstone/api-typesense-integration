<?php

namespace Oilstone\ApiTypesenseIntegration;

use Aggregate\Set;
use Api\Schema\Schema;
use Laravel\Scout\Builder;
use Oilstone\ApiTypesenseIntegration\Api\Record;
use Oilstone\ApiTypesenseIntegration\Api\ResultSet;
use Oilstone\ApiTypesenseIntegration\Models\SearchModel;

class Query
{
    /**
     * @var string|null
     */
    protected string|null $contentType;

    /**
     * @var Builder
     */
    protected Builder $queryBuilder;

    /**
     * @param string|null $contentType
     * @param Delivery $client
     */
    public function __construct(string|null $contentType, ?Schema $schema = null, string $search = '*')
    {
        $this->contentType = $contentType;
        $this->queryBuilder = SearchModel::search($contentType, $schema, $search);
    }

    /**
     * @param string|null $contentType
     * @param Delivery $client
     * @return static
     */
    public static function make(string|null $contentType): static
    {
        return new static($contentType);
    }

    /**
     * @param string $relation
     * @return static
     */
    public function with(string $relation): static
    {
        $this->queryBuilder->with($relation);

        return $this;
    }

    /**
     * @param array|string $columns
     * @return static
     */
    public function select(array|string $columns): static
    {
        $this->queryBuilder->select(...(is_array($columns) ? $columns : explode(',', $columns)));

        return $this;
    }

    /**
     * @param string $column
     * @param string $direction
     * @return static
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->queryBuilder->orderBy($column, strtolower($direction));

        return $this;
    }

    /**
     * @param [string] ...$arguments
     * @return static
     */
    public function where(...$arguments): static
    {
        $this->queryBuilder->where(...$arguments);

        return $this;
    }

    /**
     * @param [mixed] $arguments
     * @return static
     */
    public function limit(int $limit): static
    {
        $this->queryBuilder->take($limit);

        return $this;
    }

    /**
     * @param [mixed] $arguments
     * @return static
     */
    public function offset(int $offset): static
    {
        $this->queryBuilder->skip($offset);

        return $this;
    }

    /**
     * @param string $offset
     * @return static
     */
    public function search(string $search): static
    {
        $this->queryBuilder->skip($search);

        return $this;
    }

    /**
     * @return Set
     */
    public function get(): Set
    {
        return $this->getResultSet();
    }

    /**
     * @return Set
     */
    public function getResultSet(): Set
    {
        return ResultSet::make($this->queryBuilder->get()->toArray());
    }

    /**
     * @return null|Record
     */
    public function first(): ?Record
    {
        $result = $this->limit(1)->get();

        return $result->count() ? $result[0] : null;
    }
}
