<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;
use JC\Core\Config;
use JC\Core\HttpException;
use JC\Security\FileCrypto;

/**
 * Encrypted digital case bundle. Uploaded files are encrypted with a fresh
 * data key the moment they arrive and are never written to permanent storage
 * in plaintext. Documents of sealed cases live in a separate store under a
 * separate key purpose.
 */
final class DocumentService
{
    public const CATEGORIES = [
        'pleading' => 'Pleading',
        'affidavit' => 'Affidavit',
        'submission' => 'Written submission',
        'exhibit' => 'Exhibit',
        'authority' => 'List of authorities',
        'correspondence' => 'Correspondence',
        'order' => 'Order (issued)',
        'draft_order' => 'Draft order',
        'judgment_draft' => 'Judgment draft (private)',
        'other' => 'Other',
    ];

    public const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/rtf' => 'rtf',
        'text/plain' => 'txt',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/tiff' => 'tif',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'video/mp4' => 'mp4',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string,mixed> $case
     * @param array<string,mixed> $file   an entry from $_FILES
     * @param array<string,mixed> $meta
     */
    public function upload(array $case, array $file, array $meta, int $userId): int
    {
        $auth = $this->app->auth();
        $uploaded = PHP_SAPI === 'cli' || is_uploaded_file((string) ($file['tmp_name'] ?? ''));
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !$uploaded) {
            throw new \InvalidArgumentException('The file did not upload correctly.');
        }
        $max = Config::int('MAX_UPLOAD_MB', 100) * 1024 * 1024;
        if ((int) $file['size'] > $max) {
            throw new \InvalidArgumentException('The file is larger than the permitted maximum.');
        }
        $category = (string) ($meta['category'] ?? 'other');
        if (!isset(self::CATEGORIES[$category])) {
            throw new \InvalidArgumentException('Choose a document category.');
        }
        if ($category === 'draft_order' && !$auth->can('orders.draft')) {
            throw new HttpException(403, 'You are not permitted to prepare draft orders.');
        }
        $confidential = $category === 'judgment_draft' || !empty($meta['confidential']);
        if ($confidential && !$auth->can('notes.private')) {
            throw new HttpException(403, 'You are not permitted to file confidential judicial material.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: 'application/octet-stream';
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new \InvalidArgumentException("Files of type {$mime} are not accepted.");
        }

        $sealed = (int) $case['sealed'] === 1;
        $purpose = $sealed ? 'sealed-kek' : 'file-kek';
        $storageKey = bin2hex(random_bytes(20));
        $dir = $this->dirFor($sealed);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $dek = FileCrypto::newDataKey();
        $result = FileCrypto::encryptFile((string) $file['tmp_name'], $dir . '/' . $storageKey . '.jcf', $dek);
        @unlink((string) $file['tmp_name']);

        $title = trim((string) ($meta['title'] ?? ''));
        return $this->app->db()->insert('documents', $this->app->cipher()->encryptRow('documents', [
            'case_id' => (int) $case['id'],
            'category' => $category,
            'title' => $title !== '' ? $title : (string) $file['name'],
            'description' => ($meta['description'] ?? '') !== '' ? $meta['description'] : null,
            'original_name' => basename((string) $file['name']),
            'mime_type' => $mime,
            'size_bytes' => $result['bytes'],
            'storage_key' => $storageKey,
            'wrapped_key' => $this->app->crypto()->wrapKey($dek, 'documents:' . $storageKey, $purpose),
            'key_purpose' => $purpose,
            'sha256' => $result['sha256'],
            'confidential' => $confidential ? 1 : 0,
            'uploaded_by' => $userId,
            'created_at' => now_utc(),
        ]));
    }

    /** @return list<array<string,mixed>> */
    public function forCase(int $caseId): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT d.*, u.full_name AS uploader FROM documents d JOIN users u ON u.id = d.uploaded_by WHERE d.case_id = ? ORDER BY d.category, d.created_at',
            [$caseId]
        );
        $canConfidential = $this->app->auth()->can('notes.private');
        $out = [];
        foreach ($rows as $row) {
            if ((int) $row['confidential'] === 1 && !$canConfidential) {
                continue;
            }
            $uploader = $row['uploader'];
            $row = $this->app->cipher()->decryptRow('documents', $row);
            $row['uploader'] = $this->app->crypto()->decrypt($uploader, 'users.full_name');
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->cipher()->decryptRow('documents', $this->app->db()->fetch('SELECT * FROM documents WHERE id = ?', [$id]));
    }

    /** @param array<string,mixed> $doc */
    public function path(array $doc): string
    {
        return $this->dirFor($doc['key_purpose'] === 'sealed-kek') . '/' . $doc['storage_key'] . '.jcf';
    }

    /** @param array<string,mixed> $doc */
    public function dataKey(array $doc): string
    {
        return $this->app->crypto()->unwrapKey((string) $doc['wrapped_key'], 'documents:' . $doc['storage_key'], (string) $doc['key_purpose']);
    }

    /**
     * Verifies the stored file still decrypts and matches the hash recorded at upload.
     *
     * @param array<string,mixed> $doc
     */
    public function verify(array $doc): bool
    {
        try {
            return hash_equals((string) $doc['sha256'], FileCrypto::verify($this->path($doc), $this->dataKey($doc)));
        } catch (\Throwable) {
            return false;
        }
    }

    public function rekeyForCase(int $caseId, bool $sealed): void
    {
        $target = $sealed ? 'sealed-kek' : 'file-kek';
        $docs = $this->app->db()->fetchAll('SELECT * FROM documents WHERE case_id = ? AND key_purpose <> ?', [$caseId, $target]);
        foreach ($docs as $doc) {
            $dek = $this->dataKey($doc);
            $from = $this->path($doc);
            $toDir = $this->dirFor($sealed);
            if (!is_dir($toDir)) {
                mkdir($toDir, 0700, true);
            }
            $this->app->db()->update('documents', [
                'wrapped_key' => $this->app->crypto()->wrapKey($dek, 'documents:' . $doc['storage_key'], $target),
                'key_purpose' => $target,
            ], ['id' => $doc['id']]);
            if (is_file($from)) {
                rename($from, $toDir . '/' . $doc['storage_key'] . '.jcf');
            }
        }
    }

    private function dirFor(bool $sealed): string
    {
        return $this->app->storagePath($sealed ? 'sealed' : 'documents');
    }
}
