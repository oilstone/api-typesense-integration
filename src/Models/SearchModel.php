<?php

namespace Oilstone\ApiTypesenseIntegration\Models;

use Api\Schema\Property;
use Api\Schema\Schema;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model as EloquentModel;
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
        $attributes = [];
        $indexProperties = collect($this->getIndexFields());

        foreach ($this->getAttributes() as $key => $value) {
            if ($property = $this->getIndexProperty($key)) {
                $indexProperty = $indexProperties->firstWhere('name', $property->getName());

                if (isset($value) || !$indexProperty['optional']) {
                    switch ($property->getType()) {
                        case 'integer':
                            $value = $value ?: 0;
                            break;

                        case 'boolean':
                            $value = boolval($value ?: false);
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

                $attributes[$key] = $value;
            }
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
        return array_column(array_filter($this->getIndexFields(), fn (array $field) => $field['index']), 'name');
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
    protected function getIndexFields(): array
    {
        return array_map(function (Property $property) {
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
                'name' => $property->getName(),
                'type' => $this->transformType($property),
                'facet' => $property->facet ?? false,
                'optional' => $optional,
                'index' => $searchable,
            ];
        }, $this->getIndexSchema());
    }

    /**
     * @return array
     */
    protected function getIndexSchema(): array
    {
        return array_values(array_filter($this->schema->getProperties(), fn (Property $property) => $property->indexed || $property->searchable));
    }

    /**
     * @param string $key
     * @return Property|null
     */
    protected function getIndexProperty(string $key): ?Property
    {
        $property = $this->schema->getProperty($key);

        if (!$property || (!$property->indexed && !$property->searchable)) {
            return null;
        }

        return $property;
    }

    /**
     * @return string|null
     */
    protected function getSortingField(): ?string
    {
        foreach ($this->schema->getProperties() as $property) {
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
            case 'richtext':
                return 'string';

            case 'timestamp':
            case 'date':
            case 'datetime':
                return 'int32';

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
