<?php

return [

    'app_id' => env('NATIVEPHP_APP_ID', 'com.medicalplus.mobile'),

    'app_version' => env('NATIVEPHP_APP_VERSION', 'DEBUG'),

    'app_version_code' => env('NATIVEPHP_APP_VERSION_CODE', 1),

    'start_url' => env('NATIVEPHP_START_URL', '/'),

    'runtime' => [
        'mode' => env('NATIVEPHP_RUNTIME_MODE', 'persistent'),
        'reset_instances' => true,
        'gc_between_dispatches' => false,
    ],

    'server' => [
        'http_port' => env('NATIVEPHP_HTTP_PORT', 3000),
        'ws_port' => env('NATIVEPHP_WS_PORT', 8081),
        'service_name' => env('NATIVEPHP_SERVICE_NAME', 'Medical Plus Server'),
        'open_browser' => env('NATIVEPHP_OPEN_BROWSER', true),
    ],

    'hot_reload' => [
        'watch_paths' => [
            'app',
            'resources',
            'routes',
            'config',
            'public',
        ],
        'exclude_patterns' => [
            '\.git',
            'storage',
            'node_modules',
            'vendor',
        ],
    ],

    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME', 'medicalplus'),
    'deeplink_host' => env('NATIVEPHP_DEEPLINK_HOST'),

    'ipad' => false,

    'orientation' => [
        'iphone' => [
            'portrait' => true,
            'upside_down' => false,
            'landscape_left' => false,
            'landscape_right' => false,
        ],
        'android' => [
            'portrait' => true,
            'upside_down' => false,
            'landscape_left' => false,
            'landscape_right' => false,
        ],
    ],

    'android' => [
        'compile_sdk' => env('NATIVEPHP_ANDROID_COMPILE_SDK', 36),
        'min_sdk' => env('NATIVEPHP_ANDROID_MIN_SDK', 33),
        'target_sdk' => env('NATIVEPHP_ANDROID_TARGET_SDK', 36),

        'status_bar_style' => env('NATIVEPHP_ANDROID_STATUS_BAR_STYLE', 'auto'),

        'build' => [
            'minify_enabled' => env('NATIVEPHP_ANDROID_MINIFY_ENABLED', true),
            'shrink_resources' => env('NATIVEPHP_ANDROID_SHRINK_RESOURCES', true),
            'obfuscate' => env('NATIVEPHP_ANDROID_OBFUSCATE', true),
            'debug_symbols' => env('NATIVEPHP_ANDROID_DEBUG_SYMBOLS', 'none'),
            'parallel_builds' => env('NATIVEPHP_ANDROID_PARALLEL_BUILDS', true),
            'incremental_builds' => env('NATIVEPHP_ANDROID_INCREMENTAL_BUILDS', true),
            'abis' => env('NATIVEPHP_ANDROID_ABIS', 'arm64-v8a'),
        ],
    ],

    'cleanup_env_keys' => [
        'APP_KEY',
        'DB_PASSWORD',
        'MAIL_PASSWORD',
        'AWS_SECRET_ACCESS_KEY',
        'SANCTUM_TOKEN_PREFIX',
    ],

    'cleanup_exclude_files' => [
        'storage/logs',
        'storage/debugbar',
        'storage/app/private',
        'storage/app/public',
        'storage/framework/cache/data',
        '.git',
        'node_modules',
        'tests',
    ],

    'production_api_url' => env('PRODUCTION_API_URL', 'https://prof-hosam-fekry.online/api/mobile/v1'),

    'auto_login' => env('NATIVE_AUTO_LOGIN', env('APP_ENV', 'production') !== 'production'),

    'permissions' => [
        'NSCameraUsageDescription' => 'Used to capture patient document photos.',
        'NSMicrophoneUsageDescription' => 'Used to record audio notes for patient records.',
        'NSPhotoLibraryUsageDescription' => 'Used to select patient document images.',
    ],
];
