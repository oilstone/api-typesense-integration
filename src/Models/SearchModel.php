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

    protected ?Schema $schema;

    /**
     * Make all attributes mass assignable
     *
     * @var string[]|bool
     */
    protected $guarded = false;

    protected array $queryBy = [];

    public string $additionalIndexKey = 'extraIndex';

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
        if (! $this->schema) {
            return [];
        }

        $values = $this->getAttributes();
        $document = $this->buildSearchableData($this->schema, $values);

        $extra = Arr::get($values, $this->additionalIndexKey);

        if (isset($extra)) {
            $document[$this->additionalIndexKey] = is_string($extra) ? $extra : (string) $extra;
        }

        return $document;
    }

    /**
     * Recursively build the nested searchable document for a schema, applying
     * the same per-type value coercion as before at the leaves.
     */
    protected function buildSearchableData(Schema $schema, array $values): array
    {
        $data = [];

        foreach ($schema->getProperties() as $property) {
            $name = $property->getName();
            $accepts = $property->getAccepts();

            if ($accepts instanceof Schema) {
                // Skip containers with nothing indexable beneath them.
                if (! $this->hasIndexableLeaf($accepts)) {
                    continue;
                }

                $nested = Arr::get($values, $name);

                if ($this->isCollection($property)) {
                    if (! is_array($nested)) {
                        continue;
                    }

                    $data[$name] = array_values(array_map(
                        fn ($item) => $this->buildSearchableData($accepts, is_array($item) ? $item : []),
                        $nested
                    ));
                } else {
                    if (! is_array($nested)) {
                        continue;
                    }

                    $data[$name] = $this->buildSearchableData($accepts, $nested);
                }

                continue;
            }

            if (! ($property->indexed || $property->searchable)) {
                continue;
            }

            $optional = $this->isOptional($property);
            $value = Arr::get($values, $name);

            // Omit absent optional leaves rather than sending null. Typesense
            // v29+ rejects null in non-optional fields; omitting absent values
            // keeps upserts clean and avoids that validation edge case.
            if (! isset($value) && $optional) {
                continue;
            }

            $data[$name] = $this->coerceLeafValue($property, $value);
        }

        return $data;
    }

    /**
     * Coerce a leaf value to the type Typesense expects, keyed on the raw
     * (pre-transform) schema type, mirroring the previous behaviour.
     */
    protected function coerceLeafValue(Property $property, mixed $value): mixed
    {
        $rawType = $property->searchType ?? $property->getType();

        switch ($rawType) {
            case 'integer':
                return intval($value ?: 0);

            case 'float':
            case 'decimal':
                return floatval($value ?: 0.0);

            case 'boolean':
                return boolval($value ?: false);

            case 'string[]':
                return is_array($value) ? $value : [];

            case 'timestamp':
            case 'date':
            case 'datetime':
                if (! $value && $property->hasMeta('nullDate')) {
                    $value = $property->nullDate;
                }

                return $value ? Carbon::parse($value)->unix() : 0;

            default:
                return $value ?: '';
        }
    }

    /**
     * The Typesense schema to be created.
     */
    public function getCollectionSchema(): array
    {
        if (! $this->schema) {
            return array_filter([
                'name' => $this->searchableAs(),
                'fields' => [$this->additionalIndexField()],
                'enable_nested_fields' => true,
            ]);
        }

        $fields = $this->collectFields($this->schema);

        if (! $this->fieldsContain($fields, $this->additionalIndexKey)) {
            $fields[] = $this->additionalIndexField();
        }

        // Strip the internal helper keys before handing the schema to Typesense.
        $fields = array_map(fn (array $field) => Arr::only($field, [
            'name', 'type', 'facet', 'optional', 'index', 'sort',
        ]), $fields);

        return array_filter([
            'name' => $this->searchableAs(),
            'fields' => $fields,
            'default_sorting_field' => $this->getSortingField(),
            'enable_nested_fields' => true,
        ]);
    }

    /**
     * Recursively collect Typesense field descriptors for a schema.
     *
     * Nested objects are declared as `object` / `object[]` parents (so
     * enable_nested_fields can index their sub-fields) plus explicitly typed
     * leaf sub-fields addressed by dot notation. Leaves beneath an
     * array-of-objects ancestor are declared with array types (e.g. string[]).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function collectFields(Schema $schema, ?string $prefix = null, bool $inCollection = false): array
    {
        $fields = [];

        foreach ($schema->getProperties() as $property) {
            $name = implode('.', array_filter([$prefix, $property->getName()]));
            $accepts = $property->getAccepts();

            if ($accepts instanceof Schema) {
                $isCollection = $this->isCollection($property);

                $childFields = $this->collectFields($accepts, $name, $inCollection || $isCollection);

                // Only declare the container (and descend) if it actually has
                // indexable leaves; this preserves selective indexing.
                if (! $childFields) {
                    continue;
                }

                $fields[] = [
                    'name' => $name,
                    'type' => $isCollection ? 'object[]' : 'object',
                    'facet' => false,
                    'optional' => true,
                    'index' => true,
                    'sort' => false,
                    'priority' => $property->searchPriority ?? 1,
                    'object' => true,
                ];

                $fields = array_merge($fields, $childFields);

                continue;
            }

            if (! ($property->indexed || $property->searchable)) {
                continue;
            }

            $type = $this->transformType($property);

            if ($inCollection && ! str_ends_with($type, '[]')) {
                $type .= '[]';
            }

            $fields[] = [
                'name' => $name,
                'type' => $type,
                'facet' => $property->facet ?? false,
                'optional' => $prefix !== null ? true : $this->isOptional($property),
                'index' => $property->searchable ?? false,
                'sort' => $property->sortable ?? false,
                'priority' => $property->searchPriority ?? 1,
                'object' => false,
            ];
        }

        return $fields;
    }

    /**
     * The fields to be queried against.
     *
     * Auto-selects searchable string (and nested string[]) leaves, ordered by
     * search priority, plus the additional index field. An explicit queryBy
     * still short-circuits this.
     */
    public function typesenseQueryBy(bool $returnWeights = false): array
    {
        $this->queryBy = array_values(array_filter($this->queryBy));

        if ($this->queryBy) {
            if ($returnWeights) {
                return array_map(fn () => 1, $this->queryBy);
            }

            return $this->queryBy;
        }

        if (! $this->schema) {
            return [];
        }

        $columns = array_values(array_filter(
            $this->collectFields($this->schema),
            fn (array $field) => ! $field['object']
                && $field['index']
                && in_array($field['type'], ['string', 'string[]'], true)
        ));

        // Keep the denormalised extra-index field searchable.
        $columns[] = [
            'name' => $this->additionalIndexKey,
            'type' => 'string',
            'index' => true,
            'priority' => 1,
            'object' => false,
        ];

        usort($columns, fn ($a, $b) => ($b['priority'] ?? 1) <=> ($a['priority'] ?? 1));

        return array_column($columns, $returnWeights ? 'priority' : 'name');
    }

    /**
     * Get the value of schema
     */
    public function getSchema(): ?Schema
    {
        return $this->schema;
    }

    /**
     * Set the value of schema
     */
    public function setSchema(?Schema $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    public function getQueryBy(): array
    {
        return $this->queryBy;
    }

    public function setQueryBy(array $queryBy): static
    {
        $this->queryBy = $queryBy;

        return $this;
    }

    /**
     * Whether a property represents an array of objects (object[]) rather than
     * a single nested object (object).
     */
    protected function isCollection(Property $property): bool
    {
        return $property->getType() === 'collection';
    }

    /**
     * Resolve the optional flag for a leaf property, mirroring the original
     * rules: non-searchable fields are optional; default-sort fields are not.
     */
    protected function isOptional(Property $property): bool
    {
        $optional = $property->optional ?? false;

        if (! ($property->searchable ?? false)) {
            $optional = true;
        }

        if ($property->defaultSort ?? false) {
            $optional = false;
        }

        return $optional;
    }

    /**
     * Whether a (possibly deeply nested) schema has any indexable leaf.
     */
    protected function hasIndexableLeaf(Schema $schema): bool
    {
        foreach ($schema->getProperties() as $property) {
            $accepts = $property->getAccepts();

            if ($accepts instanceof Schema) {
                if ($this->hasIndexableLeaf($accepts)) {
                    return true;
                }

                continue;
            }

            if ($property->indexed || $property->searchable) {
                return true;
            }
        }

        return false;
    }

    /**
     * The descriptor for the additional (denormalised) index field.
     *
     * @return array<string, mixed>
     */
    protected function additionalIndexField(): array
    {
        return [
            'name' => $this->additionalIndexKey,
            'type' => 'string',
            'facet' => false,
            'optional' => true,
            'index' => true,
            'sort' => false,
            'priority' => 1,
            'object' => false,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    protected function fieldsContain(array $fields, string $name): bool
    {
        foreach ($fields as $field) {
            if (($field['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

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
     */
    public function searchableAs(): string
    {
        return Config::get('scout.prefix').$this->getTable();
    }

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
