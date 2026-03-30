<?php

namespace LaravelJsonApi\OpenApiSpec\Eloquent\Fields;

use Closure;
use LaravelJsonApi\Contracts\Schema\Attribute;
use LaravelJsonApi\Eloquent\Contracts\Filter;

// WithDescription proxies filters and adds documentation, used for generating OpenAPI docs.
class WithDescription extends Attribute
{
    /**
     * WithDescription constructor.
     *
     * @param ?string|closure():string $description
     * @param ?mixed|array<mixed, mixed> $example
     * @param ?mixed $default
     */
    public function __construct(
        private ?string $description = null,
        private mixed $example = null,
        private mixed $default = null,
        public ?Attribute $attr = null,
    ) {}

    /**
     * WithDescription.
     *
     * @param ?string $description
     * @param ?mixed|array<mixed, mixed> $example
     * @param ?mixed $default
     * @return self
     */
    public static function make(
        ?string $description = null,
        mixed $example = null,
        mixed $default = null,
        ?Filter $filter = null,
    ): self {
        return new self($description, $example, $default, $filter);
    }

    /**
     * Adds a filter.
     */
    public function withAttribute(Attribute $attr): self
    {
        $this->attr = $attr;
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
     * Gets examples, or an empty array if none set.
     *
     * @return array<mixed, mixed>
     */
    public function getExamples(): array
    {
        if (!$this->example)
            return [];
        $example = $this->example;
        if ($this->example instanceof Closure)
            $example = ($this->example)();
        if (!is_array($example))
            return [$example];
        return $example;
    }

    /**
     * Get the default value, or returns null if none set.
     * return value depends on whatever was passed in originally.
     *
     * @return mixed|null
     */
    public function getDefault(): mixed
    {
        if (!$this->default)
            return '';
        if ($this->default instanceof Closure)
            return ($this->default)();
        return $this->default;
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

    function __call($method, $args)
    {
        $out = call_user_func_array([$this->filter, $method], $args);
        return $out;
    }
}

