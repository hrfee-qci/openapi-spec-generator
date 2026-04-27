<?php

namespace LaravelJsonApi\OpenApiSpec\Descriptors;

use GoldSpecDigital\ObjectOrientedOAS\Objects;
use LaravelJsonApi\OpenApiSpec\Descriptors\Descriptor as BaseDescriptor;

class Server extends BaseDescriptor
{
    /**
     * @todo Add contact
     * @todo Add TOS
     * @todo Add License
     */
    public function info(): Objects\Info
    {
        return Objects\Info::create()
            ->title(config("openapi.servers.{$this->generator->key()}.info.title"))
            ->description(config("openapi.servers.{$this->generator->key()}.info.description"))
            ->version(config("openapi.servers.{$this->generator->key()}.info.version"));
    }

    // @return Objects\SecurityScheme[]
    public function securitySchemes(): array
    {
        $schemes = config("openapi.servers.{$this->generator->key()}.securitySchemes", []);
        $assert = fn(bool $assertion, string $error) => $assertion || throw new \Error($error);
        return array_map(
            function (array $scheme, string $name) use ($assert): Objects\SecurityScheme {
                $assert(
                    isset($scheme['type']) && $scheme['type'] === 'oauth2',
                    "Only OAuth2 security schemes are currently supported. Please remove any non-oauth2 schemes from the {$this->generator->key()} server in your config.",
                );
                if ($scheme['type'] === 'oauth2') {
                    $assert(
                        isset($scheme['flows']) && !empty($scheme['flows']),
                        "openapi.servers.{$this->generator->key()}.securitySchemes.{$name}.flows must be set.",
                    );
                    $flows = array_map(
                        function ($flow, $flowName) use ($name, $assert) {
                            $flowSchema = Objects\OAuthFlow::create($flowName)->flow($flowName);
                            foreach ($flow as $field => $value) {
                                if ($field === 'authorizationUrl') {
                                    $flowSchema = $flowSchema->authorizationUrl($value);
                                } else if ($field === 'tokenUrl') {
                                    $flowSchema = $flowSchema->tokenUrl($value);
                                } else if ($field === 'refreshUrl') {
                                    $flowSchema = $flowSchema->refreshUrl($value);
                                } else if ($field === 'scopes') {
                                    $assert(
                                        is_array($value) && !empty($value),
                                        "openapi.servers.{$this->generator->key()}.securitySchemes.{$name}.flows.{$flowName}.{$field} must be a non-empty array",
                                    );
                                    $flowSchema = $flowSchema->scopes($value);
                                }
                            }
                            return $flowSchema;
                        },
                        $scheme['flows'],
                        array_keys($scheme['flows']),
                    );
                    return Objects\SecurityScheme::oauth2($name)->flows(...$flows);
                }
                return Objects\SecurityScheme::create($ref)->type($scheme['type']);
            },
            $schemes,
            array_keys($schemes),
        );
    }

    /**
     * @return \LaravelJsonApi\Core\Server\Server[]
     *
     * @todo Allow Configuration
     * @todo Use for enums?
     * @todo Extract only URI Server Prefix and let domain be set separately
     */
    public function servers(): array
    {
        return [
            Objects\Server::create()->url('{serverUrl}')->variables(Objects\ServerVariable::create(
                'serverUrl',
            )->default($this->generator->server()->url())),
        ];
    }
}
