<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;
use LaravelJsonApi\OpenApiSpec\Tests\Support\Database\Seeders\DatabaseSeeder;
use LaravelJsonApi\OpenApiSpec\Tests\TestCase;

/**
 * Labels and defaults that a reader sees directly in rendered documentation.
 */
class LabellingTest extends TestCase
{
    use RefreshDatabase;

    protected array $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->document = json_decode(GeneratorFacade::generate('v1', 'json'), true);
    }

    private function operation(string $path, string $method = 'get'): array
    {
        $this->assertArrayHasKey($path, $this->document['paths'], "Path [{$path}] is missing.");

        return $this->document['paths'][$path][$method];
    }

    private function parameter(string $path, string $name): array
    {
        return collect($this->operation($path)['parameters'])->firstWhere('name', $name);
    }

    public function test_page_size_carries_the_schemas_default(): void
    {
        $this->assertEquals(25, $this->parameter('/posts/{post}/tags', 'page[size]')['schema']['default']);
    }

    /**
     * The default belongs to the paginator, so a schema that declares none must not
     * acquire one.
     */
    public function test_page_size_carries_no_default_when_the_schema_declares_none(): void
    {
        $this->assertArrayNotHasKey('default', $this->parameter('/posts', 'page[size]')['schema']);
    }

    /**
     * The default must follow the inverse schema, like every other collection
     * parameter on a relationship endpoint.
     */
    public function test_the_page_size_default_is_resolved_against_the_inverse_schema(): void
    {
        $this->assertEquals(
            $this->parameter('/videos/{video}/tags', 'page[size]')['schema']['default'],
            $this->parameter('/posts/{post}/tags', 'page[size]')['schema']['default'],
        );
    }

    /**
     * A relationship response describes the resource it returns, not its parent.
     */
    public function test_a_related_response_is_described_by_the_inverse_resource(): void
    {
        $description = $this->operation('/posts/{post}/tags')['responses']['200']['description'];

        $this->assertStringContainsString('tags', $description);
        $this->assertStringNotContainsString('ShowRelated', $description);
    }

    public function test_related_and_relationships_operations_are_distinguishable(): void
    {
        $related = $this->operation('/posts/{post}/tags')['summary'];
        $identifiers = $this->operation('/posts/{post}/relationships/tags')['summary'];

        $this->assertNotEquals($related, $identifiers);
        $this->assertStringContainsString('identifiers', $identifiers);
    }

    public function test_related_and_relationships_responses_are_distinguishable(): void
    {
        $this->assertNotEquals(
            $this->operation('/posts/{post}/tags')['responses']['200']['description'],
            $this->operation('/posts/{post}/relationships/tags')['responses']['200']['description'],
        );
    }

    /**
     * Plain resource operations keep their existing labels.
     */
    public function test_non_relationship_descriptions_are_unchanged(): void
    {
        $this->assertEquals('Index posts', $this->operation('/posts')['responses']['200']['description']);
    }
}
