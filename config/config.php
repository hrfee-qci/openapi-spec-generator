<?php

/*
 * OpenAPI Generator configuration
 */
return [
    'servers' => [
        'v1' => [
            // Optional. Pins the URL advertised in the document's `servers` block.
            // Without it the URL is derived from the application's base URL, which
            // makes the output depend on the environment that generated it.
            // 'url' => 'https://api.example.com/api/v1',

            'info' => [
                'title' => 'My JSON:API',
                'description' => 'JSON:API built using Laravel',
                'version' => '1.0.0',

                // Optional. `url` may be omitted.
                // 'license' => ['name' => 'Apache-2.0', 'url' => 'https://opensource.org/license/apache-2-0'],
            ],
            'securitySchemes' => [ // optional, identical shape to OpenAPI .components.securitySchemes, except two extra parameters:
                'OAuth2' => [
                    'middleware' => ['auth:api'], // routes with any of these middleware attached will require this scheme.
                    'controllers' => [\Illuminate\Routing\Controller::class => ['index', 'show']], // optional, if given acts as an additional requirement for the auth type to match, where the routes, controller must be an instance of the class and the route method must match (in this example, index() or show()).
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
            // Enhanced tags: correspond to JSON:API resource names, capitalized (e.g. user-accounts -> User-accounts)
            'tags' => [
                [
                    'name' => 'User-accounts',
                    // although OAS 3.2 is not supported, this field will be mapped to x-displayName.
                    'summary' => 'User Accounts',
                    'description' => 'Access user accounts.',
                    'externalDocs' => [
                        'url' => 'https://www.wikipedia.org/',
                        'description' => 'More information',
                    ],
                ],
            ],
        ],
    ],

    /*
     * Whether to sample example values from the database.
     *
     * When false, no query is issued, no resource instance is constructed, and no
     * `example`/`examples` key is emitted anywhere in the document. This is required
     * for byte-reproducible output: sampled rows change between runs, so a document
     * generated with examples cannot be used to detect spec drift mechanically. It
     * also keeps live row data out of a document intended for publication.
     */
    'examples' => env('OPENAPI_EXAMPLES', false),

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
