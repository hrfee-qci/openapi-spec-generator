<?php

namespace LaravelJsonApi\OpenApiSpec\Attributes;

use Closure;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema;

// WithDescription tags a route method with a description and a response Schema or an example as an array, used for generating OpenAPI docs.
#[\Attribute]
class WithDescription
{
    /**
     * WithDescription constructor.
     *
     * @param array|class-string<Schema>|closure():array $responseClassOrExample
     * @param ?string|closure():string $description
     */
    public function __construct(
        private mixed $responseClassOrExample,
        private ?string $description = null,
    ) {}

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

    /* Returns the response class, or an example if provided instead.
     * @return array|Schema
     */
    public function getResponseClassOrExample(): mixed
    {
        if ($this->responseClassOrExample instanceof Closure)
            return ($this->responseClassOrExample)();
        return $this->responseClassOrExample;
    }
}
