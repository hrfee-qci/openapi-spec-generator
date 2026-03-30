<?php

namespace LaravelJsonApi\OpenApiSpec\Descriptors\Actions;

use GoldSpecDigital\ObjectOrientedOAS\Objects\Operation;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Parameter;
use GoldSpecDigital\ObjectOrientedOAS\Objects\RequestBody;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Response;
use LaravelJsonApi\Contracts\Server\Server;
use LaravelJsonApi\OpenApiSpec\Attributes\WithDescription;
use LaravelJsonApi\OpenApiSpec\Builders\Paths\Operation\ParameterBuilder;
use LaravelJsonApi\OpenApiSpec\Builders\Paths\Operation\RequestBodyBuilder;
use LaravelJsonApi\OpenApiSpec\Builders\Paths\Operation\ResponseBuilder;
use LaravelJsonApi\OpenApiSpec\Contracts\DescribesEndpoints;
use LaravelJsonApi\OpenApiSpec\Contracts\Descriptors\ActionDescriptor as ActionDescriptorContract;
use LaravelJsonApi\OpenApiSpec\Generator;
use LaravelJsonApi\OpenApiSpec\Route;

class BasicFetchWithDescription extends ActionDescriptor
{
    private ?WithDescription $description = null;

    public function __construct(
        ParameterBuilder $parameterBuilder,
        RequestBodyBuilder $requestBodyBuilder,
        ResponseBuilder $responseBuilder,
        Generator $generator,
        Route $route,
    ) {
        parent::__construct($parameterBuilder, $requestBodyBuilder, $responseBuilder, $generator, $route);

        [$class, $method] = $route->controllerCallable();
        $reflection = new \ReflectionClass($class);
        $methodReflection = $reflection->getMethod($method);
        $attrs = $methodReflection->getAttributes(WithDescription::class);
        if (!empty($attrs) && !empty($attrs[0])) {
            $this->description = $attrs[0]->newInstance();
        }
    }

    protected function summary(): string
    {
        return $this->description->getDescription();
    }
}
