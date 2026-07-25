<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;
use LaravelJsonApi\OpenApiSpec\Tests\Support\Database\Seeders\DatabaseSeeder;
use LaravelJsonApi\OpenApiSpec\Tests\TestCase;

/**
 * A route can be undescribable for good reasons, so skipping is not an error. But
 * an unreported skip is indistinguishable from an endpoint quietly disappearing,
 * which is the failure mode a generated spec is supposed to eliminate.
 */
class SkippedRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        GeneratorFacade::generate('v1', 'json');
    }

    /**
     * @return string[]
     */
    private function skippedNames(): array
    {
        return array_column(GeneratorFacade::skippedRoutes(), 'route');
    }

    public function test_a_plain_controller_route_is_reported(): void
    {
        $this->assertContains('v1.health', $this->skippedNames());
    }

    public function test_an_invokable_controller_route_is_reported(): void
    {
        $this->assertContains('v1.posts.stats', $this->skippedNames());
    }

    public function test_every_skip_carries_a_uri_and_a_reason(): void
    {
        $skipped = GeneratorFacade::skippedRoutes();

        $this->assertNotEmpty($skipped);

        foreach ($skipped as $entry) {
            $this->assertNotEmpty($entry['uri'], "Skip entry for [{$entry['route']}] has no uri.");
            $this->assertNotEmpty($entry['reason'], "Skip entry for [{$entry['route']}] has no reason.");
        }
    }

    /**
     * Unrelated application routes are not this server's business, and listing them
     * would bury the skips that matter.
     */
    public function test_routes_belonging_to_other_servers_are_not_reported(): void
    {
        RouteFacade::get('elsewhere', fn () => null)->name('other.things.index');

        GeneratorFacade::generate('v1', 'json');

        $this->assertNotContains('other.things.index', $this->skippedNames());
    }

    /**
     * The full inventory of what this fixture application cannot describe, so that a
     * newly undescribable route shows up here as a failure rather than as a quietly
     * shorter document.
     *
     * `purge` and `publish` are JSON:API custom actions, and the two `sites` entries
     * are a non-Eloquent resource whose controller uses no action trait. All four
     * were being dropped in silence before this diagnostic existed.
     */
    public function test_the_set_of_undescribable_routes_is_exactly_as_expected(): void
    {
        $names = $this->skippedNames();
        sort($names);

        $this->assertEquals([
            'v1.health',
            'v1.posts.publish',
            'v1.posts.purge',
            'v1.posts.stats',
            'v1.sites.destroy',
            'v1.sites.update',
        ], $names);
    }

    /**
     * The two failure classes are reported with distinguishable reasons: rejected
     * before parsing, versus parsed but with no describable operation.
     */
    public function test_both_classes_of_skip_are_reported(): void
    {
        $reasons = array_column(GeneratorFacade::skippedRoutes(), 'reason', 'route');

        $this->assertStringContainsString('not a JSON:API action', $reasons['v1.posts.purge']);
        $this->assertStringContainsString('no recognised JSON:API action trait', $reasons['v1.sites.update']);
    }

    /**
     * Diagnostics are command output, so they must not reach the document.
     */
    public function test_reporting_does_not_change_the_generated_document(): void
    {
        $first = GeneratorFacade::generate('v1', 'json');

        $this->assertNotEmpty(GeneratorFacade::skippedRoutes());
        $this->assertEquals($first, GeneratorFacade::generate('v1', 'json'));
        $this->assertStringNotContainsString('not described', $first);
    }
}
