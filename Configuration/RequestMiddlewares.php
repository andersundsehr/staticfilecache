<?php

declare(strict_types=1);

use SFC\Staticfilecache\Middleware\CookieCheckMiddleware;
use SFC\Staticfilecache\Middleware\FrontendUserMiddleware;
use SFC\Staticfilecache\Middleware\GenerateMiddleware;
use SFC\Staticfilecache\Middleware\PrepareMiddleware;

return [
    'frontend' => [
        'staticfilecache/prepare' => [
            'target' => PrepareMiddleware::class,
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
        'staticfilecache/generate' => [
            'target' => GenerateMiddleware::class,
            'before' => [
                'staticfilecache/prepare',
            ],
        ],
        'staticfilecache/frontend-user' => [
            'target' => FrontendUserMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'staticfilecache/generate',
            ],
        ],
        'staticfilecache/cookie-check' => [
            'target' => CookieCheckMiddleware::class,
            'before' => [
                'staticfilecache/generate',
            ],
            'after' => [
                'staticfilecache/frontend-user',
            ],
        ],
    ],
];
