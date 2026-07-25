<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;
use LaravelJsonApi\OpenApiSpec\Tests\Support\Database\Seeders\DatabaseSeeder;
use LaravelJsonApi\OpenApiSpec\Tests\TestCase;

/**
 * JSON:API schemas carry no nullability information, so a schema opts in by
 * defining `attributeNullability()`. Without it a document silently claims every
 * attribute is always present and non-null.
 */
class NullabilityTest extends TestCase
{
    use RefreshDatabase;

    protected array $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->document = json_decode(GeneratorFacade::generate('v1', 'json'), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(string $component): array
    {
        $this->assertArrayHasKey(
            $component,
            $this->document['components']['schemas'],
            "Component [{$component}] is missing.",
        );

        return $this->document['components']['schemas'][$component]['properties']['attributes']['properties'];
    }

    private function commentAttributes(): array
    {
        return $this->attributes('resources.comments.resource.fetch');
    }

    public function test_an_attribute_declared_nullable_is_marked_nullable(): void
    {
        $this->assertTrue($this->commentAttributes()['content']['nullable']);
    }

    public function test_an_attribute_declared_non_nullable_carries_no_nullable_key(): void
    {
        $this->assertArrayNotHasKey('nullable', $this->commentAttributes()['createdAt']);
    }

    /**
     * A schema that has not opted in must be emitted exactly as before.
     */
    public function test_a_schema_without_the_hook_emits_no_nullable_keys(): void
    {
        foreach ($this->attributes('resources.posts.resource.fetch') as $name => $attribute) {
            $this->assertArrayNotHasKey('nullable', $attribute, "Attribute [{$name}] should carry no nullable key.");
        }
    }

    /**
     * Nullability sits alongside the declared type rather than replacing it, which
     * is the only way OpenAPI 3.0 can express it.
     */
    public function test_nullability_does_not_displace_the_declared_type(): void
    {
        $content = $this->commentAttributes()['content'];

        $this->assertEquals('string', $content['type']);
        $this->assertTrue($content['nullable']);
    }

    public function test_a_declared_name_matching_no_field_is_ignored(): void
    {
        $names = array_keys($this->commentAttributes());

        $this->assertNotContains('doesNotExist', $names);
    }
}
