<?php

namespace LaravelJsonApi\OpenApiSpec\Concerns;

use LaravelJsonApi\OpenApiSpec\Attributes\WithDescription;
use LaravelJsonApi\OpenApiSpec\Descriptors\Actions\ActionDescriptor;
use LaravelJsonApi\OpenApiSpec\Route as SpecRoute;
use ReflectionAttribute;

trait ResolvesActionTraitToDescriptor
{
    /**
     * @todo Get descriptors from Attributes
     * @return ?string|array
     */
    protected function descriptorClass(SpecRoute $route, bool $instance = false): mixed
    {
        [$class, $method] = $route->controllerCallable();
        try {
            $reflection = new \ReflectionClass($class);
            $methodReflection = $reflection->getMethod($method);

            if ($methodReflection->getDeclaringClass()->name !== $reflection->name) {
                $reflection = $methodReflection->getDeclaringClass();
            }
            $traitMethod = collect($reflection->getTraits())
                ->map(function (\ReflectionClass $trait) {
                    return $trait->getMethods();
                })
                ->flatten()
                ->mapWithKeys(fn(\ReflectionMethod $method) => [$method->name => $method])
                ->get($method);

            if ($traitMethod === null) {
                $attrs = $methodReflection->getAttributes(WithDescription::class);
                if (!empty($attrs) && !empty($attrs[0])) {
                    // $attr = $attrs[0]->newInstance();
                    return WithDescription::class;
                }
            }
        } catch (\ReflectionException $exception) {
            return null;
        }

        return $traitMethod !== null ? $traitMethod->getDeclaringClass()->name : null;
    }
}
