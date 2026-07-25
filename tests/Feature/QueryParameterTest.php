<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;
use LaravelJsonApi\OpenApiSpec\Tests\Support\Database\Seeders\DatabaseSeeder;
use LaravelJsonApi\OpenApiSpec\Tests\TestCase;

/**
 * Query parameter coverage per action.
 *
 * Which parameters an operation accepts is derived from the action, so a single
 * missed action leaves whole classes of endpoint documented as accepting nothing
 * but a path parameter.
 */
class QueryParameterTest extends TestCase
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
     * @return string[]
     */
    private function parameterNames(string $path, string $method = 'get'): array
    {
        $this->assertArrayHasKey($path, $this->document['paths'], "Path [{$path}] is missing from the document.");
        $this->assertArrayHasKey(
            $method,
            $this->document['paths'][$path],
            "Path [{$path}] has no [{$method}] operation.",
        );

        return collect($this->document['paths'][$path][$method]['parameters'] ?? [])
            ->pluck('name')
            ->all();
    }

    private function assertHasParameterLike(array $names, string $prefix): void
    {
        $this->assertNotEmpty(
            array_filter($names, fn (string $name) => str_starts_with($name, $prefix)),
            "Expected a parameter starting with [{$prefix}], got: " . implode(', ', $names),
        );
    }

    private function assertHasNoParameterLike(array $names, string $prefix): void
    {
        $this->assertEmpty(
            array_filter($names, fn (string $name) => str_starts_with($name, $prefix)),
            "Expected no parameter starting with [{$prefix}], got: " . implode(', ', $names),
        );
    }

    public function test_index_carries_the_full_collection_parameter_set(): void
    {
        $names = $this->parameterNames('/posts');

        $this->assertHasParameterLike($names, 'page[');
        $this->assertHasParameterLike($names, 'filter[');
        $this->assertHasParameterLike($names, 'fields[');
        $this->assertContains('sort', $names);
        $this->assertContains('include', $names);
    }

    public function test_show_carries_include_and_sparse_fieldsets(): void
    {
        $names = $this->parameterNames('/posts/{post}');

        $this->assertContains('include', $names);
        $this->assertHasParameterLike($names, 'fields[');
    }

    /**
     * A single-resource fetch has nothing to paginate, sort, or filter.
     */
    public function test_show_does_not_carry_collection_only_parameters(): void
    {
        $names = $this->parameterNames('/posts/{post}');

        $this->assertHasNoParameterLike($names, 'page[');
        $this->assertHasNoParameterLike($names, 'filter[');
        $this->assertNotContains('sort', $names);
    }

    public function test_show_still_carries_its_path_parameter(): void
    {
        $this->assertContains('post', $this->parameterNames('/posts/{post}'));
    }

    private function enumFor(string $path, string $parameter): array
    {
        $found = collect($this->document['paths'][$path]['get']['parameters'])
            ->firstWhere('name', $parameter);

        $this->assertNotNull($found, "Parameter [{$parameter}] is missing from [{$path}].");

        return $found['schema']['items']['enum'];
    }

    public function test_show_related_carries_the_full_collection_parameter_set(): void
    {
        $names = $this->parameterNames('/posts/{post}/tags');

        $this->assertHasParameterLike($names, 'page[');
        $this->assertHasParameterLike($names, 'filter[');
        $this->assertHasParameterLike($names, 'fields[');
        $this->assertContains('sort', $names);
        $this->assertContains('include', $names);
        $this->assertContains('post', $names);
    }

    /**
     * The load-bearing assertion for relationship endpoints. `tags` sorts by `name`
     * and `posts` sorts by `title`, so resolving against the wrong schema produces a
     * plausible-looking enum full of fields the response does not have.
     */
    public function test_show_related_resolves_sorts_against_the_inverse_schema(): void
    {
        $sorts = $this->enumFor('/posts/{post}/tags', 'sort');

        $this->assertContains('name', $sorts, 'Expected the tag schema\'s sortable fields.');
        $this->assertNotContains('title', $sorts, 'Got the parent post schema\'s sortable fields instead.');
    }

    /**
     * The same relation reached from two different parents must document the same
     * inverse-derived parameters, because those describe the resource being
     * returned. Filters are excluded: a parent may scope filters to its own
     * relation, so those legitimately differ.
     */
    public function test_the_same_relation_documents_identically_from_either_parent(): void
    {
        $inverseDerived = fn (array $names) => array_values(array_filter(
            $names,
            fn (string $name) => ! in_array($name, ['post', 'video'], true)
                && ! str_starts_with($name, 'filter['),
        ));

        $this->assertEquals(
            $inverseDerived($this->parameterNames('/videos/{video}/tags')),
            $inverseDerived($this->parameterNames('/posts/{post}/tags')),
        );
    }

    public function test_relation_scoped_filters_are_emitted(): void
    {
        $names = $this->parameterNames('/posts/{post}/tags');

        $this->assertContains('filter[createdAt]', $names, 'Expected the filter the post schema scopes to its tags relation.');
    }

    public function test_relation_scoped_filters_also_apply_to_the_relationships_endpoint(): void
    {
        $this->assertContains('filter[createdAt]', $this->parameterNames('/posts/{post}/relationships/tags'));
    }

    /**
     * The same relation on a parent that scopes no filters must still get the
     * inverse schema's own filters, and none of the other parent's.
     */
    public function test_a_relation_without_scoped_filters_gets_only_the_inverse_schema_filters(): void
    {
        $names = $this->parameterNames('/videos/{video}/tags');

        $this->assertContains('filter[id][]', $names);
        $this->assertContains('filter[name]', $names);
        $this->assertNotContains('filter[createdAt]', $names);
    }

    /**
     * A filter declared on both the relation and the inverse schema describes one
     * query parameter, not two.
     */
    public function test_a_filter_declared_on_both_the_relation_and_the_schema_appears_once(): void
    {
        $names = $this->parameterNames('/posts/{post}/tags');

        $this->assertCount(
            1,
            array_filter($names, fn (string $name) => $name === 'filter[name]'),
            'Expected the colliding filter to be emitted exactly once: ' . implode(', ', $names),
        );
    }

    /**
     * Mutating a relation is not a read, so it takes no query parameters.
     */
    public function test_relationship_mutations_carry_only_the_path_parameter(): void
    {
        foreach (['patch', 'post', 'delete'] as $method) {
            $this->assertEquals(
                ['post'],
                $this->parameterNames('/posts/{post}/relationships/tags', $method),
                "Expected [{$method}] on a relationship to take no query parameters.",
            );
        }
    }

    public function test_show_related_emits_sparse_fieldsets_for_the_inverse_resource(): void
    {
        $names = $this->parameterNames('/posts/{post}/tags');

        $this->assertContains('fields[tags]', $names);
    }

    /**
     * A relationships endpoint returns resource identifiers, so parameters that
     * shape how a resource renders do not apply to it.
     */
    public function test_show_relationship_carries_selection_but_not_rendering_parameters(): void
    {
        $names = $this->parameterNames('/posts/{post}/relationships/tags');

        $this->assertHasParameterLike($names, 'page[');
        $this->assertContains('sort', $names);
        $this->assertNotContains('include', $names);
        $this->assertHasNoParameterLike($names, 'fields[');
    }

    /**
     * A to-one relation returns one resource, so it takes the `show` parameter set.
     */
    public function test_a_to_one_related_endpoint_takes_the_show_parameter_set(): void
    {
        $names = $this->parameterNames('/posts/{post}/author');

        $this->assertContains('include', $names);
        $this->assertHasParameterLike($names, 'fields[');
        $this->assertHasNoParameterLike($names, 'page[');
        $this->assertNotContains('sort', $names);
    }

    /**
     * The include enum must be the same set the resource offers on its collection
     * endpoint, otherwise the two documented views of one resource disagree.
     */
    public function test_the_include_enum_on_show_matches_the_one_on_index(): void
    {
        $enumFor = function (string $path): array {
            $parameter = collect($this->document['paths'][$path]['get']['parameters'])
                ->firstWhere('name', 'include');

            return $parameter['schema']['items']['enum'];
        };

        $this->assertEquals($enumFor('/posts'), $enumFor('/posts/{post}'));
    }
}
