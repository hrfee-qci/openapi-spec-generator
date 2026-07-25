<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;
use LaravelJsonApi\OpenApiSpec\Route;
use LaravelJsonApi\OpenApiSpec\Tests\Support\Database\Seeders\DatabaseSeeder;
use LaravelJsonApi\OpenApiSpec\Tests\TestCase;

/**
 * Scope enforcement is not standardized, so the default scan only recognises
 * Passport's middleware. An application using its own is otherwise documented as
 * requiring no scope, which reads as "this endpoint is unrestricted".
 */
class SecurityScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        // The fixture routes carry the `api` middleware, so match the scheme to it.
        config()->set('openapi.servers.v1.securitySchemes', [
            'OAuth2' => [
                'middleware' => ['api'],
                'type' => 'oauth2',
                'flows' => [
                    'authorizationCode' => [
                        'authorizationUrl' => '/oauth/authorize',
                        'tokenUrl' => '/oauth/token',
                        'scopes' => ['data:read' => 'Read data'],
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Route::resolveScopesUsing(null);

        parent::tearDown();
    }

    private function securityFor(string $path): array
    {
        $document = json_decode(GeneratorFacade::generate('v1', 'json'), true);

        return $document['paths'][$path]['get']['security'];
    }

    public function test_the_scheme_applies_without_any_scopes_by_default(): void
    {
        $this->assertEquals([['OAuth2' => []]], $this->securityFor('/posts'));
    }

    public function test_a_registered_resolver_supplies_scopes(): void
    {
        Route::resolveScopesUsing(
            fn (string $middleware) => $middleware === 'api' ? ['data:read'] : [],
        );

        $this->assertEquals([['OAuth2' => ['data:read']]], $this->securityFor('/posts'));
    }

    /**
     * Only scopes the scheme actually declares may be attached to an operation.
     */
    public function test_a_resolver_cannot_introduce_scopes_the_scheme_does_not_declare(): void
    {
        Route::resolveScopesUsing(fn () => ['not:declared']);

        $this->assertEquals([['OAuth2' => []]], $this->securityFor('/posts'));
    }

    public function test_clearing_the_resolver_restores_the_default_behaviour(): void
    {
        Route::resolveScopesUsing(fn () => ['data:read']);
        Route::resolveScopesUsing(null);

        $this->assertEquals([['OAuth2' => []]], $this->securityFor('/posts'));
    }
}
