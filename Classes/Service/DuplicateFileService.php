<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Read-only report of duplicate files: files in one storage with identical content (sys_file.sha1),
 * with their file-level metadata, file references and soft references (links in text fields).
 *
 * Only physical copies that exist on disk count, sizes are taken from disk, each path once:
 * sys_file.missing / sys_file.size are not trusted (stale in copied or broken installations),
 * and several sys_file records can point to the same path (their usages are added up).
 * A group is listed when at least two copies exist on disk and one of them lies in the given
 * folder (or below); the other copies are looked up in the whole storage.
 */
class DuplicateFileService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FileCleanupService $fileCleanupService,
    ) {}

    /**
     * Summary and one page of groups with details, largest extra size first ($limit null = all).
     *
     * @return array{summary: array{groups: int, wasted: int, recordsNotOnDisk: int}, groups: array}
     */
    public function getReport(int $storageUid, string $folderPrefix = '', ?int $limit = null, int $offset = 0): array
    {
        $basePath = $this->fileCleanupService->getStorageBasePath($storageUid);
        $folder = $folderPrefix === '' ? '' : '/' . trim($folderPrefix, '/') . '/';
        $groups = [];
        $recordsNotOnDisk = 0;
        $diskCheck = [];

        $flush = function (string $sha1, array $paths) use (&$groups, $folder): void {
            // A duplicate needs at least two physical copies on disk
            if (count($paths) < 2) {
                return;
            }
            if ($folder !== '') {
                $inFolder = false;
                foreach ($paths as $path) {
                    if (str_starts_with($path['identifier'], $folder)) {
                        $inFolder = true;
                        break;
                    }
                }
                if (!$inFolder) {
                    return;
                }
            }
            $size = (int)reset($paths)['size'];
            $groups[] = ['sha1' => $sha1, 'copies' => count($paths), 'size' => $size, 'wasted' => (count($paths) - 1) * $size, 'paths' => $paths];
        };

        $currentSha1 = null;
        $paths = [];
        foreach ($this->fetchCandidates($storageUid, $folder) as $row) {
            if ($row['sha1'] !== $currentSha1) {
                if ($currentSha1 !== null) {
                    $flush($currentSha1, $paths);
                }
                $currentSha1 = $row['sha1'];
                $paths = [];
            }
            $hash = (string)$row['identifier_hash'];
            if (!isset($diskCheck[$hash])) {
                $absolutePath = $basePath . $row['identifier'];
                $diskCheck[$hash] = is_file($absolutePath) ? (int)filesize($absolutePath) : false;
            }
            if ($diskCheck[$hash] === false) {
                $recordsNotOnDisk++;
                continue;
            }
            $paths[$hash] ??= ['identifier' => (string)$row['identifier'], 'size' => $diskCheck[$hash], 'uids' => []];
            $paths[$hash]['uids'][] = (int)$row['uid'];
        }
        if ($currentSha1 !== null) {
            $flush($currentSha1, $paths);
        }

        usort($groups, static fn(array $a, array $b): int => [$b['wasted'], $a['sha1']] <=> [$a['wasted'], $b['sha1']]);
        return [
            'summary' => [
                'groups'           => count($groups),
                'wasted'           => array_sum(array_column($groups, 'wasted')),
                'recordsNotOnDisk' => $recordsNotOnDisk,
            ],
            'groups' => $this->getGroupDetails($limit === null ? $groups : array_slice($groups, $offset, $limit)),
        ];
    }

    /**
     * Copies per group, one entry per physical path. When several sys_file records point to the
     * same path, their usages are added up and the record with the most usages represents the copy.
     */
    private function getGroupDetails(array $groups): array
    {
        if ($groups === []) {
            return [];
        }
        $fileUids = [];
        foreach ($groups as $group) {
            foreach ($group['paths'] as $path) {
                array_push($fileUids, ...$path['uids']);
            }
        }
        $files = $this->findFilesByUids($fileUids);
        [$metadata, $metadataUids] = $this->findMetadata($fileUids);
        $references = $this->findReferences($fileUids);
        $softReferences = $this->countSoftReferences($fileUids);

        $result = [];
        foreach ($groups as $group) {
            $copies = [];
            foreach ($group['paths'] as $path) {
                $usage = static fn(int $uid): int => count($references[$uid] ?? []) + ($softReferences[$uid] ?? 0);
                $uids = $path['uids'];
                usort($uids, static fn(int $a, int $b): int => [$usage($b), $a] <=> [$usage($a), $b]);
                $uid = $uids[0];
                $file = $files[$uid] ?? ['name' => basename($path['identifier']), 'creation_date' => 0];
                $meta = $metadata[$uid] ?? ['title' => '', 'alternative' => '', 'description' => ''];
                $pathReferences = [];
                $pathSoftReferences = 0;
                foreach ($uids as $recordUid) {
                    array_push($pathReferences, ...($references[$recordUid] ?? []));
                    $pathSoftReferences += $softReferences[$recordUid] ?? 0;
                }
                $copies[] = [
                    'uid'            => $uid,
                    'identifier'     => $path['identifier'],
                    'name'           => (string)$file['name'],
                    'crdate'         => (int)$file['creation_date'],
                    'records'        => count($uids),
                    'metadata'       => $meta,
                    'hasMetadata'    => implode('', $meta) !== '',
                    'metadataUid'    => $metadataUids[$uid] ?? 0,
                    'references'     => $pathReferences,
                    'referenceCount' => count($pathReferences),
                    'softReferences' => $pathSoftReferences,
                    'isKeeper'       => false,
                ];
            }
            // Suggested keeper: most references (incl. soft references), then oldest file
            usort($copies, static fn(array $a, array $b): int =>
                [$b['referenceCount'] + $b['softReferences'], $a['crdate'], $a['uid']]
                <=> [$a['referenceCount'] + $a['softReferences'], $b['crdate'], $b['uid']]);
            $copies[0]['isKeeper'] = true;

            $usedCopies = array_filter($copies, static fn(array $c): bool => $c['referenceCount'] + $c['softReferences'] > 0);
            $distinctMetadata = array_unique(array_map(
                static fn(array $c): string => implode("\0", $c['metadata']),
                array_filter($copies, static fn(array $c): bool => $c['hasMetadata'])
            ));
            unset($group['paths']);
            $result[] = $group + [
                'copiesList'      => $copies,
                'usedCopies'      => count($usedCopies),
                'referenceTotal'  => array_sum(array_column($copies, 'referenceCount')),
                'hasSoftRefs'     => array_sum(array_column($copies, 'softReferences')) > 0,
                // Different file-level texts on copies: merging needs them copied into the references
                'metadataDiffers' => count($distinctMetadata) > 1,
                'firstUid'        => $copies[0]['uid'],
            ];
        }
        return $result;
    }

    /**
     * All records whose content (sha1) exists more than once in the storage, ordered by sha1.
     * With a folder: only groups with at least one record in that folder (or below), so that
     * only their records are checked on disk.
     */
    private function fetchCandidates(int $storageUid, string $folder): iterable
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_file');
        $params = ['storage' => $storageUid];
        $types = ['storage' => ParameterType::INTEGER];
        $folderSql = '';
        if ($folder !== '') {
            $folderSql = ' AND SUM(CASE WHEN d.identifier LIKE :folder THEN 1 ELSE 0 END) > 0';
            $params['folder'] = $connection->escapeLikeWildcards($folder) . '%';
            $types['folder'] = ParameterType::STRING;
        }
        $result = $connection->executeQuery(
            'SELECT f.uid, f.sha1, f.identifier, f.identifier_hash FROM sys_file f'
            . ' WHERE f.storage = :storage AND f.sha1 IN ('
            . 'SELECT d.sha1 FROM sys_file d WHERE d.storage = :storage AND d.sha1 <> \'\' GROUP BY d.sha1 HAVING COUNT(*) > 1'
            . $folderSql
            . ') ORDER BY f.sha1, f.uid',
            $params,
            $types
        );
        while ($row = $result->fetchAssociative()) {
            yield $row;
        }
    }

    /**
     * @return array<int, array{name: string, creation_date: int}>
     */
    private function findFilesByUids(array $fileUids): array
    {
        $rows = $this->connectionPool->getConnectionForTable('sys_file')->executeQuery(
            'SELECT uid, name, creation_date FROM sys_file WHERE uid IN (:uids)',
            ['uids' => $fileUids],
            ['uids' => ArrayParameterType::INTEGER]
        )->fetchAllAssociative();
        return array_column($rows, null, 'uid');
    }

    /**
     * File-level metadata in the default language, and the uid of that metadata record per file.
     *
     * @return array{0: array<int, array{title: string, alternative: string, description: string}>, 1: array<int, int>}
     */
    private function findMetadata(array $fileUids): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'file', 'title', 'alternative', 'description')
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->in('file', $qb->createNamedParameter($fileUids, ArrayParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $metadata = [];
        $metadataUids = [];
        foreach ($rows as $row) {
            $metadataUids[(int)$row['file']] = (int)$row['uid'];
            $metadata[(int)$row['file']] = [
                'title'       => trim((string)$row['title']),
                'alternative' => trim((string)$row['alternative']),
                'description' => trim((string)$row['description']),
            ];
        }
        return [$metadata, $metadataUids];
    }

    /**
     * Active file references with the record they belong to and the page they are on.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function findReferences(array $fileUids): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'uid_local', 'uid_foreign', 'tablenames', 'fieldname', 'pid', 'sys_language_uid', 'hidden', 't3ver_wsid', 'title', 'alternative', 'description')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->in('uid_local', $qb->createNamedParameter($fileUids, ArrayParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->orderBy('tablenames')
            ->addOrderBy('uid_foreign')
            ->executeQuery()
            ->fetchAllAssociative();

        $references = [];
        foreach ($rows as $row) {
            $table = (string)$row['tablenames'];
            $record = isset($GLOBALS['TCA'][$table]) ? BackendUtility::getRecord($table, (int)$row['uid_foreign']) : null;
            $pageUid = $table === 'pages' ? (int)$row['uid_foreign'] : (int)($record['pid'] ?? $row['pid']);
            $page = $pageUid > 0 ? BackendUtility::getRecord('pages', $pageUid, 'uid,title') : null;
            $references[(int)$row['uid_local']][] = [
                'uid'            => (int)$row['uid'],
                'table'          => $table,
                'tableLabel'     => $this->getTableLabel($table),
                'field'          => (string)$row['fieldname'],
                'recordUid'      => (int)$row['uid_foreign'],
                'recordTitle'    => $record !== null ? BackendUtility::getRecordTitle($table, $record) : '[record not found]',
                'recordExists'   => $record !== null,
                'pageUid'        => $pageUid,
                'pageTitle'      => (string)($page['title'] ?? ''),
                'language'       => (int)$row['sys_language_uid'],
                'hidden'         => (bool)$row['hidden'],
                'workspace'      => (int)$row['t3ver_wsid'],
                'ownAlternative' => trim((string)$row['alternative']) !== '',
                'ownTitle'       => trim((string)$row['title']) !== '',
                'ownDescription' => trim((string)$row['description']) !== '',
            ];
        }
        return $references;
    }

    /**
     * Soft references (e.g. t3://file links in RTE fields) from the reference index.
     *
     * @return array<int, int>
     */
    private function countSoftReferences(array $fileUids): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('ref_uid')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->from('sys_refindex')
            ->where(
                $qb->expr()->eq('ref_table', $qb->createNamedParameter('sys_file')),
                $qb->expr()->in('ref_uid', $qb->createNamedParameter($fileUids, ArrayParameterType::INTEGER)),
                $qb->expr()->neq('softref_key', $qb->createNamedParameter(''))
            )
            ->groupBy('ref_uid')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_column(array_map(static fn(array $r): array => ['uid' => (int)$r['ref_uid'], 'cnt' => (int)$r['cnt']], $rows), 'cnt', 'uid');
    }

    private function getTableLabel(string $table): string
    {
        $title = (string)($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table);
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = $languageService instanceof LanguageService ? $languageService->sL($title) : '';
        return $label !== '' ? $label : $table;
    }
}
