<?php

namespace LaravelJsonApi\OpenApiSpec\Concerns;

use Closure;
use Error;

trait HasSchemaProperties
{
    private ?string $format = null;
    private mixed $enum = null;

    /**
     * Sets the schema format string. can be OpenAPI format (date(-time),password,byte,binary) or arbitrary.
     * @param string $format
     * @return self
     */
    public function withFormat(string $format): self
    {
        $this->format = $format;
        return $this;
    }

    /**
     * Sets the schema enums (allowed values). If closure passed, will be evaluated only at doc-compile-time.
     * @param array<mixed>|closure():array<mixed> $enums
     * @return self
     */
    public function withEnum(array|Closure $enums): self
    {
        $this->enum = $enums;
        return $this;
    }

    /**
     * Gets the format string, or returns null if none set.
     *
     * @return ?string
     */
    public function getFormat(): ?string
    {
        return $this->format;
    }

    /**
     * Get the allowed enum values, or returns null if none set.
     *
     * @return ?array
     */
    public function getEnum(): ?array
    {
        $enum = $this->enum;

        if ($enum instanceof Closure)
            $enum = ($this->enum)();

        if ($enum !== null && !is_array($enum))
            throw new Error('Got non-array enum!');

        return $enum;
    }
}
