<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Support\Controllers;

/**
 * A plain, non-JSON:API controller registered under the server's route prefix.
 *
 * Applications routinely put routes like this alongside their JSON:API resources.
 * The generator must skip them rather than trying to parse their names as
 * `{server}.{resource}.{action}`.
 */
class HealthController extends Controller
{
    public function check(): array
    {
        return ['status' => 'ok'];
    }
}
