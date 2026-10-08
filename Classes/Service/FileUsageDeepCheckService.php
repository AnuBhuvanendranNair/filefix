<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Anubit\Filefix\Utility\Labels;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Live check whether a single file is used anywhere in the database, before it is deleted by hand:
 *   1. file references (sys_file_reference), every workspace and language
 *   2. reference index (sys_refindex): relation fields, links in RTE/link fields, form definitions
 *   3. text search in every text column of every table: public path, combined identifier,
 *      t3://file?uid=N and the legacy file:N link syntax
 * Technical tables (caches, logs, history, processed files, own metadata) are skipped and reported.
 */
class FileUsageDeepCheckService
{
    /** Tables that never hold a real usage; matched with fnmatch() */
    private const EXCLUDED_TABLES = [
        'cache_*', 'cf_*', 'sys_log', 'sys_history', 'sys_file', 'sys_file_metadata',
        'sys_file_processedfile', 'sys_refindex', 'sys_registry', 'sys_lockedrecords',
        'be_sessions', 'fe_sessions', 'tx_filefix_*',
    ];
    private const MAX_TEXT_MATCHES = 50;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {}

    public function check(int $fileUid): array
    {
        $file = $this->getFileRow($fileUid);
        if ($file === null) {
            return ['found' => false];
        }

        // Same file indexed more than once: deleting removes the physical file for all records
        $otherRecords = $this->findOtherRecordsOfSameFile($file);
        $references = $this->findReferences($fileUid);
        $indexEntries = $this->findReferenceIndexEntries($fileUid);
        $needles = $this->getNeedles($file);
        [$textMatches, $searchedTables, $skippedTables, $truncated] = $this->searchText($fileUid, $needles);

        // Usages by deleted records do not keep a file alive, but are listed to make the verdict transparent
        $activeUsages = count($otherRecords)
            + count(array_filter($references, static fn(array $r): bool => !$r['parentDeleted']))
            + count(array_filter($indexEntries, static fn(array $r): bool => !$r['deleted']))
            + count(array_filter($textMatches, static fn(array $r): bool => !$r['deleted']));

        return [
            'found'          => true,
            'uid'            => $fileUid,
            'identifier'     => (string)$file['identifier'],
            'safeToDelete'   => $activeUsages === 0,
            'activeUsages'   => $activeUsages,
            'otherRecords'   => $otherRecords,
            'references'     => $references,
            'indexEntries'   => $indexEntries,
            'textMatches'    => $textMatches,
            'textTruncated'  => $truncated,
            'needles'        => array_values($needles),
            'searchedTables' => $searchedTables,
            'skippedTables'  => $skippedTables,
        ];
    }

    private function getFileRow(int $fileUid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid', 'storage', 'identifier', 'identifier_hash')
            ->from('sys_file')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($fileUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Other sys_file records pointing to the same physical file (same storage and identifier).
     * Seen in real installations after broken re-indexing; each one counts as a usage.
     */
    private function findOtherRecordsOfSameFile(array $file): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'identifier')
            ->from('sys_file')
            ->where(
                $qb->expr()->eq('storage', $qb->createNamedParameter((int)$file['storage'], ParameterType::INTEGER)),
                $qb->expr()->eq('identifier_hash', $qb->createNamedParameter((string)$file['identifier_hash'])),
                $qb->expr()->neq('uid', $qb->createNamedParameter((int)$file['uid'], ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(static fn(array $row): array => [
            'table'       => 'sys_file',
            'field'       => 'identifier',
            'recordUid'   => (int)$row['uid'],
            'recordTitle' => Labels::get('deep.indexedAgain', (string)$row['identifier']),
            'path'        => (string)$row['identifier'],
            'deleted'     => false,
            'kind'        => Labels::get('deep.kind.indexedTwice'),
        ], $rows);
    }

    private function findReferences(int $fileUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'tablenames', 'fieldname', 'uid_foreign', 'sys_language_uid', 't3ver_wsid', 'hidden')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('uid_local', $qb->createNamedParameter($fileUid, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(function (array $row): array {
            $record = $this->getRecordInfo((string)$row['tablenames'], (int)$row['uid_foreign']);
            return [
                'table'         => (string)$row['tablenames'],
                'field'         => (string)$row['fieldname'],
                'recordUid'     => (int)$row['uid_foreign'],
                'recordTitle'   => $record['title'],
                'parentDeleted' => $record['deleted'],
                'language'      => (int)$row['sys_language_uid'],
                'workspace'     => (int)$row['t3ver_wsid'],
                'hidden'        => (bool)$row['hidden'],
            ];
        }, $rows);
    }

    /**
     * Every reference index entry pointing to the file, except the ones already covered above:
     * sys_file_reference.uid_local (step 1) and the file's own metadata record.
     */
    private function findReferenceIndexEntries(int $fileUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('tablename', 'recuid', 'field', 'softref_key', 'workspace')
            ->from('sys_refindex')
            ->where(
                $qb->expr()->eq('ref_table', $qb->createNamedParameter('sys_file')),
                $qb->expr()->eq('ref_uid', $qb->createNamedParameter($fileUid, ParameterType::INTEGER)),
                $qb->expr()->notIn('tablename', $qb->createNamedParameter(['sys_file_reference', 'sys_file_metadata'], \Doctrine\DBAL\ArrayParameterType::STRING))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(function (array $row): array {
            $record = $this->getRecordInfo((string)$row['tablename'], (int)$row['recuid']);
            return [
                'table'       => (string)$row['tablename'],
                'field'       => (string)$row['field'],
                'recordUid'   => (int)$row['recuid'],
                'recordTitle' => $record['title'],
                'deleted'     => $record['deleted'],
                'kind'        => (string)$row['softref_key'] !== '' ? (string)$row['softref_key'] : Labels::get('deep.kind.relation'),
                'workspace'   => (int)$row['workspace'],
            ];
        }, $rows);
    }

    /**
     * Strings that identify the file in text: label => LIKE needle.
     *
     * @return array<string, string>
     */
    private function getNeedles(array $file): array
    {
        $identifier = (string)$file['identifier'];
        $storageUid = (int)$file['storage'];
        $basePath = trim((string)($this->storageRepository->findByUid($storageUid)?->getConfiguration()['basePath'] ?? 'fileadmin'), '/');
        $needles = [
            'path'                => $basePath . $identifier,
            'combined identifier' => $storageUid . ':' . $identifier,
            't3:// link'          => 't3://file?uid=' . (int)$file['uid'],
            'file: link'          => 'file:' . (int)$file['uid'],
        ];
        $encodedPath = $basePath . implode('/', array_map('rawurlencode', explode('/', $identifier)));
        if ($encodedPath !== $needles['path']) {
            $needles['path (URL-encoded)'] = $encodedPath;
        }
        return $needles;
    }

    /**
     * @return array{0: array, 1: int, 2: string[], 3: bool}
     */
    private function searchText(int $fileUid, array $needles): array
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $schemaManager = $connection->createSchemaManager();
        $matches = [];
        $searchedTables = 0;
        $skippedTables = [];
        $truncated = false;

        foreach ($schemaManager->listTableNames() as $table) {
            if ($this->isExcluded($table)) {
                $skippedTables[] = $table;
                continue;
            }
            $columns = [];
            $hasUid = false;
            foreach ($schemaManager->listTableColumns($table) as $column) {
                $type = $column->getType();
                if ($column->getName() === 'uid') {
                    $hasUid = true;
                }
                if ($type instanceof StringType || $type instanceof TextType || $type instanceof JsonType) {
                    $columns[] = $column->getName();
                }
            }
            if ($columns === []) {
                continue;
            }
            $searchedTables++;

            $qb = $connection->createQueryBuilder();
            // Hidden, scheduled and deleted records count too (deleted ones are labelled, not dropped)
            $qb->getRestrictions()->removeAll();
            $conditions = [];
            foreach ($columns as $columnName) {
                foreach ($needles as $needle) {
                    $conditions[] = $qb->expr()->like(
                        $columnName,
                        $qb->createNamedParameter('%' . $connection->escapeLikeWildcards($needle) . '%')
                    );
                }
            }
            $rows = $qb->select(...array_merge($hasUid ? ['uid'] : [], $columns))
                ->from($table)
                ->where($qb->expr()->or(...$conditions))
                ->setMaxResults(self::MAX_TEXT_MATCHES)
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                foreach ($columns as $columnName) {
                    $kinds = $this->matchKinds((string)($row[$columnName] ?? ''), $needles, $fileUid);
                    if ($kinds === []) {
                        continue;
                    }
                    $recordUid = $hasUid ? (int)$row['uid'] : 0;
                    $record = $recordUid > 0 ? $this->getRecordInfo($table, $recordUid) : ['title' => '', 'deleted' => false];
                    $matches[] = [
                        'table'       => $table,
                        'field'       => $columnName,
                        'recordUid'   => $recordUid,
                        'recordTitle' => $record['title'],
                        'deleted'     => $record['deleted'],
                        'kind'        => implode(', ', $kinds),
                    ];
                    if (count($matches) >= self::MAX_TEXT_MATCHES) {
                        return [$matches, $searchedTables, $skippedTables, true];
                    }
                }
            }
        }
        return [$matches, $searchedTables, $skippedTables, $truncated];
    }

    /**
     * Exact check of a LIKE hit: uid links must not continue with a digit (file:12 is not file:123),
     * paths must not continue with further path characters (foo.jpg is not foo.jpg.bak).
     */
    private function matchKinds(string $value, array $needles, int $fileUid): array
    {
        $kinds = [];
        foreach ($needles as $label => $needle) {
            $pattern = str_contains($label, 'link')
                ? '/(?<![a-z])' . preg_quote($needle, '/') . '(?!\d)/i'
                : '/' . preg_quote($needle, '/') . '(?![\w.\-])/u';
            if (preg_match($pattern, $value)) {
                $kinds[] = $label;
            }
        }
        return $kinds;
    }

    /**
     * @return array{title: string, deleted: bool}
     */
    private function getRecordInfo(string $table, int $uid): array
    {
        if (!isset($GLOBALS['TCA'][$table]) || $uid <= 0) {
            return ['title' => '', 'deleted' => false];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return ['title' => '[record not found]', 'deleted' => true];
        }
        $deleteField = (string)($GLOBALS['TCA'][$table]['ctrl']['delete'] ?? '');
        return [
            'title'   => BackendUtility::getRecordTitle($table, $row),
            'deleted' => $deleteField !== '' && (bool)($row[$deleteField] ?? false),
        ];
    }

    private function isExcluded(string $table): bool
    {
        foreach (self::EXCLUDED_TABLES as $pattern) {
            if (fnmatch($pattern, $table)) {
                return true;
            }
        }
        return false;
    }
}
