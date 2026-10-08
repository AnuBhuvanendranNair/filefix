<?php

declare(strict_types=1);

return [
    // Live usage check of one file against the whole database (duplicate report, admin only)
    'filefix_deepcheck' => [
        'path'   => '/filefix/deepcheck',
        'target' => \Anubit\Filefix\Controller\DuplicatesController::class . '::deepCheckAction',
    ],
    // One thumbnail per request, lazy-loaded by the duplicate and oversized reports (admin only)
    'filefix_thumbnail' => [
        'path'   => '/filefix/thumbnail',
        'target' => \Anubit\Filefix\Controller\ThumbnailController::class . '::thumbnailAction',
    ],
    // Dry run or resize of one image to the limits from the extension settings (oversized report, admin only)
    'filefix_resizeimage' => [
        'path'    => '/filefix/resizeimage',
        'methods' => ['POST'],
        'target'  => \Anubit\Filefix\Controller\OversizedController::class . '::resizeAction',
    ],
    // Delete one file after the deep check passed again on the server (duplicate report, admin only)
    'filefix_deletefile' => [
        'path'    => '/filefix/deletefile',
        'methods' => ['POST'],
        'target'  => \Anubit\Filefix\Controller\DuplicatesController::class . '::deleteAction',
    ],
];
