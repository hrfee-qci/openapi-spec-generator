<?php

namespace LaravelJsonApi\OpenApiSpec\Descriptors\Responses;

use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema;
use LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Schema as SchemaDescriptor;

class FetchOne extends ResponseDescriptor
{
    protected bool $hasId = true;

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

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function data(): Schema
    {
        return $this->schemaBuilder->build($this->route)->objectId('data');
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function included(): ?Schema
    {
        $descriptor = new SchemaDescriptor($this->generator);

        $schemas = $descriptor->fetchWithIncluded(
            $this->route->schema(),
            $this->schemaBuilder->objectId($this->route, false),
            $this->route->resource(),
            $this->route->name(true),
        );

        return $schemas['included'] ?? null;
    }
}
