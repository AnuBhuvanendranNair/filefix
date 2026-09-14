<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Filefix',
    'description' => 'Fileadmin housekeeping toolkit: detect MIME/content mismatches, find and remove unused files.',
    'category' => 'be',
    'author' => 'Anu Bhuvanendran Nair',
    'author_email' => '',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.9.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
