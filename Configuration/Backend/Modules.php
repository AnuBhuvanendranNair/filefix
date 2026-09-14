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
        'labels'          => [
            'title'       => 'File Cleanup',
            'description' => 'Find and remove unused files and orphaned FAL records from fileadmin',
        ],
        'extensionName'        => 'Filefix',
        'navigationComponent'  => '@typo3/backend/tree/file-storage-browser',
        'routes'               => [
            '_default' => [
                'target' => \Anubit\Filefix\Controller\FileCleanupController::class . '::indexAction',
            ],
        ],
    ],
];
