<?php

namespace LaravelJsonApi\OpenApiSpec\Eloquent\Fields;

use Closure;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema;
use Illuminate\Database\Eloquent\Model;
use LaravelJsonApi\Eloquent\Fields\ArrayList;
use LaravelJsonApi\Eloquent\Fields\Attribute;
use LaravelJsonApi\OpenApiSpec\Concerns\HasSchemaProperties;
use LaravelJsonApi\OpenApiSpec\Helpers\SchemaFromExample;

// WithDescription proxies attributes and adds documentation, used for generating OpenAPI docs.
class WithDescription extends Attribute
{
    use HasSchemaProperties;

    /*
     * Generates an array to return from a *Schema::fields() method, given an array of arrays containing a field and optional description and example.
     * @param array<int, array{ field: Attribute, description: string|null, example: mixed|null, format: string|null}|Attribute> $fields
     * @return array<int, Attribute>
     */
    public static function arrayFromFieldList(array $fields): array
    {
        return array_map(function (mixed $row) {
            if (!is_array($row))
                return $row;
            $desc = self::make(
                description: $row['description'] ?? $row[1] ?? null,
                example: $row['example'] ?? $row[2] ?? null,
                attr: $row['field'] ?? $row[0],
            );
            $format = $row['format'] ?? $row[3] ?? null;
            if ($format)
                $desc = $desc->withFormat($format);

            $enum = $row['enum'] ?? $row[4] ?? null;
            if ($enum)
                $desc = $desc->withEnum($enum);

            $pseudoSchema = $row['schema'] ?? $row[5] ?? null;
            if ($pseudoSchema)
                $desc = $desc->withPseudoSchema($pseudoSchema);

            return $desc;
        }, $fields);
    }

    /**
     * WithDescription constructor.
     *
     * @param ?string|closure():string $description
     * @param ?mixed $example
     * @param ?Attribute $attr
     */
    public function __construct(
        private ?string $description = null,
        private mixed $example = null,
        public ?Attribute $attr = null,
    ) {}

    /**
     * WithDescription.
     *
     * @param ?string $description
     * @param ?mixed $example
     * @param ?Attribute $attr
     * @return self
     */
    public static function make(?string $description = null, mixed $example = null, ?Attribute $attr = null): self
    {
        return new self($description, $example, $attr);
    }

    /**
     * Adds a filter.
     * @param Attribute $attr
     * @return self
     */
    public function withAttribute(Attribute $attr): self
    {
        $this->attr = $attr;
        return $this;
    }

    /**
     * Adds an example.
     * @param ?mixed $example
     * @return self
     */
    public function withExample(mixed $example): self
    {
        $this->example = $example;
        return $this;
    }

    /**
     * Get the description as a string, or returns null if none set.
     *
     * @return ?string
     */
    public function getDescription(): ?string
    {
        if ($this->description instanceof Closure)
            return ($this->description)();
        return $this->description;
    }

    /**
     * Gets example, or null if none set.
     *
     * @return mixed|null
     */
    public function getExample(): mixed
    {
        $example = $this->example;
        if ($this->example instanceof Closure)
            $example = ($this->example)();
        return $example;
    }

    /**
     * Attempts to generate a sub-schema for the given schema (e.g. setting types for an array's items), either from an example or a pseudo-schema if either set.
     * @param Schema $schema
     * @param mixed|null $key
     * @return ?Schema
     */
    public function generateSubSchema(?Schema $schema = null, mixed $key = null): ?Schema
    {
        $example = $this->getPseudoSchema() ?? $this->getExample();
        if ($example === null)
            return $schema;

        return SchemaFromExample::generate($schema, $example, $key, $this->format);
    }

    public function isSingular(): bool
    {
        return $this->filter->isSingular();
    }

    public function apply($query, $value)
    {
        return $this->filter->apply($query, $value);
    }

    public function key(): string
    {
        return $this->filter->key();
    }

    protected function assertValue($value): void
    {
        $this->attr->assertValue($value);
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return $this->attr->name();
    }

    /**
     * @inheritDoc
     */
    public function serializedFieldName(): string
    {
        return $this->attr->serializedFieldName();
    }

    /**
     * @return string
     */
    public function column(): string
    {
        return $this->attr->column();
    }

    /**
     * @inheritDoc
     */
    public function columnsForField(): array
    {
        return $this->attr->columnsForField();
    }

    /**
     * Customise the hydration of the model attribute.
     *
     * @param Closure $hydrator
     * @return $this
     */
    public function fillUsing(Closure $hydrator): self
    {
        $this->attr = $this->attr->fillUsing($hydrator);
        return $this;
    }

    /**
     * Customise the extraction of the model attribute.
     *
     * @param Closure $extractor
     * @return $this
     */
    public function extractUsing(Closure $extractor): self
    {
        $this->attr = $this->attr->extractUsing($extractor);
        return $this;
    }

    /**
     * Ignore mass-assignment and always fill the attribute.
     *
     * @return $this
     */
    public function unguarded(): self
    {
        $this->attr = $this->attr->unguarded();
        return $this;
    }

    /**
     * Use mass-assignment rules when filling the attribute.
     *
     * @return $this
     */
    public function guarded(): self
    {
        $this->attr = $this->attr->guarded();
        return $this;
    }

    /**
     * Customise deserialization of the value.
     *
     * @param Closure $deserializer
     * @return $this
     */
    public function deserializeUsing(Closure $deserializer): self
    {
        $this->attr = $this->attr->deserializeUsing($deserializer);
        return $this;
    }

    /**
     * @param Closure $serializer
     * @return $this
     */
    public function serializeUsing(Closure $serializer): self
    {
        $this->attr = $this->attr->serializeUsing($serializer);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function fill(Model $model, $value, array $validatedData): void
    {
        $this->attr->fill($model, $value, $validatedData);
    }

    /**
     * @inheritDoc
     */
    public function sort($query, string $direction = 'asc')
    {
        return $this->attr->sort($query, $direction);
    }

    /**
     * @inheritDoc
     */
    public function serialize(object $model)
    {
        return $this->attr->serialize($model);
    }

    /**
     * Convert the JSON value for this field.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function deserialize($value)
    {
        return $this->attr->deserialize($value);
    }

    /**
     * @return string
     */
    private function guessColumn(): string
    {
        return $this->attr->guessColumn();
    }
}
