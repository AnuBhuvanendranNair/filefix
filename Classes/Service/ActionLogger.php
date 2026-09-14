<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

class ActionLogger
{
    public const ACTION_DELETE_DIRECT = 'delete_direct';
    public const ACTION_QUARANTINE    = 'quarantine';
    public const ACTION_FLUSH         = 'flush';
    public const ACTION_RESTORE       = 'restore';
    public const ACTION_SKIP          = 'skip';
    public const ACTION_RECHECK       = 'recheck';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function log(
        string $action,
        string $identifier,
        string $name,
        int $storageUid,
        int $fileUid,
        string $details = ''
    ): void {
        $beUser     = $GLOBALS['BE_USER'] ?? null;
        $beUserUid  = (int)($beUser?->user['uid'] ?? 0);
        $beUserName = (string)($beUser?->user['username'] ?? 'system');

        $this->connectionPool->getConnectionForTable('tx_filefix_log')->insert(
            'tx_filefix_log',
            [
                'created_at'   => time(),
                'action'       => $action,
                'identifier'   => $identifier,
                'name'         => $name,
                'storage_uid'  => $storageUid,
                'file_uid'     => $fileUid,
                'be_user_uid'  => $beUserUid,
                'be_user_name' => $beUserName,
                'details'      => $details,
            ]
        );
    }
}
