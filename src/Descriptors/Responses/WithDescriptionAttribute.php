<?php

namespace LaravelJsonApi\OpenApiSpec\Descriptors\Responses;

use Carbon\Carbon;
use Error;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema;
use Illuminate\Support\Collection;
use LaravelJsonApi\OpenApiSpec\Builders\Paths\Operation\SchemaBuilder;
use LaravelJsonApi\OpenApiSpec\Generator;
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
        $isArray = true;
        foreach (array_keys($this->response) as $k) {
            if (!is_int($k)) {
                $isArray = false;
                break;
            }
        }
        if ($isArray) {
            return Schema::array('data')->example($this->response);
        } else {
            $props = [];
            foreach ($this->response as $k => $v) {
                if (is_int($v)) {
                    $props[] = Schema::integer($k)->example($v);
                } else if (is_float($v)) {
                    $props[] = Schema::number($k)->example($v);
                } else if (is_string($v)) {
                    $props[] = Schema::string($k)->example($v);
                } else if ($v instanceof Carbon) {
                    // @todo: Since WithDescription is an attribute, this couldn't ever be instantiated. Instead try parsing string as time.
                    $props[] = Schema::string($k)->format(Schema::FORMAT_DATE_TIME)->example($v);
                } else {
                    throw new Error("unknown datatype in example: $k => $v");
                }
            }
            return Schema::object('data')->properties(...$props);
        }
    }
}
