<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Support\Controllers;

/**
 * An invokable controller whose route name does parse as `{resource}.{action}`.
 *
 * This is the harder skip case: the name looks describable, so only the absence of
 * a `@method` segment in the action distinguishes it. Splitting that action on '@'
 * yields a single element, so anything reading the second element fails.
 */
class PostStatsController extends Controller
{
    public function __invoke(): array
    {
        return ['posts' => 0];
    }
}
