<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title'         => 'File Quarantine Queue',
        'label'         => 'identifier',
        'label_alt'     => 'name,status',
        'readOnly'      => true,
        'rootLevel'     => -1,
        'iconfile'      => 'EXT:filefix/Resources/Public/Icons/module-filefix-quarantine.svg',
        'searchFields'  => 'identifier,name,reason,status,scan_id,error_message',
        'hideTable'     => true,
    ],
    'columns' => [
        'storage_uid'           => ['label' => 'Storage UID',           'config' => ['type' => 'number']],
        'file_uid'              => ['label' => 'sys_file UID',          'config' => ['type' => 'number']],
        'identifier'            => ['label' => 'FAL Identifier',        'config' => ['type' => 'input', 'readOnly' => true]],
        'name'                  => ['label' => 'File Name',             'config' => ['type' => 'input', 'readOnly' => true]],
        'mime_type'             => ['label' => 'MIME Type',             'config' => ['type' => 'input', 'readOnly' => true]],
        'size'                  => ['label' => 'Size (bytes)',          'config' => ['type' => 'number']],
        'sha1'                  => ['label' => 'SHA1 at scan',          'config' => ['type' => 'input', 'readOnly' => true]],
        'mtime'                 => ['label' => 'mtime at scan',         'config' => ['type' => 'number']],
        'absolute_path_snapshot'=> ['label' => 'Absolute path (scan)', 'config' => ['type' => 'input', 'readOnly' => true]],
        'quarantine_identifier' => ['label' => 'Quarantine path',      'config' => ['type' => 'input', 'readOnly' => true]],
        'reason'                => ['label' => 'Reason',               'config' => ['type' => 'input', 'readOnly' => true]],
        'status'                => ['label' => 'Status',               'config' => ['type' => 'input', 'readOnly' => true]],
        'scan_id'               => ['label' => 'Scan ID',              'config' => ['type' => 'input', 'readOnly' => true]],
        'created_at'            => ['label' => 'Created',              'config' => ['type' => 'number']],
        'updated_at'            => ['label' => 'Updated',              'config' => ['type' => 'number']],
        'last_checked_at'       => ['label' => 'Last checked',         'config' => ['type' => 'number']],
        'quarantined_at'        => ['label' => 'Quarantined at',       'config' => ['type' => 'number']],
        'flushed_at'            => ['label' => 'Flushed at',           'config' => ['type' => 'number']],
        'restored_at'           => ['label' => 'Restored at',          'config' => ['type' => 'number']],
        'metadata_json'         => ['label' => 'Metadata (JSON)',      'config' => ['type' => 'text',  'readOnly' => true]],
        'error_message'         => ['label' => 'Error',                'config' => ['type' => 'text',  'readOnly' => true]],
    ],
    'types' => [
        '1' => [
            'showitem' => 'storage_uid, file_uid, identifier, name, mime_type, size, sha1, reason, status, scan_id, created_at, error_message',
        ],
    ],
];
