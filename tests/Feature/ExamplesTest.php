<?php

namespace LaravelJsonApi\OpenApiSpec\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;
use LaravelJsonApi\OpenApiSpec\Tests\Support\Database\Seeders\DatabaseSeeder;
use LaravelJsonApi\OpenApiSpec\Tests\TestCase;

/**
 * Examples are the only source of nondeterminism in a generated document, and the
 * only route by which live row data can reach a published spec. These tests pin
 * both properties: with examples disabled the run touches no data and emits no
 * example, and it does so without giving up any structure.
 */
class ExamplesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function generate(bool $examples): array
    {
        config()->set('openapi.examples', $examples);

        return json_decode(GeneratorFacade::generate('v1', 'json'), true);
    }

    /**
     * Recursively collect the value of every key with the given name.
     *
     * @return array<int, mixed>
     */
    private function collectKey(array $document, string $key): array
    {
        $found = [];

        foreach ($document as $k => $value) {
            if ($k === $key) {
                $found[] = $value;
            }

            if (is_array($value)) {
                $found = array_merge($found, $this->collectKey($value, $key));
            }
        }

        return $found;
    }

    /**
     * @return array<mixed>
     */
    private function withoutKeys(array $document, array $keys): array
    {
        $stripped = [];

        foreach ($document as $k => $value) {
            if (in_array($k, $keys, true)) {
                continue;
            }

            $stripped[$k] = is_array($value) ? $this->withoutKeys($value, $keys) : $value;
        }

        return $stripped;
    }

    public function test_examples_are_disabled_by_default(): void
    {
        $this->assertFalse(config('openapi.examples'));
    }

    public function test_generating_without_examples_issues_no_queries(): void
    {
        config()->set('openapi.examples', false);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        GeneratorFacade::generate('v1', 'json');

        $this->assertEquals([], $queries);
    }

    public function test_generating_with_examples_does_issue_queries(): void
    {
        config()->set('openapi.examples', true);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        GeneratorFacade::generate('v1', 'json');

        $this->assertNotEquals([], $queries, 'Guards the no-query assertion against passing for the wrong reason.');
    }

    public function test_no_example_keys_are_emitted_when_examples_are_disabled(): void
    {
        $document = $this->generate(examples: false);

        $this->assertEquals([], $this->collectKey($document, 'example'));
        $this->assertEquals([], $this->collectKey($document, 'examples'));
    }

    public function test_example_keys_are_emitted_when_examples_are_enabled(): void
    {
        $document = $this->generate(examples: true);

        $this->assertNotEquals([], $this->collectKey($document, 'example'));
    }

    /**
     * The load-bearing property: disabling examples must remove examples and nothing
     * else. If this fails, the examples-off document is not a faithful description of
     * the API and cannot stand in for the examples-on one.
     */
    public function test_disabling_examples_removes_examples_and_nothing_else(): void
    {
        $withExamples = $this->generate(examples: true);
        $withoutExamples = $this->generate(examples: false);

        $this->assertEquals(
            $this->withoutKeys($withExamples, ['example', 'examples']),
            $withoutExamples,
        );
    }

    public function test_output_is_byte_identical_across_runs_without_examples(): void
    {
        config()->set('openapi.examples', false);

        $first = GeneratorFacade::generate('v1', 'json');
        $second = GeneratorFacade::generate('v1', 'json');

        $this->assertEquals($first, $second);
    }
}
