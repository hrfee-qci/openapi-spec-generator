<?php

namespace LaravelJsonApi\OpenApiSpec\Descriptors\Schema\Filters;

use GoldSpecDigital\ObjectOrientedOAS\Objects\Example;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Parameter;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema as OASchema;
use LaravelJsonApi\OpenApiSpec\Contracts\Descriptors\FilterDescriptor as FilterDescriptorContract;
use LaravelJsonApi\OpenApiSpec\Filters\WithDescription as LaravelJsonApiWithDescription;

class WithDescription extends FilterDescriptor
{
    protected ?FilterDescriptorContract $descriptor;

    public function withDescriptor(FilterDescriptorContract $descriptor): static
    {
        $this->descriptor = $descriptor;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function filter(): array
    {
        if (!$this->filter instanceof LaravelJsonApiWithDescription)
            return [];

        $parents = $this->descriptor->filter();
        $parent = $parents[0];
        if ($this->filter->getDescription())
            $parent = $parent->description($this->filter->getDescription());
        if ($this->filter->getDefault()) {
            $schema = $parent->schema;
            $parent = $parent->schema($schema->default($this->filter->getDefault()));
        }
        $examples = $this->filter->getExamples();
        if ($examples && count($examples)) {
            $parent = $parent->examples(...array_map(
                fn($example, $key) => Example::create(is_string($key) ? $key : $example)->value($example),
                $examples,
                array_keys($examples),
            ));
        }
        if ($this->filter->getFormat())
            $parent = $parent->schema($parent->schema->format($this->filter->getFormat()));
        if ($this->filter->getEnum()) {
            $parent = $parent->schema($parent->schema->enum(...$this->filter->getEnum()));
        }

        $parents[0] = $parent;
        return $parents;
    }

    protected function description(): string
    {
        if ($this->filter instanceof LaravelJsonApiWithDescription) {
            return $this->filter->getDescription();
        }
        return '';
    }

    function __call($method, $args)
    {
        $out = call_user_func_array([$this->descriptor, $method], $args);
        return $out;
    }
}
