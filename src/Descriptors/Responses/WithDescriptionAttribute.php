<?php

namespace LaravelJsonApi\OpenApiSpec\Descriptors\Responses;

use Carbon\Carbon;
use Error;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema;
use Illuminate\Support\Collection;
use LaravelJsonApi\OpenApiSpec\Builders\Paths\Operation\SchemaBuilder;
use LaravelJsonApi\OpenApiSpec\Generator;
use LaravelJsonApi\OpenApiSpec\Helpers\SchemaFromExample;
use LaravelJsonApi\OpenApiSpec\Route;

class WithDescriptionAttribute extends ResponseDescriptor
{
    private array $response;

    /* Takes a user-provided example and returns it as a response.
     * @param Generator $generator
     * @param Route $route
     * @param SchemaBuilder $schemaBuilder
     * @param Collection $defaults
     * @param array $response
     */
    public function __construct(
        Generator $generator,
        Route $route,
        SchemaBuilder $schemaBuilder,
        Collection $defaults,
        array $response,
    ) {
        parent::__construct($generator, $route, $schemaBuilder, $defaults);
        $this->response = $response;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function response(): array
    {
        return [
            $this->ok(),
            ...$this->defaults(),
        ];
    }

    protected function data(): Schema
    {
        return SchemaFromExample::generate(example: $this->response, key: 'data');
    }
}
