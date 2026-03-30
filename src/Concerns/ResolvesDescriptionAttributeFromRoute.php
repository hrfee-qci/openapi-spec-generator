<?php

namespace LaravelJsonApi\OpenApiSpec\Concerns;

use LaravelJsonApi\OpenApiSpec\Attributes\WithDescription;
use LaravelJsonApi\OpenApiSpec\Route as SpecRoute;

trait ResolvesDescriptionAttributeFromRoute
{
    /**
     * @todo Get WithDescription attribute from route if set.
     * @return ?WithDescription
     */
    protected function descriptionFromRoute(SpecRoute $route, bool $instance = false): ?WithDescription
    {
        [$class, $method] = $route->controllerCallable();
        try {
            $reflection = new \ReflectionClass($class);
            $methodReflection = $reflection->getMethod($method);

            $attrs = $methodReflection->getAttributes(WithDescription::class);
            if (!empty($attrs) && !empty($attrs[0])) {
                return $attrs[0]->newInstance();
            }
        } catch (\ReflectionException $exception) {
            return null;
        }
        return null;
    }
}
