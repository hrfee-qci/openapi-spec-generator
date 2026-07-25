<?php

namespace LaravelJsonApi\OpenApiSpec;

use GoldSpecDigital\ObjectOrientedOAS\Objects\SecurityRequirement;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use LaravelJsonApi\Contracts\Schema\PolymorphicRelation;
use LaravelJsonApi\Contracts\Schema\Schema;
use LaravelJsonApi\Contracts\Server\Server;
use LaravelJsonApi\Eloquent\Fields\Relations\Relation;

class Route
{
    protected Server $server;

    protected Schema $schema;

    protected IlluminateRoute $route;

    protected string $resource;

    /**
     * @var string The controller class FQN
     */
    protected string $controller;

    /**
     * @var string The method name on the controller
     */
    protected string $method;

    /**
     * The last part of the route name. For the 'showRelated' method, the name
     * is manually added.
     */
    protected string $action;

    /**
     * @var string The route name without the prefix
     */
    protected string $operationId;

    protected string $uri;

    protected ?string $relation = null;

    // Security schemes applied to this route. Scheme application is guessed from which scopes lie within which schemes.
    // @var SecurityRequirement[] $securitySchemes
    protected array $securitySchemes = [];

    private const PASSPORT_SCOPE_MIDDLEWARE = [
        'Laravel\Passport\Http\Middleware\CheckTokenForAnyScope',
        'Laravel\Passport\Http\Middleware\CheckToken',
    ];

    /**
     * Resolves the OAuth scopes a middleware string requires.
     *
     * @var null|callable(string): string[]
     */
    private static $scopeResolver = null;

    /**
     * Teach the generator how this application declares required scopes.
     *
     * Scope enforcement is not standardized. An application using its own
     * middleware instead of Passport's is invisible to the default scan, and its
     * endpoints are then documented as requiring no scope at all, which is worse
     * than documenting nothing. Registering a resolver is the single point at
     * which that can be corrected.
     *
     * @param null|callable(string): string[] $resolver Receives one middleware
     *        string and returns the scopes it requires. Null restores the default.
     */
    public static function resolveScopesUsing(?callable $resolver): void
    {
        self::$scopeResolver = $resolver;
    }

    /**
     * @return string[]
     */
    private static function scopesFor(string $middleware): array
    {
        if (self::$scopeResolver !== null) {
            return (self::$scopeResolver)($middleware);
        }

        foreach (self::PASSPORT_SCOPE_MIDDLEWARE as $passportMiddleware) {
            if (str_starts_with($middleware, $passportMiddleware . ':')) {
                // TODO maybe parse like a CSV in case scopes have commas
                return explode(',', substr($middleware, strlen($passportMiddleware) + 1));
            }
        }

        return [];
    }

    /**
     * Route constructor.
     */
    public function __construct(Server $server, IlluminateRoute $route)
    {
        $this->server = $server;
        $this->route = $route;

        $securitySchemes = config("openapi.servers.{$this->server->name()}.securitySchemes", []);
        $matchingMiddleware = collect($securitySchemes)->map(fn(array $m) => $m['middleware']);
        $matchingControllers = collect($securitySchemes)->map(
            fn(array $scheme) => $scheme['controllers'] ?? null,
        )->map(fn(?array $controllers) => function (?string $controller) use ($controllers): bool {
            // $controller is a controller class-name with '@<method name>' appended.
            if ($controllers === null)
                return true;

            $split = explode('@', $controller);
            if (count($split) < 2)
                return false;

            foreach ($controllers as $targetClass => $actions) {
                if (is_int($targetClass) && is_string($actions)) {
                    // no actions listed so only match class
                    $targetClass = $actions;
                    if ($split[0] == $targetClass)
                        return true;
                } else {
                    foreach ($actions as $action) {
                        if ($split[0] == $targetClass && $split[1] == $action)
                            return true;
                    }
                }
            }
            return false;
        });

        $scopes = [];
        $appliedSchemes = [];
        if (!empty($securitySchemes)) {
            $middlewares = $this->route->gatherMiddleware();
            foreach ($middlewares as $middleware) {
                if (!is_string($middleware))
                    continue;

                foreach ($matchingMiddleware as $securityScheme => $middlewareToMatch) {
                    if (in_array($middleware, $middlewareToMatch)) {
                        if ($matchingControllers[$securityScheme]($this->route->action['controller'])) {
                            $appliedSchemes[$securityScheme] =
                                SecurityRequirement::create($securityScheme)->securityScheme($securityScheme);
                        }
                    }
                }

                $scopes = array_merge($scopes, self::scopesFor($middleware));
            }
        }

        if (!empty($scopes)) {
            $matchingSchemes = collect($securitySchemes)
                ->filter(fn(array $scheme) => ($scheme['scanForPassportScopes'] ?? true) && isset($scheme['flows']))
                ->map(
                    fn(array $scheme) => collect($scheme['flows'])
                        ->map(fn(array $flow) => collect($flow['scopes'] ?? [])->keys())
                        ->flatten()
                        ->unique(),
                )
                ->map(fn(Collection $schemeScopes) => $schemeScopes->intersect($scopes))
                ->filter(fn(Collection $overlap) => $overlap->count() > 0);

            foreach ($matchingSchemes as $securityScheme => $overlap) {
                $requirement = $appliedSchemes[$securityScheme] ?? SecurityRequirement::create(
                    $securityScheme,
                )->securityScheme($securityScheme);
                $requirement = $requirement->scopes(...$overlap->toArray());
                $appliedSchemes[$securityScheme] = $requirement;
            }
        }

        $this->securitySchemes = array_values($appliedSchemes);

        $segments = explode('.', $this->route->getName());
        $segments = array_slice($segments, array_search($this->server->name(), $segments) + 1);

        $this->operationId = collect($segments)->join('.');
        $relation = null;

        if (count($segments) === 2) {
            [$resource, $action] = $segments;
        } elseif (count($segments) === 3) {
            [$resource, $relation, $action] = $segments;
        } else {
            throw new \LogicException('Unable to handle action structure ' . $route->getName());
        }

        $this->resource = $resource;
        $this->schema = $this->server->schemas()->schemaFor($resource);

        if ($action !== null && $relation === null && $this->schema->isRelationship($action)) {
            $this->relation = $action;
            $this->action = 'showRelated';
        } else {
            $this->relation = $relation;
            $this->action = $action;
        }

        $this->setUriForRoute();

        [$controller, $method] = explode('@', $this->route->getActionName(), 2);

        $this->controller = $controller;
        $this->method = $method;
    }

    /**
     * @return string The HTTP method
     */
    public function method(): string
    {
        return collect($this->route->methods())->filter(fn($method) => $method !== 'HEAD')->first();
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    // @return SecurityRequirement[]
    public function securitySchemes(): array
    {
        return $this->securitySchemes;
    }

    public function route(): IlluminateRoute
    {
        return $this->route;
    }

    /**
     * @return string[]
     */
    public function controllerCallable(): array
    {
        return [$this->controller, $this->method];
    }

    public function id(): string
    {
        return $this->operationId;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function relationName(): ?string
    {
        return $this->relation;
    }

    public function relation(): ?Relation
    {
        $relation = $this->relation ? $this->schema()->relationship($this->relation) : null;

        if ($relation !== null && !$relation instanceof Relation) {
            throw new \RuntimeException('Unexpected Type');
        }

        return $relation;
    }

    public function isRelation(): bool
    {
        return $this->relation !== null;
    }

    public function isPolymorphic(): bool
    {
        return $this->relation() instanceof PolymorphicRelation;
    }

    public function invers(): ?string
    {
        return $this->relation() !== null ? $this->relation()->inverse() : null;
    }

    public function inversSchema(): ?Schema
    {
        if ($this->isRelation()) {
            if ($this->relation() instanceof PolymorphicRelation) {
                throw new \LogicException('Method is not allowed for Polymorphic relationships');
            }

            return $this->server
                ->schemas()
                ->schemaFor($this->relation() !== null ? $this->relation()->inverse() : null);
        }

        return null;
    }

    /**
     * @return \LaravelJsonApi\Contracts\Schema\Schema[]
     */
    public function inversSchemas(): array
    {
        $schemas = [];
        if ($this->isRelation()) {
            $relation = $this->relation();
            if ($relation instanceof PolymorphicRelation) {
                foreach ($relation->inverseTypes() as $type) {
                    $schemas[$type] = $this->server->schemas()->schemaFor($type);
                }
            } else {
                $schemas[$relation->inverse()] = $this->server->schemas()->schemaFor($relation->inverse());
            }
        }

        return $schemas;
    }

    public function inverseName(bool $singular = false): ?string
    {
        $relation = $this->relation() !== null ? $this->relation()->inverse() : null;
        if ($singular) {
            return Str::singular($relation);
        }

        return $relation;
    }

    /**
     * @param  false  $singular
     */
    public function name(bool $singular = false): string
    {
        if ($singular) {
            return Str::singular($this->resource);
        }

        return $this->resource;
    }

    public function resource(): string
    {
        return $this->resource;
    }

    public function action(): string
    {
        return $this->action;
    }

    /**
     * The controller methods supplied by the standard JSON:API action traits.
     *
     * A route bound to anything else cannot be described from a schema, because the
     * generator resolves each operation by which action trait the controller uses.
     */
    public const JSON_API_ACTIONS = [
        'index',
        'store',
        'show',
        'update',
        'destroy',
        'showRelated',
        'showRelationship',
        'updateRelationship',
        'attachRelationship',
        'detachRelationship',
    ];

    public static function belongsTo(IlluminateRoute $route, Server $server): bool
    {
        return self::rejectionReason($route, $server) === null;
    }

    /**
     * Why this route cannot be described, or null if it can be.
     *
     * A bare substring test on the route name is not enough. It sweeps in any route
     * whose name merely contains the server name and hands it to a parser that
     * assumes a `{server}.{resource}.{action}` shape, which then fails on ordinary
     * application routes registered under the same prefix.
     *
     * Callers report the reason rather than discarding it, so that a route dropped
     * from the document is always visible to whoever runs the generator.
     */
    public static function rejectionReason(IlluminateRoute $route, Server $server): ?string
    {
        $name = $route->getName();

        if ($name === null || ! Str::startsWith($name, $server->name() . '.')) {
            return 'name is not prefixed with the server name';
        }

        $segments = explode('.', Str::after($name, $server->name() . '.'));

        if (count($segments) < 2 || count($segments) > 3) {
            return sprintf('name has %d segment(s) after the server prefix, expected 2 or 3', count($segments));
        }

        $action = $route->getActionName();

        /*
         * An invokable controller has no method segment at all. Left alone it fails
         * later as an undefined array index rather than as a skipped route.
         */
        if (! Str::contains($action, '@')) {
            return 'route is bound to an invokable controller or a closure';
        }

        $method = Str::afterLast($action, '@');

        if (! in_array($method, self::JSON_API_ACTIONS, true)) {
            return sprintf('controller method [%s] is not a JSON:API action', $method);
        }

        if (! $server->schemas()->exists($segments[0])) {
            return sprintf('no schema is registered for resource type [%s]', $segments[0]);
        }

        return null;
    }

    protected function setUriForRoute(): void
    {
        $domain = URL::to('/');
        $serverBasePath = str_replace($domain, '', $this->server->url());

        $this->uri = str_replace($serverBasePath, '', '/' . $this->route->uri());
    }
}
