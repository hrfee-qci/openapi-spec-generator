<?php

namespace LaravelJsonApi\OpenApiSpec\Attributes;

use Closure;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema;

// WithDescription tags a route method with a description and example output if desired, used for generating OpenAPI docs.
#[\Attribute]
class WithDescription
{
    /**
     * WithDescription constructor.
     *
     * @param mixed|Schema $responseClassOrSchema
     * @param ?string|closure():string $description
     * @param ?mixed $example
     */
    public function __construct(
        private mixed $responseClassOrSchema,
        private ?string $description = null,
        private mixed $example = null,
    ) {}

    /**
     * WithDescription.
     *
     * @param ?string $description
     * @param ?mixed $example
     * @return self
     */
    public static function make(?string $description = null, mixed $example = null, ?Attribute $attr = null): self
    {
        return new self($description, $example, $attr);
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
        if (!$this->example)
            return null;
        $example = $this->example;
        if ($this->example instanceof Closure)
            $example = ($this->example)();
        return $example;
    }

    public function getResponseClassOrSchema(): mixed
    {
        return $this->responseClassOrSchema;
    }
}
