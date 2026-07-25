<?php

namespace LaravelJsonApi\OpenApiSpec\Descriptors\Schema;

use GoldSpecDigital\ObjectOrientedOAS\Objects\AnyOf;
use GoldSpecDigital\ObjectOrientedOAS\Objects\OneOf;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Parameter;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema as OASchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LaravelJsonApi\Contracts\Resources\JsonApiRelation;
use LaravelJsonApi\Contracts\Schema\Attribute as AttributeContract;
use LaravelJsonApi\Contracts\Schema\Field;
use LaravelJsonApi\Contracts\Schema\Filter;
use LaravelJsonApi\Contracts\Schema\PolymorphicRelation;
use LaravelJsonApi\Contracts\Schema\Relation as RelationContract;
use LaravelJsonApi\Contracts\Schema\Schema as JASchema;
use LaravelJsonApi\Contracts\Schema\Sortable;
use LaravelJsonApi\Core\Resources\JsonApiResource;
use LaravelJsonApi\Core\Resources\Relation;
use LaravelJsonApi\Core\Support\Str;
use LaravelJsonApi\Eloquent;
use LaravelJsonApi\Eloquent\Fields\ArrayHash;
use LaravelJsonApi\Eloquent\Fields\ArrayList;
use LaravelJsonApi\Eloquent\Fields\Attribute as EloquentAttribute;
use LaravelJsonApi\Eloquent\Fields\Boolean;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Map;
use LaravelJsonApi\Eloquent\Fields\Number;
use LaravelJsonApi\Eloquent\Pagination\CursorPagination;
use LaravelJsonApi\Eloquent\Pagination\MultiPagination;
use LaravelJsonApi\Eloquent\Pagination\PagePagination;
use LaravelJsonApi\NonEloquent\Fields\Attribute as NonEloquentAttribute;
use LaravelJsonApi\OpenApiSpec\Builders\Paths\Operation\SchemaBuilder;
use LaravelJsonApi\OpenApiSpec\Contracts\Descriptors\Schema\PaginationDescriptor;
use LaravelJsonApi\OpenApiSpec\Contracts\Descriptors\Schema\SortablesDescriptor;
use LaravelJsonApi\OpenApiSpec\Contracts\Descriptors\SchemaDescriptor;
use LaravelJsonApi\OpenApiSpec\Descriptors\Descriptor;
use LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Filters\WithDescription as FilterWithDescriptionDescriptor;
use LaravelJsonApi\OpenApiSpec\Eloquent\Fields\WithDescription as FieldWithDescription;
use LaravelJsonApi\OpenApiSpec\Filters\WithDescription as FilterWithDescription;
use LaravelJsonApi\OpenApiSpec\Helpers\SchemaFromExample;
use LaravelJsonApi\OpenApiSpec\ResourceContainer;
use LaravelJsonApi\OpenApiSpec\Route;

class Schema extends Descriptor implements PaginationDescriptor, SchemaDescriptor, SortablesDescriptor
{
    protected array $filterDescriptors = [
        Eloquent\Filters\WhereIdIn::class => Filters\WhereIdIn::class,
        Eloquent\Filters\WhereIn::class => Filters\WhereIn::class,
        Eloquent\Filters\Scope::class => Filters\Scope::class,
        Eloquent\Filters\WithTrashed::class => Filters\WithTrashed::class,
        Eloquent\Filters\Where::class => Filters\Where::class,
        Eloquent\Filters\WhereNull::class => Filters\WhereNull::class,
        Eloquent\Filters\Has::class => Filters\Has::class,
        Eloquent\Filters\WhereHas::class => Filters\WhereHas::class,
    ];

    /**
     * The page size applied when a client sends none.
     *
     * The paginator exposes this only to its own subclasses, so it is read
     * reflectively. Omitting it leaves clients to guess how many records an
     * unparameterised request returns.
     */
    private static function defaultPerPage(PagePagination $pagination): ?int
    {
        $property = new \ReflectionProperty($pagination, 'defaultPerPage');

        $value = $property->getValue($pagination);

        return is_int($value) ? $value : null;
    }

    /**
     * Which of a schema's attributes may be null.
     *
     * JSON:API schemas do not declare nullability, so it cannot be derived from the
     * field definitions. A schema may opt in by defining `attributeNullability()`
     * returning a `['fieldName' => bool]` map. This is duck-typed rather than an
     * interface so that adopting it never requires changing a schema's parentage.
     *
     * Nullability is deliberately not inferred from database columns: computed and
     * presenter-derived attributes have no column, and a column that merely permits
     * null is not evidence that the API ever returns it.
     *
     * @return array<string, bool>
     */
    private static function attributeNullability(?JASchema $schema): array
    {
        if ($schema === null || ! method_exists($schema, 'attributeNullability')) {
            return [];
        }

        return array_filter($schema->attributeNullability());
    }

    /**
     * Read one attribute value for use as an example, or null if unavailable.
     *
     * An accessor is arbitrary application code and may throw for reasons that have
     * nothing to do with documentation. One uncooperative accessor must cost its own
     * example only, never the whole generation run.
     */
    private static function sampleValue(JsonApiResource $example, string $column): mixed
    {
        try {
            return isset($example[$column]) ? $example[$column] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read the full attribute list for use as examples, or an empty list if unavailable.
     *
     * @return array<string, mixed>
     */
    private static function sampleAttributes(JsonApiResource $example): array
    {
        try {
            return collect($example->attributes(null))->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The `id` property, carrying a sampled example only when one is available.
     *
     * @param ?JsonApiResource $resource
     */
    protected function idProperty(?JsonApiResource $resource): OASchema
    {
        $id = OASchema::string('id');

        return $resource ? $id->example($resource->id()) : $id;
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function fetch(JASchema $schema, string $objectId, string $type, string $name): OASchema
    {
        $resource = $this->generator->resources()->resource($schema::model());

        $fields = $this->fields($schema->fields(), $resource, $schema);
        $properties = [
            OASchema::string('type')->title('type')->default($type),
            $this->idProperty($resource),
            OASchema::object('attributes')->properties(...$fields->get('attributes')),
        ];

        if ($fields->has('relationships')) {
            $properties[] = OASchema::object('relationships')->properties(...$fields->get('relationships'));
        }

        return OASchema::object($objectId)
            ->title('Resource/' . ucfirst($name) . '/Fetch')
            ->required('type', 'id', 'attributes')
            ->properties(...$properties);
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     * @return array['schema' => OASchema, 'included' => OASchema]
     */
    public function fetchWithIncluded(JASchema $schema, string $objectId, string $type, string $name): array
    {
        $includedItems = [];
        $resource = $this->generator->resources()->resource($schema::model());

        $fields = $this->fields($schema->fields(), $resource, $schema);
        $properties = [
            OASchema::string('type')->title('type')->default($type),
            $this->idProperty($resource),
            OASchema::object('attributes')->properties(...$fields->get('attributes')),
        ];

        if ($fields->has('relationships')) {
            $properties[] = OASchema::object('relationships')->properties(...$fields->get('relationships'));
            $includedItems = array_merge($includedItems, $this->included($schema->fields(), $resource, $type));
        }

        $oaSchema = OASchema::object($objectId)
            ->title('Resource/' . ucfirst($name) . '/Fetch')
            ->required('type', 'id', 'attributes')
            ->properties(...$properties);

        if (!empty($includedItems))
            return [
                'schema' => $oaSchema,
                'included' => OASchema::array('included')->items(AnyOf::create('')->schemas(...$includedItems)),
            ];

        return ['schema' => $oaSchema];
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function store(Route $route): OASchema
    {
        $objectId = SchemaBuilder::objectId($route);

        $resource = $this->generator->resources()->resource($route->schema()::model());

        $fields = $this->fields($route->schema()->fields(), $resource, $route->schema());

        return OASchema::object($objectId)
            ->title('Resource/' . ucfirst($route->name(true)) . '/Store')
            ->required('type', 'attributes')
            ->properties(
                OASchema::string('type')->title('type')->default($route->name()),
                OASchema::object('attributes')->properties(...$fields->get('attributes')),
                OASchema::object('relationships')->properties(...$fields->get('relationships') ?: []),
            );
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function update(Route $route): OASchema
    {
        $objectId = SchemaBuilder::objectId($route);
        $resource = $this->generator->resources()->resource($route->schema()::model());

        $fields = $this->fields($route->schema()->fields(), $resource, $route->schema());

        return OASchema::object($objectId)
            ->title('Resource/' . ucfirst($route->name(true)) . '/Update')
            ->properties(
                OASchema::string('type')->title('type')->default($route->name()),
                $this->idProperty($resource),
                OASchema::object('attributes')->properties(...$fields->get('attributes')),
                OASchema::object('relationships')->properties(...$fields->get('relationships') ?: []),
            )
            ->required('type', 'id', 'attributes');
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function fetchRelationship(Route $route): OASchema
    {
        if (!$route->isPolymorphic()) {
            $resource = $this->generator->resources()->resource($route->inversSchema()::model());
        } else {
            $resource = $this->generator->resources()->resource(Arr::first($route->inversSchemas())::model());
        }

        $inverseRelation = $route->relation() !== null ? $route->relation()->inverse() : null;

        return $this->relationshipData($route->relation(), $resource, $inverseRelation)->title(
            'Resource/' . ucfirst($route->name(true)) . '/Relationship/' . ucfirst($route->relationName()) . '/Fetch',
        );
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function updateRelationship(Route $route): OASchema
    {
        if (!$route->isPolymorphic()) {
            $resource = $this->generator->resources()->resource($route->inversSchema()::model());
        } else {
            $resource = $this->generator->resources()->resource(Arr::first($route->inversSchemas())::model());
        }

        $dataSchema = $this->getDataSchema($route, $resource);

        return $dataSchema->title(
            'Resource/' . ucfirst($route->name(true)) . '/Relationship/' . ucfirst($route->relationName()) . '/Update',
        );
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function attachRelationship(Route $route): OASchema
    {
        if (!$route->isPolymorphic()) {
            $resource = $this->generator->resources()->resource($route->inversSchema()::model());
        } else {
            $resource = $this->generator->resources()->resource(Arr::first($route->inversSchemas())::model());
        }

        $dataSchema = $this->getDataSchema($route, $resource);

        return $dataSchema->title(
            'Resource/' . ucfirst($route->name(true)) . '/Relationship/' . ucfirst($route->relationName()) . '/Attach',
        );
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function detachRelationship(Route $route): OASchema
    {
        if (!$route->isPolymorphic()) {
            $resource = $this->generator->resources()->resource($route->inversSchema()::model());
        } else {
            $resource = $this->generator->resources()->resource(Arr::first($route->inversSchemas())::model());
        }
        $dataSchema = $this->getDataSchema($route, $resource);

        return $dataSchema->title(
            'Resource/' . ucfirst($route->name(true)) . '/Relationship/' . ucfirst($route->relationName()) . '/Detach',
        );
    }

    /**
     * @param  mixed  $objectId
     *
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function fetchPolymorphicRelationship(Route $route, $objectId): OASchema
    {
        $resource = $this->generator->resources()->resource($route->schema()::model());

        $inverseRelation = $route->relation() !== null ? $route->relation()->inverse() : null;

        return $this
            ->relationshipData($route->relation(), $resource, $inverseRelation)
            ->objectId($objectId)
            ->title(
                'Resource/'
                . ucfirst($route->name(true))
                . '/Relationship/'
                . ucfirst($route->relationName())
                . '/Fetch',
            );
    }

    /**
     * @param  mixed  $route
     * @return \GoldSpecDigital\ObjectOrientedOAS\Objects\Parameter[]
     */
    public function sortables($route, ?JASchema $target = null): array
    {
        $target ??= $route->schema();

        $fieldsWithDescriptions = collect($target->sortFields())
            ->merge(collect($target->sortables())->map(function (Sortable $sortable) {
                return $sortable->sortField();
            })->whereNotNull())
            ->map(function (string $field) {
                return [
                    [$field, 'By ' . $field . ', ascending'],
                    ['-' . $field, 'By ' . $field . ', descending'],
                ];
            })
            ->flatten(1)
            ->mapWithKeys(fn(array $fieldAndDescription) => [$fieldAndDescription[0] => $fieldAndDescription[1]])
            ->toArray();

        $fields = array_keys($fieldsWithDescriptions);

        $pagination = $target->pagination();
        if ($pagination instanceof CursorPagination)
            return [];

        $parameter = Parameter::query('sort')
            ->name('sort')
            ->schema(OASchema::array()->items(OASchema::string()->x('enumDescriptions', $fieldsWithDescriptions)->enum(
                ...$fields,
            )))
            ->allowEmptyValue(false)
            ->required(false)
            ->style('form')
            ->explode(false);

        if ($pagination instanceof MultiPagination) {
            $parameter = $parameter->description('Disallowed if using cursor pagination.');
        }
        return [$parameter];
    }

    public function pagination(Route $route, ?JASchema $target = null): array
    {
        $pagination = ($target ?? $route->schema())->pagination();
        if ($pagination instanceof PagePagination) {
            $pageSize = OASchema::integer();
            $defaultPerPage = self::defaultPerPage($pagination);

            if ($defaultPerPage !== null) {
                $pageSize = $pageSize->default($defaultPerPage);
            }

            return [
                Parameter::query('pageSize')
                    ->name('page[size]')
                    ->description('The page size for paginated results')
                    ->required(false)
                    ->allowEmptyValue(false)
                    ->schema($pageSize),
                Parameter::query('pageNumber')
                    ->name('page[number]')
                    ->description('The page number for paginated results')
                    ->required(false)
                    ->allowEmptyValue(false)
                    ->schema(OASchema::integer()),
            ];
        }

        if ($pagination instanceof CursorPagination) {
            return [
                Parameter::query('pageLimit')
                    ->name('page[limit]')
                    ->description('The page limit for paginated results')
                    ->required(false)
                    ->allowEmptyValue(false)
                    ->schema(OASchema::integer()),
                Parameter::query('pageAfter')
                    ->name('page[after]')
                    ->description('The page offset for paginated results')
                    ->required(false)
                    ->allowEmptyValue(false)
                    ->schema(OASchema::string()),
                Parameter::query('pageBefore')
                    ->name('page[before]')
                    ->description('The page offset for paginated results')
                    ->required(false)
                    ->allowEmptyValue(false)
                    ->schema(OASchema::string()),
            ];
        }

        if ($pagination instanceof MultiPagination) {
            return [
                Parameter::query('pageSize')
                    ->name('page[size]')
                    ->description('The number of items per page.')
                    ->required(false)
                    ->allowEmptyValue(false)
                    ->schema(OASchema::integer()),
                Parameter::query('pageNumber')
                    ->name('page[number]')
                    ->description('For standard pagination, the page number. Pass this to use standard pagination.')
                    ->required(false)
                    ->allowEmptyValue(false)
                    ->schema(OASchema::integer()),
                Parameter::query('pageAfter')
                    ->name('page[after]')
                    ->description(
                        'For cursor pagination, show results with an ID after this. Pass this or `page[before]` to use cursor pagination.',
                    )
                    ->required(false)
                    ->allowEmptyValue(false)
                    ->schema(OASchema::string()),
                Parameter::query('pageBefore')
                    ->name('page[before]')
                    ->description(
                        'For cursor pagination, show results with an ID before this. Pass this or `page[after]` to use cursor pagination.',
                    )
                    ->required(false)
                    ->allowEmptyValue(false)
                    ->schema(OASchema::string()),
            ];
        }

        return [];
    }

    /**
     * @param Filter[] $filters
     * @return \GoldSpecDigital\ObjectOrientedOAS\Objects\Parameter[]
     */
    public function filters($route, ?array $filters = null, ?JASchema $target = null): array
    {
        return collect($filters ?? ($target ?? $route->schema())->filters())
            ->map(function (Filter $filterInstance) use ($route) {
                $descriptor = $this->getDescriptor($filterInstance);
                $descriptorInstance = new $descriptor($this->generator, $route, $filterInstance);
                if ($this->hasManualDescription($filterInstance)) {
                    $descriptorInstance = new FilterWithDescriptionDescriptor(
                        $this->generator,
                        $route,
                        $filterInstance,
                    )->withDescriptor($descriptorInstance);
                }

                return $descriptorInstance->filter();
            })
            ->flatten()
            ->toArray();
    }

    /**
     * @return \GoldSpecDigital\ObjectOrientedOAS\Objects\Parameter[]
     */
    public function sparseFieldsets(Route $route, ?JASchema $target = null, ?string $targetResource = null): array
    {
        $target ??= $route->schema();
        $targetResource ??= $route->resource();
        $maxDepth = 1;
        $forSchema = function (JASchema $schema, string $resource, ?array $parents = null) use ($maxDepth): ?Parameter {
            $sparseFields = iterator_to_array($schema->sparseFields());
            $fieldName = 'fields[' . $resource . ']';
            if (count($sparseFields) == 0)
                return null;

            if ($parents !== null && count($parents) > $maxDepth)
                return null;

            $parentString = $parents !== null ? implode('.', $parents) . '.' : '';
            return Parameter::query($parentString . $resource . '.sparseFields')
                ->name($fieldName)
                ->description(
                    'Only return these fields for the "'
                    . $resource
                    . '" resource. The .data field will be empty if none of the fields are set on a resource.',
                )
                ->schema(OASchema::array()->items(OASchema::string()->enum(...$sparseFields)))
                ->allowEmptyValue(false)
                ->style('form')
                ->explode(false);
        };
        $out = [$forSchema($target, $targetResource)];

        $includePaths = collect($target->includePaths())
            ->filter(fn(string $includePath) => substr_count($includePath, '.') < $maxDepth);
        $resources = [$targetResource => true];
        foreach ($includePaths as $includePath) {
            try {
                $relation = $target->relationship($includePath);
            } catch (\Exception $_) {
                continue;
            }
            $resource = $relation->inverse();
            if (isset($resources[$resource]))
                continue;
            $schemas = $this->generator->server()->schemas();
            /*
             * A polymorphic container relation reports its own field name as the
             * inverse type. That name is not a registered resource type, so it has
             * no field list to offer as a sparse fieldset.
             */
            if (!$schemas->exists($resource))
                continue;
            $resources[$resource] = true;
            $out[] = $forSchema($schemas->schemaFor($resource), $resource, [$targetResource]);
        }
        return array_filter($out);
    }

    /**
     * @return \GoldSpecDigital\ObjectOrientedOAS\Objects\Parameter[]
     */
    public function includes(Route $route, ?JASchema $target = null, ?string $targetResource = null): array
    {
        $includePaths = ($target ?? $route->schema())->includePaths();
        return [Parameter::query(($targetResource ?? $route->resource()) . '.include')
            ->name('include')
            ->description(
                'Additionally fetch these related resources. Each related resources will be placed in the .included key of the root document, and the main resource each relates to will list the related IDs in its "relationships" section.',
            )
            ->schema(OASchema::array()->items(OASchema::string()->enum(...$includePaths)))
            ->allowEmptyValue(false)
            ->style('form')
            ->explode(false)];
    }

    /**
     * @param  \LaravelJsonApi\Contracts\Schema\Field[]  $fields
     */
    protected function fields(array $fields, ?JsonApiResource $resource, ?JASchema $schema = null): Collection
    {
        return collect($fields)->mapToGroups(function (Field $field) {
            switch (true) {
                case $field instanceof AttributeContract:
                    $key = 'attributes';
                    break;
                case $field instanceof RelationContract:
                    $key = 'relationships';
                    break;
                default:
                    $key = 'unknown';
            }

            return [$key => $field];
        })->map(function ($fields, $type) use ($resource, $schema) {
            switch ($type) {
                case 'attributes':
                    return $this->attributes($fields, $resource, $schema);
                case 'relationships':
                    return $this->relationships($fields, $resource);
                case 'actions':
                    return $this->actions($fields, $resource);
                default:
                    return null;
            }
        });
    }

    /**
     * @return Schema[]
     */
    protected function attributes(Collection $fields, ?JsonApiResource $example, ?JASchema $schema = null): array
    {
        $nullability = self::attributeNullability($schema);

        return $fields
            ->filter(fn($field) => !$field instanceof ID)
            ->map(function (Field $field) use ($example, $nullability) {
                $fieldId = $field->name();
                $descriptionField = null;
                if ($field instanceof FieldWithDescription) {
                    $descriptionField = $field;
                    $field = $descriptionField->attr;
                }
                switch (true) {
                    case $field instanceof Boolean:
                        $fieldDataType = OASchema::boolean($fieldId);
                        break;
                    case $field instanceof Number:
                        $fieldDataType = OASchema::number($fieldId);
                        break;
                    case $field instanceof ArrayList:
                        $fieldDataType = OASchema::array($fieldId);
                        break;
                    case $field instanceof ArrayHash:
                    case $field instanceof Map:
                        $fieldDataType = OASchema::object($fieldId);
                        break;
                    default:
                        $fieldDataType = OASchema::string($fieldId);
                }

                $schema = $fieldDataType->title($field->name());

                /*
                 * OpenAPI 3.0 has no null type, so a nullable attribute is expressed
                 * as a flag alongside its declared type rather than as a union.
                 */
                if ($nullability[$fieldId] ?? false) {
                    $schema = $schema->nullable(true);
                }

                $column = $field instanceof EloquentAttribute ? $field->column() : $field->name();

                /*
                 * Attribute values are read off a live resource, so reading them can
                 * trigger relation lazy-loads. Skip every such read when examples are
                 * disabled: it is the difference between a generation run that issues
                 * queries and one that issues none.
                 */
                $canSampleValues = $example !== null && ResourceContainer::examplesEnabled();

                if ($field instanceof NonEloquentAttribute) {
                    $attributes = $canSampleValues ? self::sampleAttributes($example) : [];
                    if (isset($attributes[$column])) {
                        $schema = $schema->example($attributes[$column]);
                    }
                } else {
                    if ($descriptionField) {
                        if ($descriptionField->getDescription()) {
                            $schema = $schema->description($descriptionField->getDescription());
                        }
                        if ($descriptionField->getFormat()) {
                            $schema = $schema->format($descriptionField->getFormat());
                        }
                        if ($descriptionField->getEnum()) {
                            $schema = $schema->enum(...$descriptionField->getEnum());
                            if ($descriptionField->getExample() === null)
                                $descriptionField->withExample($descriptionField->getEnum()[0]);
                        }
                        $schema = $descriptionField->generateSubSchema($schema, $fieldId);
                    }
                    if ($descriptionField && $descriptionField->getExample() !== null) {
                        $example = $descriptionField->getExample();
                        if ($example !== '')
                            $schema = $schema->example($example);
                    } else if ($canSampleValues && ($sampled = self::sampleValue($example, $column)) !== null) {
                        $schema = $schema->example(
                            $descriptionField ? $descriptionField->formatExample($sampled) : $sampled,
                        );
                    }
                    if ($field instanceof EloquentAttribute && $field->isReadOnly(null)) {
                        $schema = $schema->readOnly(true);
                    }
                }

                return $schema;
            })
            ->toArray();
    }

    /**
     * @param Field[] $fields
     * @param ?JsonApiResource $example
     * @param ?string $parentType
     * @return Collection<OASchema>
     */
    protected function included(array $fields, ?JsonApiResource $example, ?string $parentType = null): array
    {
        $out = collect($fields)
            ->filter(fn(Field $field) => $field instanceof RelationContract)
            ->map(fn(Field $field) => $this->include($field, $example, $parentType))
            ->filter()
            ->toArray();
        return $out;
    }

    /** Returns null if the child type is the same as parent type.
     * @return ?OASchema
     */
    protected function include(
        RelationContract $relation,
        ?JsonApiResource $example,
        ?string $parentType = null,
    ): ?OASchema {
        $fieldId = $relation->name();
        $type = $relation->inverse();
        // dump($parentType . ' => ' . $type . ': ' . $fieldId);
        if ($type === $parentType)
            return null;
        $schemas = $this->generator->server()->schemas();
        /*
         * A polymorphic container relation reports its own field name as the
         * inverse type. That name is not a registered resource type, so no single
         * schema can describe it and it is omitted rather than aborting the run.
         */
        if (!$schemas->exists($type))
            return null;
        $schema = $schemas->schemaFor($type);
        return $this->fetch($schema, "resources.$type.resource.fetch", $type, $fieldId)->description(
            "May not be present unless \"$fieldId\" is in the \"include\" header. See `.data[].relationships.$fieldId.data` for the lists of IDs which have been included here.",
        );

        // return OASchema::object($relation->name());
    }

    /**
     * @todo Fix relation field names
     */
    protected function relationships(Collection $relationships, ?JsonApiResource $example): array
    {
        return $relationships->map(function (RelationContract $relation) use ($example) {
            return $this->relationship($relation, $example);
        })->toArray();
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function relationship(
        RelationContract $relation,
        ?JsonApiResource $example,
        ?bool $includeData = null,
    ): OASchema {
        $fieldId = $relation->name();
        if ($includeData === null) {
            foreach ($example->relationships(null) as $resourceRelation) {
                if (!$resourceRelation instanceof Relation)
                    continue;
                if ($resourceRelation->fieldName() !== $fieldId)
                    continue;
                if ($resourceRelation->showData()) {
                    $includeData = true;
                    break;
                }
            }
        }

        $type = $relation->inverse();

        $linkSchema = $this->relationshipLinks($relation, $example);

        $dataSchema = $this->relationshipData($relation, $example, $type)->description(
            "Related item with type and ID. Add \"$fieldId\" to the \"include\" query parameter to get the full object; results will be found in `.included`.",
        );

        if ($relation instanceof Eloquent\Fields\Relations\ToMany) {
            $dataSchema = OASchema::array('data')->items($dataSchema);
        }
        $schema = OASchema::object($fieldId)
            ->title($relation->name())
            ->description(
                "May include a list of IDs of relevant items in the `data` field. To retrieve these listed items as well, add \"$fieldId\" to the \"include\" query parameter; results will be found in `.included`.",
            );

        if ($includeData) {
            return $schema->properties($dataSchema);
        } else {
            return $schema->properties($linkSchema);
        }
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function relationshipData(RelationContract $relation, ?JsonApiResource $example, string $type): OASchema
    {
        $fieldId = $relation->name();
        if ($relation instanceof PolymorphicRelation) {
            // @todo Add examples for each available type
            $dataSchema = OASchema::object('data')
                ->title($relation->name())
                ->required('type', 'id')
                ->properties(
                    OASchema::string('type')->title('type')->enum(...$relation->inverseTypes()),
                    OASchema::string('id')->title('id'),
                );
        } else {
            $dataSchema = OASchema::object('data')
                ->title($relation->name())
                ->required('type', 'id')
                ->properties(
                    OASchema::string('type')->title('type')->default($type),
                    OASchema::string('id')->title('id')->example($example->id()),
                );
        }

        return $dataSchema;
    }

    public function relationshipLinks(RelationContract $relation, ?JsonApiResource $example): OASchema
    {
        $name = Str::dasherize(Str::plural(Str::camel($relation->name())));

        /*
         * @todo Create real links
         */
        $relatedLink = $this->generator->server()->url([
            $name,
            $example->id(),
        ]);

        /*
         * @todo Create real links
         */
        $selfLink = $this->generator->server()->url([
            $name,
            $example->id(),
        ]);

        return OASchema::object('links')
            ->readOnly(true)
            ->properties(
                OASchema::string('related')->title('related')->example($relatedLink),
                OASchema::string('self')->title('self')->example($selfLink),
            );
    }

    protected function links(Route $route, ?JsonApiResource $resource): array
    {
        $url = $this->generator->server()->url([
            $route->name(),
            $resource->id(),
        ]);

        return [
            OASchema::string('self')->title('self')->example($url),
        ];
    }

    /**
     * @todo Get descriptors from Attributes
     */
    protected function getDescriptor(Filter $filter): string
    {
        if (self::hasManualDescription($filter)) {
            $filter = $filter->filter;
        }
        foreach ($this->filterDescriptors as $filterClass => $descriptor) {
            if ($filter instanceof $filterClass) {
                return $descriptor;
            }
        }

        return Filters\DefaultDescriptor::class;
    }

    protected static function hasManualDescription(Filter $filter): bool
    {
        return $filter instanceof FilterWithDescription;
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function getDataSchema(Route $route, ?JsonApiResource $resource): OASchema
    {
        $inverseRelation = $route->relation() !== null ? $route->relation()->inverse() : null;

        $relation = $route->relation();

        $dataSchema = $this->relationshipData($relation, $resource, $inverseRelation);

        if ($relation instanceof Eloquent\Fields\Relations\ToMany) {
            $dataSchema = OASchema::array('data')->items($dataSchema);
        }

        return $dataSchema;
    }
}
