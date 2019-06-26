<?php

return [
    'frontend' => [
        'static-file-cache' => [
            'target' => \SFC\Staticfilecache\Middleware\StaticFileCacheMiddleware::class,
            'before' => [
                'typo3/cms-frontend/tsfe',
            ],
        ],
        'staticfilecache/fallback' => [
            'target' => \SFC\Staticfilecache\Middleware\StaticFileCacheFallbackMiddleware::class,
            'before' => [
                'typo3/cms-frontend/timetracker',
            ],
        ]
    ]
];
