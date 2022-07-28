<?php

namespace Oilstone\ApiTypesenseIntegration\Models;

use Api\Schema\Property;
use Api\Schema\Schema;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;

class SearchModel extends EloquentModel
{
    use Searchable;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var Schema|null
     */
    protected ?Schema $schema;

    /**
     * Make all attributes mass assignable
     *
     * @var string[]|bool
     */
    protected $guarded = false;

    /**
     * @var array
     */
    protected array $queryBy = [];

    /**
     * @var string
     */
    public string $additionalIndexKey = 'extraIndex';

    /**
     * @param string $type
     * @param array $attributes
     * @param Schema|null $schema
     * @return static
     */
    public static function make(string $type, array $attributes = [], ?Schema $schema = null, array $queryBy = []): static
    {
        return (new static($attributes))
            ->setSchema($schema)
            ->setTable($type)
            ->setQueryBy($queryBy);
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        if (!$this->schema) {
            return [];
        }

        $attributes = [];
        $values = $this->getAttributes();

        foreach ($this->getIndexFields(false, true) as $field) {
            $value = Arr::get($values, $field['name']);

            if (isset($value) || !$field['optional']) {
                switch ($field['type']) {
                    case 'integer':
                        $value = intval($value ?: 0);
                        break;

                    case 'float':
                    case 'decimal':
                        $value = floatval($value ?: 0.0);
                        break;

                    case 'boolean':
                        $value = boolval($value ?: false);
                        break;

                    case 'string[]':
                        $value = is_array($value) ? $value : [];
                        break;

                    case 'timestamp':
                    case 'date':
                    case 'datetime':
                        if (!$value && ($field['property'] ?? null)?->hasMeta('nullDate')) {
                            $value = $field['property']->nullDate;
                        }

                        $value = $value ? Carbon::parse($value)->unix() : 0;
                        break;

                    default:
                        $value = $value ?: '';
                }
            }

            $attributes[$field['name']] = $value;
        }

        return $attributes;
    }

    /**
     * The Typesense schema to be created.
     *
     * @return array
     */
    public function getCollectionSchema(): array
    {
        return array_filter([
            'name' => $this->searchableAs(),
            'fields' => $this->getIndexFields(),
            'default_sorting_field' => $this->getSortingField(),
        ]);
    }

    /**
     * The fields to be queried against. See https://typesense.org/docs/0.21.0/api/documents.html#search.
     *
     * @return array
     */
    public function typesenseQueryBy(): array
    {
        return $this->queryBy ?: array_column(array_filter($this->getIndexFields(), fn (array $field) => $field['index'] && $field['type'] === 'string'), 'name') + [$this->additionalIndexKey];
    }

    /**
     * Get the value of schema
     *
     * @return Schema
     */
    public function getSchema(): ?Schema
    {
        return $this->schema;
    }

    /**
     * Set the value of schema
     *
     * @param Schema|null  $schema
     * @return static
     */
    public function setSchema(?Schema $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * @return array
     */
    public function getQueryBy(): array
    {
        return $this->queryBy;
    }

    /**
     * @param array $queryBy
     * @return static
     */
    public function setQueryBy(array $queryBy): static
    {
        $this->queryBy = $queryBy;

        return $this;
    }

    /**
     * @return array
     */
    protected function getIndexFields(bool $transformType = true, bool $includeProperty = false): array
    {
        if (!$this->schema) {
            return [];
        }

        return array_merge(array_map(function (Property $property) use ($transformType, $includeProperty) {
            $optional = $property->optional ?? false;
            $searchable = $property->searchable ?? false;
            $isDefaultSort = $property->defaultSort ?? false;

            if (!$searchable) {
                $optional = true;
            }

            if ($isDefaultSort) {
                $optional = false;
            }

            $field = [
                'name' => implode('.', array_filter([$property->prefix, $property->getName()])),
                'type' => $transformType ? $this->transformType($property) : ($property->searchType ?? $property->getType()),
                'facet' => $property->facet ?? false,
                'optional' => $optional,
                'index' => $searchable,
            ];

            if ($includeProperty) {
                $field['property'] = $property;
            }

            return $field;
        }, $this->getIndexProperties($this->schema)), [
            [
                'name' => $this->additionalIndexKey,
                'type' => 'string',
                'facet' => false,
                'optional' => true,
                'index' => true,
            ],
        ]);
    }

    /**
     * @return array
     */
    protected function getIndexProperties(Schema $schema, ?string $prefix = null): array
    {
        $properties = [];

        foreach ($schema->getProperties() as $property) {
            $property->meta('prefix', $prefix);

            if ($property->getAccepts()) {
                $properties = array_merge($properties, $this->getIndexProperties($property->getAccepts(), implode('.', array_filter([$property->prefix, $property->getName()])) ?: null));
                continue;
            }

            if ($property->indexed || $property->searchable) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /**
     * @return string|null
     */
    protected function getSortingField(): ?string
    {
        foreach ($this->schema?->getProperties() ?? [] as $property) {
            if ($property->defaultSort) {
                return $property->getName();
            }
        }

        return null;
    }

    /**
     * Get the index name for the model.
     *
     * @return string
     */
    public function searchableAs(): string
    {
        return Config::get('scout.prefix') . $this->getTable();
    }

    /**
     * @param Property $property
     * @return string
     */
    protected function transformType(Property $property): string
    {
        if ($property->hasMeta('searchType')) {
            return $property->searchType;
        }

        switch ($property->getType()) {
            case 'integer':
                return 'int32';

            case 'richtext':
                return 'string';

            case 'timestamp':
            case 'date':
            case 'datetime':
                return 'int64';

            case 'boolean':
                return 'bool';

            case 'decimal':
                return 'float';
        }

        return $property->getType();
    }

    /**
     * Perform a search against the model's indexed data.
     *
     * @param string $type
     * @param Schema|null $schema
     * @param mixed $query
     * @param mixed $callback
     * @return Builder
     */
    public static function search(string $type, ?Schema $schema = null, mixed $query = '', mixed $callback = null, array $queryBy = []): Builder
    {
        return App::make(Builder::class, [
            'model' => static::make($type, [], $schema, $queryBy),
            'query' => $query,
            'callback' => $callback,
            'softDelete' => static::usesSoftDelete() && Config::get('scout.soft_delete', false),
        ]);
    }
}
