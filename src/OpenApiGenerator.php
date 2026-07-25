<?php

namespace LaravelJsonApi\OpenApiSpec;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

class OpenApiGenerator
{
    /**
     * Routes belonging to the server that were left out of the last document.
     *
     * @var array<int, array{route: string, uri: string, reason: string}>
     */
    protected array $skippedRoutes = [];

    /**
     * @return array<int, array{route: string, uri: string, reason: string}>
     */
    public function skippedRoutes(): array
    {
        return $this->skippedRoutes;
    }

    /**
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\ValidationException
     */
    public function generate(string $serverKey, string $format = 'yaml'): string
    {
        $generator = new Generator($serverKey);
        $openapi = $generator->generate();

        $this->skippedRoutes = $generator->skippedRoutes();

        $openapi->validate();

        $storageDisk = Storage::disk(config('openapi.filesystem_disk'));

        $fileName = $serverKey.'_openapi.'.$format;

        $document = $openapi->toArray();

        if (! ResourceContainer::examplesEnabled()) {
            $document = self::withoutExamples($document);
        }

        if ($format === 'yaml') {
            $output = Yaml::dump($document);
        } elseif ($format === 'json') {
            $output = json_encode($document, JSON_PRETTY_PRINT);
        }

        $storageDisk->put($fileName, $output);

        return $output;
    }

    /**
     * Recursively remove every `example` and `examples` key.
     *
     * This runs at the single serialization point rather than at each of the many
     * call sites that can attach an example. Guarding the call sites individually
     * can silently miss one, and it would not catch the examples built from
     * constants rather than sampled data.
     *
     * @param  array<mixed>  $document
     * @return array<mixed>
     */
    private static function withoutExamples(array $document): array
    {
        $stripped = [];

        foreach ($document as $key => $value) {
            if ($key === 'example' || $key === 'examples') {
                continue;
            }

            $stripped[$key] = is_array($value) ? self::withoutExamples($value) : $value;
        }

        return $stripped;
    }
}
