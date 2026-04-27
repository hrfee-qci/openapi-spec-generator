<?php

/*
 * OpenAPI Generator configuration
 */
return [
    'servers' => [
        'v1' => [
            'info' => [
                'title' => 'My JSON:API',
                'description' => 'JSON:API built using Laravel',
                'version' => '1.0.0',
            ],
            'securitySchemes' => [ // optional, identical shape to OpenAPI .components.securitySchemes, except two extra parameters:
                'OAuth2' => [
                    'middleware' => ['auth:api'], // routes with any of these middleware attached will require this scheme.
                    'scanForPassportScopes' => true, // Defaults to true. Scans for Passport CheckToken/CheckTokenForAnyScope middleware and uses those scopes.
                    'type' => 'oauth2',
                    'flows' => [
                        'authorizationCode' => [
                            'authorizationUrl' => '/oauth/authorize/',
                            'tokenUrl' => '/oauth/token/',
                            'scopes' => [
                                '*' => 'Permission to access anything your account status permits.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    /*
     * The storage disk to be used to place the generated `*_openapi.json` or `*_openapi.yaml` file.
     *
     * For example, if you use 'public' you can access the generated file as public web asset (after run `php artisan storage:link`).
     *
     * Supported: 'local', 'public' and (probably) any disk available in your filesystems (https://laravel.com/docs/9.x/filesystem#configuration).
     * Set it to `null` to use your default disk.
     */
    'filesystem_disk' => env('OPEN_API_SPEC_GENERATOR_FILESYSTEM_DISK', null),
];
