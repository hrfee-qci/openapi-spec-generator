<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;
use LaravelJsonApi\OpenApiSpec\Tests\Support\Database\Seeders\DatabaseSeeder;
use LaravelJsonApi\OpenApiSpec\Tests\TestCase;

/**
 * The `servers` block and `info` block are the two places where environment config
 * can leak into an otherwise schema-derived document.
 */
class ServerBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function document(): array
    {
        return json_decode(GeneratorFacade::generate('v1', 'json'), true);
    }

    private function serverUrl(array $document): string
    {
        return $document['servers'][0]['variables']['serverUrl']['default'];
    }

    public function test_the_server_url_is_read_from_config_when_set(): void
    {
        config()->set('openapi.servers.v1.url', 'https://api.example.com/api/v1');

        $this->assertEquals('https://api.example.com/api/v1', $this->serverUrl($this->document()));
    }

    public function test_the_server_url_falls_back_to_the_application_url(): void
    {
        config()->set('openapi.servers.v1.url', null);
        URL::forceRootUrl('http://fallback.test');

        $this->assertStringContainsString('fallback.test', $this->serverUrl($this->document()));
    }

    /**
     * The point of the config key: output must not change when the surrounding
     * application config does. The root URL is forced rather than set through
     * config, because the URL generator reads its root once at boot and would
     * ignore a later config change, making this pass for the wrong reason.
     */
    public function test_a_configured_url_makes_output_independent_of_the_app_url(): void
    {
        config()->set('openapi.servers.v1.url', 'https://api.example.com/api/v1');

        URL::forceRootUrl('http://first.test');
        $first = GeneratorFacade::generate('v1', 'json');

        URL::forceRootUrl('http://second.test');
        $second = GeneratorFacade::generate('v1', 'json');

        $this->assertEquals($first, $second);
        $this->assertStringNotContainsString('first.test', $first);
    }

    public function test_without_the_config_key_the_app_url_does_leak_into_the_document(): void
    {
        config()->set('openapi.servers.v1.url', null);

        URL::forceRootUrl('http://first.test');
        $first = GeneratorFacade::generate('v1', 'json');

        URL::forceRootUrl('http://second.test');
        $second = GeneratorFacade::generate('v1', 'json');

        $this->assertNotEquals($first, $second, 'Guards the independence assertion against passing vacuously.');
    }

    public function test_license_is_emitted_when_configured(): void
    {
        config()->set('openapi.servers.v1.info.license', [
            'name' => 'Apache-2.0',
            'url' => 'https://opensource.org/license/apache-2-0',
        ]);

        $info = $this->document()['info'];

        $this->assertEquals('Apache-2.0', $info['license']['name']);
        $this->assertEquals('https://opensource.org/license/apache-2-0', $info['license']['url']);
    }

    public function test_license_url_is_optional(): void
    {
        config()->set('openapi.servers.v1.info.license', ['name' => 'Apache-2.0']);

        $license = $this->document()['info']['license'];

        $this->assertEquals('Apache-2.0', $license['name']);
        $this->assertArrayNotHasKey('url', $license);
    }

    public function test_no_license_key_is_emitted_when_unconfigured(): void
    {
        config()->set('openapi.servers.v1.info.license', null);

        $this->assertArrayNotHasKey('license', $this->document()['info']);
    }
}
