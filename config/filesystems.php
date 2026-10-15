<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * The files people attach: compliance documents and photos of the
         * work (docs/slice-16-document-storage-plan.md; decisions 33 and
         * 156). Used for nothing else. On staging and production it is a
         * private Wasabi bucket of its own, reached by a key limited to it;
         * the key is put into the server's environment by Jacob and is never
         * in the repository. Anywhere else, a private folder on disk, so no
         * key is needed to develop or test.
         */
        'files' => env('FILES_DRIVER', 'local') === 's3'
            ? [
                'driver' => 's3',
                'key' => env('FILES_ACCESS_KEY_ID'),
                'secret' => env('FILES_SECRET_ACCESS_KEY'),
                'region' => env('FILES_REGION', 'us-east-1'),
                'bucket' => env('FILES_BUCKET'),
                'endpoint' => env('FILES_ENDPOINT', 'https://s3.us-east-1.wasabisys.com'),
                'use_path_style_endpoint' => false,
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ]
            : [
                'driver' => 'local',
                'root' => storage_path('app/files'),
                // Served only by a signed link that expires, like the bucket's.
                'serve' => true,
                'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/local-files',
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
