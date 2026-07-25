<?php

namespace LaravelJsonApi\OpenApiSpec;

use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Contracts\Server\Server;
use LaravelJsonApi\Contracts\Store\QueriesAll;
use LaravelJsonApi\Core\Resources\JsonApiResource;

class ResourceContainer
{
    protected Server $server;

    /** @var \Illuminate\Support\Collection[] */
    protected array $resources = [];

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * Whether example values may be sampled from the database.
     *
     * When this is false the container is inert: it issues no query and constructs
     * no resource, so callers must treat a null resource as "no example available"
     * rather than as an error.
     */
    public static function examplesEnabled(): bool
    {
        return (bool) config('openapi.examples', false);
    }

    /**
     * @param  mixed  $model  Model class as FQN, model instance or an Schema instance
     */
    public function resource($model): ?JsonApiResource
    {
        $fqn = $this->getFQN($model);
        if (! isset($this->resources[$fqn])) {
            $this->loadResources($fqn);
        }

        /*
         * A missing resource is recoverable, not fatal. Some resources are not
         * backed by a queryable model at all, and callers must degrade to
         * "no example available" rather than aborting the document.
         */
        return $this->resources[$fqn]->first();
    }

    /**
     * @param  mixed  $model
     * @return JsonApiResource[]
     */
    public function resources($model): array
    {
        /*
         * These feed example lists only, never structure, so an empty result is
         * always safe. Returning nothing also covers resources with no queryable
         * model at all, which can never supply a sample no matter how the database
         * is populated.
         */
        if (! self::examplesEnabled()) {
            return [];
        }

        $fqn = $this->getFQN($model);
        if (! isset($this->resources[$fqn])) {
            $this->loadResources($fqn);
        }

        return $this->resources[$fqn]->toArray();
    }

    protected function getFQN($model): string
    {
        $fqn = $model;
        if ($model instanceof Schema) {
            $fqn = $model::model();
        } elseif (is_object($model)) {
            $fqn = get_class($model);
        }

        return $fqn;
    }

    protected function loadResources(string $model)
    {
        $schema = $this->server->schemas()->schemaForModel($model);
        $repository = $schema->repository();

        /*if ($repository instanceof QueriesAll) {
            $this->resources[$model] = collect($repository->queryAll()->get())
                ->map(function ($model) {
                    return $this->server->resources()->create($model);
                })
                ->take(3);

            return;
        }*/

        /*
         * Always seed the key, even when nothing can be sampled. A non-Eloquent
         * resource has no queryable model, and leaving the key unset makes every
         * later read a fatal undefined-index rather than a recoverable miss.
         */
        $this->resources[$model] = collect();

        if (! method_exists($model, 'all')) {
            return;
        }

        /*
         * A resource instance is still needed with examples disabled, because the
         * document's *structure* is partly read from it: whether a relationship
         * carries a `data` member is a property of the resource, not of the schema.
         * An unsaved instance supplies that structure while holding no row data and
         * costing no query. Callers must not read attribute values off it, since
         * doing so triggers relation lazy-loads.
         */
        if (! self::examplesEnabled()) {
            $this->resources[$model] = collect([$this->server->resources()->create(new $model)]);

            return;
        }

        $this->resources[$model] = $model::query()->take(100)->get()->map(function ($model) {
            return $this->server->resources()->create($model);
        })->take(3);
    }
}
