<?php

namespace Oilstone\ApiTypesenseIntegration;

use Api\Exceptions\InvalidQueryArgumentsException;
use Api\Exceptions\UnknownOperatorException;
use Api\Schema\Property;
use Api\Schema\Schema;
use Carbon\Carbon;
use Illuminate\Support\Arr;
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
    protected ?Schema $schema;

    /**
     * @param string|null $contentType
     * @param Delivery $client
     */
    public function __construct(string|null $contentType, ?Schema $schema = null, string $search = '*', array|string|null $queryBy = null)
    {
        $this->contentType = $contentType;
        $this->schema = $schema;
        $this->queryBuilder = SearchModel::search($contentType, $schema, $search, null, is_array($queryBy) ? $queryBy : explode(',', (string) $queryBy));
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
        $allowPinnedHits = method_exists($this->queryBuilder, 'pinnedHits');
        $allowHiddenHits = method_exists($this->queryBuilder, 'hiddenHits');

        if (count($arguments) === 3) {
            $operator = mb_strtolower($arguments[1]);
        }

        if (
            $this->schema &&
            is_string($field) &&
            $property = Arr::first($this->schema->getProperties(), fn (Property $property) => $property->getName() === $field || $property->alias === $field)
        ) {
            $field = $property;

            switch ($field->getType()) {
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

        $fieldName = is_string($field) ? $field : $field->getName();

        switch ($operator) {
            case '=':
            case 'has':
            case 'contains':
                if ($fieldName === 'id' && $allowPinnedHits) {
                    $this->queryBuilder->pinnedHits((array) $value);
                } else {
                    $this->queryBuilder->where($fieldName, $value);
                }
                break;

            case 'in':
                if ($fieldName === 'id' && $allowPinnedHits) {
                    $this->queryBuilder->pinnedHits((array) $value);
                } else {
                    $this->queryBuilder->whereIn($fieldName, $value);
                }
                break;

            case '!=':
            case 'has not':
                if ($fieldName === 'id' && $allowHiddenHits) {
                    $this->queryBuilder->hiddenHits((array) $value);
                } else {
                    $this->queryBuilder->where($fieldName, ['!=', $value]);
                }
                break;

            case '>':
            case '>=':
            case '<':
            case '<=':
                $this->queryBuilder->where($fieldName, [$operator, $value]);
                break;

            case 'near':
                $this->queryBuilder->where($fieldName, ['', str_ireplace(['mi', 'km', ','], [' mi', ' km', ', '], preg_replace('/\s+/', '', '(' . implode(',', $value) . ')'))]);
                break;

            // NOTE: `NOT IN` is not supported for any fields other than 'id' which is a Typesense reserved field
            case 'not in':
                if ($fieldName !== 'id' || !$allowHiddenHits) {
                    throw new UnknownOperatorException($operator);
                }

                $this->queryBuilder->hiddenHits((array) $value);
                break;

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
     * @param string $search
     * @return static
     */
    public function search(string $search): static
    {
        $this->queryBuilder->setQuery($search);

        return $this;
    }

    /**
     * @return ResultSet
     */
    public function get(): ResultSet
    {
        return ResultSet::make($this->queryBuilder->get()->toArray());
    }

    /**
     * @return ResultSet
     */
    public function page(int $page): ResultSet
    {
        $results = $this->queryBuilder->paginate($this->queryBuilder->limit, 'page', $page)->toArray();

        return ResultSet::make($results['data'], [
            'outOf' => $results['out_of'],
            'total' => $results['total'],
        ]);
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
