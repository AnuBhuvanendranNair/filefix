<?php

declare(strict_types=1);

return [
    'backend' => [
        'anubit/filefix/filelist-mimefix' => [
            'target' => \Anubit\Filefix\Middleware\FilelistMimeFixMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
            'before' => [
                'typo3/cms-backend/backend-module-validator',
            ],
        ],
    ],
];
