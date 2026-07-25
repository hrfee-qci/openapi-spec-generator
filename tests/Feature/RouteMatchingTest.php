<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;
use LaravelJsonApi\OpenApiSpec\Route;
use LaravelJsonApi\OpenApiSpec\Tests\Support\Database\Seeders\DatabaseSeeder;
use LaravelJsonApi\OpenApiSpec\Tests\Support\JsonApi\V1\Server;
use LaravelJsonApi\OpenApiSpec\Tests\TestCase;

/**
 * Route matching decides what the generator will even attempt to describe. Matching
 * too loosely is not a cosmetic problem: an ordinary application route registered
 * under the same name prefix gets handed to a parser that assumes a JSON:API shape,
 * and the whole run dies on it.
 */
class RouteMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function server(): Server
    {
        return app(\LaravelJsonApi\Contracts\Server\Repository::class)->server('v1');
    }

    private function routeNamed(string $name): \Illuminate\Routing\Route
    {
        $route = collect(RouteFacade::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === $name);

        $this->assertNotNull($route, "Fixture route [{$name}] is not registered, so this test would pass vacuously.");

        return $route;
    }

    public function test_a_plain_controller_route_under_the_server_prefix_is_rejected(): void
    {
        $route = $this->routeNamed('v1.health');

        $this->assertFalse(Route::belongsTo($route, $this->server()));
        $this->assertStringContainsString('segment', Route::rejectionReason($route, $this->server()));
    }

    public function test_an_invokable_controller_route_is_rejected(): void
    {
        $route = $this->routeNamed('v1.posts.stats');

        $this->assertFalse(Route::belongsTo($route, $this->server()));
        $this->assertStringContainsString('invokable', Route::rejectionReason($route, $this->server()));
    }

    public function test_standard_json_api_routes_are_still_matched(): void
    {
        foreach (['v1.posts.index', 'v1.posts.show'] as $name) {
            $route = $this->routeNamed($name);

            $this->assertTrue(
                Route::belongsTo($route, $this->server()),
                "Expected [{$name}] to be describable.",
            );
            $this->assertNull(Route::rejectionReason($route, $this->server()));
        }
    }

    public function test_relationship_routes_are_still_matched(): void
    {
        $route = $this->routeNamed('v1.posts.comments');

        $this->assertTrue(Route::belongsTo($route, $this->server()));
    }

    /**
     * The old matcher used a bare substring test, so a route merely containing the
     * server name anywhere was swept in.
     */
    public function test_a_route_that_merely_contains_the_server_name_is_rejected(): void
    {
        RouteFacade::get('legacy', fn () => null)->name('legacy.v1.things.index');

        $route = $this->routeNamed('legacy.v1.things.index');

        $this->assertFalse(Route::belongsTo($route, $this->server()));
    }

    public function test_generation_succeeds_with_undescribable_routes_registered(): void
    {
        $document = json_decode(GeneratorFacade::generate('v1', 'json'), true);

        $this->assertArrayHasKey('/posts', $document['paths']);
        $this->assertArrayNotHasKey('/health', $document['paths']);
        $this->assertArrayNotHasKey('/posts/stats', $document['paths']);
    }
}
