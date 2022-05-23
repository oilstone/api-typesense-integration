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
     * @var string
     */
    public string $additionalIndexKey = 'extraIndex';

    /**
     * @param string $type
     * @param array $attributes
     * @param Schema $schema
     * @return static
     */
    public static function make(string $type, array $attributes = [], Schema $schema = null): static
    {
        return (new static($attributes))
            ->setSchema($schema)
            ->setTable($type);
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

        foreach ($this->getIndexFields(false) as $field) {
            $value = Arr::get($values, $field['name']);

            if (isset($value) || !$field['optional']) {
                switch ($field['type']) {
                    case 'integer':
                        $value = intval($value ?: 0);
                        break;

                    case 'float':
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
                        $value = Carbon::parse($value ?: null)->unix();
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
        return array_column(array_filter($this->getIndexFields(), fn (array $field) => $field['index'] && $field['type'] === 'string'), 'name') + [$this->additionalIndexKey];
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
     * @param Schema  $schema
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
    protected function getIndexFields(bool $transformType = true): array
    {
        if (!$this->schema) {
            return [];
        }

        return array_merge(array_map(function (Property $property) use ($transformType) {
            $optional = $property->optional ?? false;
            $searchable = $property->searchable ?? false;
            $isDefaultSort = $property->defaultSort ?? false;

            if (!$searchable) {
                $optional = true;
            }

            if ($isDefaultSort) {
                $optional = false;
            }

            return [
                'name' => ($property->hasMeta('prefix') ? $property->prefix . '.' : '') . $property->getName(),
                'type' => $transformType ? $this->transformType($property) : ($property->searchType ?? $property->getType()),
                'facet' => $property->facet ?? false,
                'optional' => $optional,
                'index' => $searchable,
            ];
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
    protected function getIndexProperties(Schema $schema): array
    {
        $properties = [];

        foreach ($schema->getProperties() as $property) {
            if ($property->getAccepts()) {
                $nested = array_map(fn (Property $nestedProperty) => $nestedProperty->meta('prefix', $property->getName() . ($property->hasMeta('prefix') ? '.' . $property->prefix : '')), $this->getIndexProperties($property->getAccepts()));
                $properties = array_merge($properties, $nested);
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
        }

        return $property->getType();
    }

    /**
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @param  \Closure  $callback
     * @return \Laravel\Scout\Builder
     */
    public static function search(string $type, Schema $schema = null, $query = '', $callback = null)
    {
        return App::make(Builder::class, [
            'model' => static::make($type, [], $schema),
            'query' => $query,
            'callback' => $callback,
            'softDelete' => static::usesSoftDelete() && Config::get('scout.soft_delete', false),
        ]);
    }
}
