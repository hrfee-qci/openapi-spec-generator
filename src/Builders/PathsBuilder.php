<?php

namespace LaravelJsonApi\OpenApiSpec\Builders;

use GoldSpecDigital\ObjectOrientedOAS\Objects\PathItem;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use LaravelJsonApi\OpenApiSpec\Builders\Paths\OperationBuilder;
use LaravelJsonApi\OpenApiSpec\ComponentsContainer;
use LaravelJsonApi\OpenApiSpec\Generator;
use LaravelJsonApi\OpenApiSpec\Route as SpecRoute;

class PathsBuilder extends Builder
{
    protected ComponentsContainer $components;

    protected OperationBuilder $operation;

    public function __construct(Generator $generator, ComponentsContainer $components)
    {
        parent::__construct($generator);
        $this->components = $components;
        $this->operation = new OperationBuilder($generator, $components);
    }

    /**
     * @return \GoldSpecDigital\ObjectOrientedOAS\Objects\PathItem[]
     */
    /**
     * Routes that exist but are absent from the document, with the reason.
     *
     * @var array<int, array{route: string, uri: string, reason: string}>
     */
    protected array $skipped = [];

    /**
     * @return array<int, array{route: string, uri: string, reason: string}>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    private function skip(IlluminateRoute $route, string $reason): void
    {
        $this->skipped[] = [
            'route' => $route->getName() ?? '(unnamed)',
            'uri' => $route->uri(),
            'reason' => $reason,
        ];
    }

    /**
     * @return \GoldSpecDigital\ObjectOrientedOAS\Objects\PathItem[]
     */
    public function build(): array
    {
        $this->skipped = [];
        $server = $this->generator->server();

        return collect(Route::getRoutes()->getRoutes())
            ->filter(function (IlluminateRoute $route) use ($server) {
                $reason = SpecRoute::rejectionReason($route, $server);

                /*
                 * Only report routes that were plausibly meant for this server.
                 * Every unrelated route in the application fails the name check, and
                 * listing all of them would bury the ones that matter.
                 */
                if ($reason !== null && str_starts_with($route->getName() ?? '', $server->name() . '.')) {
                    $this->skip($route, $reason);
                }

                return $reason === null;
            })
            ->map(fn(IlluminateRoute $route) => new SpecRoute($server, $route))
            ->mapToGroups(function (SpecRoute $route) {
                return [$route->uri() => $route];
            })
            ->map(function (Collection $routes, string $uri) {
                $operations = $routes->map(function (SpecRoute $route) {
                    $operation = $this->operation->build($route);

                    /*
                     * A route can match every structural check and still have no
                     * describable operation, because the controller does not use any
                     * recognised action trait. Dropping that silently is how a
                     * genuinely custom endpoint disappears from the document
                     * unnoticed.
                     */
                    if ($operation === null) {
                        $this->skip($route->route(), 'controller declares no recognised JSON:API action trait');
                    }

                    return $operation;
                })->filter(fn($val) => $val !== null);

                if ($operations->isEmpty()) {
                    return null;
                }

                return PathItem::create()->route($uri)->operations(...$operations->toArray());
            })
            ->filter(fn($val) => $val !== null)
            ->toArray();
    }
}
