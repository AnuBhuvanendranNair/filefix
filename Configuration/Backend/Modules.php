<?php

declare(strict_types=1);

return [
    // 'filefix_quarantine' module disabled for now — re-enable by restoring this block.
    // 'filefix_quarantine' => [
    //     'parent'         => 'file',
    //     'position'       => ['after' => 'filefix_cleanup'],
    //     'access'         => 'user',
    //     'workspaces'     => 'live',
    //     'iconIdentifier' => 'filefix-quarantine',
    //     'labels'         => [
    //         'title'       => 'File Quarantine',
    //         'description' => 'Manage the quarantine queue: review, move, restore or permanently delete cleanup candidates',
    //     ],
    //     'extensionName'        => 'Filefix',
    //     'navigationComponent'  => '@typo3/backend/tree/file-storage-browser',
    //     'routes'         => [
    //         '_default' => [
    //             'target' => \Anubit\Filefix\Controller\QuarantineController::class . '::indexAction',
    //         ],
    //     ],
    // ],
    'filefix_cleanup' => [
        'parent'          => 'file',
        'position'        => ['after' => 'media_management'],
        'access'          => 'user',
        'workspaces'      => 'live',
        'iconIdentifier'  => 'filefix-cleanup',
        // Locallang file: title, description and short description (mlang_* keys), translated in de.locallang_mod.xlf
        'labels'          => 'LLL:EXT:filefix/Resources/Private/Language/locallang_mod.xlf',
        'extensionName'        => 'Filefix',
        'navigationComponent'  => '@typo3/backend/tree/file-storage-browser',
        'routes'               => [
            '_default' => [
                'target' => \Anubit\Filefix\Controller\FileCleanupController::class . '::indexAction',
            ],
            // Read-only duplicate report, opened from the file list toolbar (route filefix_cleanup.duplicates)
            'duplicates' => [
                'target' => \Anubit\Filefix\Controller\DuplicatesController::class . '::indexAction',
            ],
            // Read-only oversized image report, opened from the file list toolbar (route filefix_cleanup.oversized)
            'oversized' => [
                'target' => \Anubit\Filefix\Controller\OversizedController::class . '::indexAction',
            ],
        ],
    ],
];
