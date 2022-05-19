<?php

namespace Oilstone\ApiTypesenseIntegration;

use Aggregate\Set;
use Api\Exceptions\InvalidQueryArgumentsException;
use Api\Exceptions\UnknownOperatorException;
use Api\Schema\Schema;
use Carbon\Carbon;
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
     * @var Schema|null
     */
    protected Schema $schema;

    /**
     * @param string|null $contentType
     * @param Delivery $client
     */
    public function __construct(string|null $contentType, ?Schema $schema = null, string $search = '*')
    {
        $this->contentType = $contentType;
        $this->schema = $schema;
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
     * Not currently supported
     *
     * @param string $relation
     * @return static
     */
    public function with(string $relation): static
    {
        // $this->queryBuilder->with($relation);

        return $this;
    }

    /**
     * Not currently supported
     *
     * @param array|string $columns
     * @return static
     */
    public function select(array|string $columns): static
    {
        // $this->queryBuilder->select(...(is_array($columns) ? $columns : explode(',', $columns)));

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
        if (count($arguments) < 2 || count($arguments) > 3) {
            throw new InvalidQueryArgumentsException();
        }

        $field = $arguments[0];
        $value = $arguments[2] ?? $arguments[1];
        $operator = '=';

        if (count($arguments) === 3) {
            $operator = mb_strtolower($arguments[1]);
        }

        if ($this->schema) {
            switch ($this->schema->getProperty($field)?->getType()) {
                case 'date':
                case 'datetime':
                case 'timestamp':
                    $value = Carbon::parse($value)->unix();
                    break;

                case 'boolean':
                    $value = boolval($value ?: false) ? 'true' : 'false';
                    break;
            }
        }

        switch ($operator) {
            case '=':
            case 'has':
            case 'contains':
                $this->queryBuilder->where($field, $value);
                break;

            case 'in':
                $this->queryBuilder->whereIn($field, $value);
                break;

                // Not currently supported

                // case '!=':
                // case 'has not':
                //     $this->queryBuilder->whereNot($field, $value);
                //     break;

                // case 'not in':
                //     $this->queryBuilder->whereNotIn($field, $value);
                //     break;

                // case '>':
                // case '>=':
                // case '<':
                // case '<=':
                //     $this->queryBuilder->where($field, $operator, $value);
                //     break;

            default:
                throw new UnknownOperatorException($operator);
        }

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
     * Not currently supported
     *
     * @param [mixed] $arguments
     * @return static
     */
    public function offset(int $offset): static
    {
        // $this->queryBuilder->skip($offset);

        return $this;
    }

    /**
     * @param string $offset
     * @return static
     */
    public function search(string $search): static
    {
        $this->queryBuilder->setQuery($search);

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
