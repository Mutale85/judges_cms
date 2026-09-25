<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;
use JC\Core\Config;
use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Security\FileCrypto;

/**
 * Court recordings and their transcripts.
 *
 * Audio is captured by the browser and streamed over WSS to the Node
 * transcription service, which encrypts it as it arrives (the same JCF1 format
 * as documents) and reports transcript segments and the final integrity data
 * back here over signed HTTPS requests.
 *
 * A recording is an original: once finalised its hash, key and storage
 * location can never change (enforced by database triggers). Transcripts start
 * as machine drafts, may be corrected by authorised staff (the machine text is
 * always kept), and only the judge can certify them, which freezes them.
 */
final class RecordingService
{
    public const SAMPLE_RATE = 16000;

    public const LANGUAGES = [
        'en' => 'English',
        'bem' => 'Bemba (experimental)',
        'nya' => 'Nyanja (experimental)',
        'toi' => 'Tonga (experimental)',
        'loz' => 'Lozi (experimental)',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string,mixed> $hearing
     * @param array<string,mixed> $case
     */
    public function start(array $hearing, array $case, int $userId, string $language): int
    {
        if (!isset(self::LANGUAGES[$language])) {
            $language = 'en';
        }
        $id = $this->app->db()->insert('recordings', [
            'hearing_id' => (int) $hearing['id'],
            'case_id' => (int) $case['id'],
            'chambers_id' => (int) $case['chambers_id'],
            'started_by' => $userId,
            'status' => 'pending',
            'sample_rate' => self::SAMPLE_RATE,
            'language' => $language,
            'transcript_status' => 'none',
            'created_at' => now_utc(),
        ]);
        if (in_array($hearing['status'], ['proposed', 'confirmed'], true)) {
            $this->app->db()->update('hearings', ['status' => 'in_progress', 'updated_at' => now_utc()], ['id' => (int) $hearing['id']]);
        }
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->db()->fetch('SELECT * FROM recordings WHERE id = ?', [$id]);
    }

    /**
     * Loads a recording and checks the current user can access its case.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public function requireAccessible(int $id): array
    {
        $rec = $this->find($id);
        if ($rec === null) {
            throw new HttpException(404, 'Recording not found.');
        }
        $case = $this->app->service(CaseService::class)->requireAccessible((int) $rec['case_id']);
        return [$rec, $case];
    }

    /** @return list<array<string,mixed>> */
    public function forHearing(int $hearingId): array
    {
        return $this->app->db()->fetchAll('SELECT * FROM recordings WHERE hearing_id = ? ORDER BY id', [$hearingId]);
    }

    /** @return list<array<string,mixed>> */
    public function forCase(int $caseId): array
    {
        return $this->app->db()->fetchAll('SELECT * FROM recordings WHERE case_id = ? ORDER BY id DESC', [$caseId]);
    }

    /** Capability token the browser presents to the transcription service. */
    public function issueCaptureToken(array $rec, int $userId): string
    {
        return $this->app->serviceAuth()->issueToken([
            'sub' => $userId,
            'rec' => (int) $rec['id'],
            'scope' => 'record',
            'lang' => $rec['language'],
            'sr' => (int) $rec['sample_rate'],
        ], 300);
    }

    // --- Called by the transcription service (signed) ------------------------

    public function markStarted(int $id): void
    {
        $this->app->db()->run(
            "UPDATE recordings SET status = 'recording', started_at = COALESCE(started_at, ?) WHERE id = ? AND status IN ('pending', 'recording')",
            [now_utc(), $id]
        );
    }

    public function markFailed(int $id, string $reason): void
    {
        // A capture failure fails the recording; a transcription failure on a finalised
        // recording leaves the (intact) audio available for another attempt.
        $this->app->db()->run("UPDATE recordings SET status = 'failed' WHERE id = ? AND sha256 IS NULL", [$id]);
        $this->app->db()->run("UPDATE recordings SET status = 'completed' WHERE id = ? AND sha256 IS NOT NULL", [$id]);
        $rec = $this->find($id);
        $this->app->audit()->record('recording.failed', null, $rec ? (int) $rec['chambers_id'] : null, 'recording', $id, ['reason' => mb_substr($reason, 0, 500)]);
    }

    /**
     * Upserts machine transcript segments. Segments a person has already
     * corrected are never overwritten by the machine.
     *
     * @param list<array<string,mixed>> $segments
     */
    public function ingestSegments(int $recordingId, array $segments, bool $replaceMachine = false): int
    {
        $rec = $this->find($recordingId) ?? throw new HttpException(404);
        if ($rec['transcript_status'] === 'certified') {
            throw new HttpException(409, 'Transcript is certified and read-only.');
        }
        $db = $this->app->db();
        $cipher = $this->app->cipher();
        return $db->transaction(function () use ($db, $cipher, $recordingId, $segments, $replaceMachine, $rec): int {
            $humanRanges = [];
            if ($replaceMachine) {
                $db->run("DELETE FROM transcript_segments WHERE recording_id = ? AND source = 'machine' AND edited_by IS NULL", [$recordingId]);
                $humanRanges = $db->fetchAll('SELECT start_ms, end_ms FROM transcript_segments WHERE recording_id = ?', [$recordingId]);
            }
            $count = 0;
            foreach ($segments as $s) {
                $seq = (int) ($s['seq'] ?? -1);
                $text = trim((string) ($s['text'] ?? ''));
                if ($seq < 0 || $text === '') {
                    continue;
                }
                // A fresh machine pass never duplicates a passage a person has corrected or added.
                $mid = intdiv((int) ($s['start_ms'] ?? 0) + (int) ($s['end_ms'] ?? 0), 2);
                foreach ($humanRanges as $r) {
                    if ($mid >= (int) $r['start_ms'] && $mid <= (int) $r['end_ms']) {
                        continue 2;
                    }
                }
                $existing = $db->fetch('SELECT id, edited_by FROM transcript_segments WHERE recording_id = ? AND seq = ?', [$recordingId, $seq]);
                $row = $cipher->encryptRow('transcript_segments', [
                    'start_ms' => max(0, (int) ($s['start_ms'] ?? 0)),
                    'end_ms' => max(0, (int) ($s['end_ms'] ?? 0)),
                    'speaker' => ($s['speaker'] ?? '') !== '' ? mb_substr((string) $s['speaker'], 0, 120) : null,
                    'text' => $text,
                    'machine_text' => $text,
                    'confidence' => isset($s['confidence']) ? max(0, min(1, (float) $s['confidence'])) : null,
                ]);
                if ($existing === null) {
                    $db->insert('transcript_segments', $row + [
                        'recording_id' => $recordingId,
                        'seq' => $seq,
                        'source' => 'machine',
                        'created_at' => now_utc(),
                    ]);
                } elseif ($existing['edited_by'] === null) {
                    $db->update('transcript_segments', $row, ['id' => $existing['id']]);
                } else {
                    continue;
                }
                $count++;
            }
            if ($count > 0 && $rec['transcript_status'] === 'none') {
                $db->update('recordings', ['transcript_status' => 'draft'], ['id' => $recordingId]);
            }
            return $count;
        });
    }

    public function addBookmark(int $recordingId, int $atMs, string $label, ?int $userId): int
    {
        $label = trim($label) !== '' ? mb_substr(trim($label), 0, 200) : 'Bookmark';
        return $this->app->db()->insert('recording_bookmarks', $this->app->cipher()->encryptRow('recording_bookmarks', [
            'recording_id' => $recordingId,
            'at_ms' => max(0, $atMs),
            'label' => $label,
            'created_by' => $userId,
            'created_at' => now_utc(),
        ]));
    }

    /**
     * Records the final, tamper-evident state of a recording after checking
     * that the encrypted file really decrypts to the reported hash.
     *
     * @param array<string,mixed> $data
     */
    public function finalize(int $id, array $data): void
    {
        $rec = $this->find($id) ?? throw new HttpException(404);
        if ($rec['sha256'] !== null) {
            if (hash_equals((string) $rec['sha256'], (string) ($data['sha256'] ?? ''))) {
                return; // idempotent retry
            }
            throw new HttpException(409, 'Recording already finalised with different content.');
        }
        $storageKey = (string) ($data['storage_key'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $storageKey)) {
            throw new HttpException(400, 'Invalid storage key.');
        }
        $wrapped = (string) ($data['wrapped_key'] ?? '');
        $path = $this->pathFor($storageKey);
        $dek = $this->app->crypto()->unwrapKey($wrapped, 'recordings:' . $storageKey, 'recording-kek');
        $actual = FileCrypto::verify($path, $dek);
        if (!hash_equals($actual, (string) ($data['sha256'] ?? ''))) {
            throw new HttpException(422, 'Recording hash does not match the stored file.');
        }
        $bytes = FileCrypto::plaintextSize($path);
        $this->app->db()->update('recordings', [
            'status' => 'completed',
            'storage_key' => $storageKey,
            'wrapped_key' => $wrapped,
            'sha256' => $actual,
            'chunk_chain' => preg_match('/^[a-f0-9]{64}$/', (string) ($data['chunk_chain'] ?? '')) ? $data['chunk_chain'] : null,
            'bytes' => $bytes,
            'duration_ms' => intdiv($bytes * 1000, 2 * (int) $rec['sample_rate']),
            'started_at' => $rec['started_at'] ?? now_utc(),
            'ended_at' => now_utc(),
            'integrity_verified_at' => now_utc(),
        ], ['id' => $id]);
        $this->app->audit()->record('recording.finalised', (int) $rec['started_by'], (int) $rec['chambers_id'], 'recording', $id, [
            'sha256' => $actual,
            'bytes' => $bytes,
            'chunk_chain' => $data['chunk_chain'] ?? null,
        ]);
    }

    // --- Transcript review ---------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function segments(int $recordingId): array
    {
        return $this->app->cipher()->decryptRows('transcript_segments', $this->app->db()->fetchAll(
            'SELECT * FROM transcript_segments WHERE recording_id = ? ORDER BY start_ms, seq',
            [$recordingId]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function bookmarks(int $recordingId): array
    {
        return $this->app->cipher()->decryptRows('recording_bookmarks', $this->app->db()->fetchAll(
            'SELECT * FROM recording_bookmarks WHERE recording_id = ? ORDER BY at_ms',
            [$recordingId]
        ));
    }

    public function editSegment(array $rec, int $segmentId, string $text, string $speaker, int $userId): void
    {
        if ($rec['transcript_status'] === 'certified') {
            throw new HttpException(409, 'This transcript has been certified and can no longer be edited.');
        }
        $seg = $this->app->db()->fetch('SELECT id FROM transcript_segments WHERE id = ? AND recording_id = ?', [$segmentId, (int) $rec['id']]);
        if ($seg === null) {
            throw new HttpException(404);
        }
        $this->app->db()->update('transcript_segments', $this->app->cipher()->encryptRow('transcript_segments', [
            'text' => trim($text),
            'speaker' => trim($speaker) !== '' ? mb_substr(trim($speaker), 0, 120) : null,
        ]) + ['edited_by' => $userId, 'edited_at' => now_utc()], ['id' => $segmentId]);
        if ($rec['transcript_status'] === 'draft' || $rec['transcript_status'] === 'none') {
            $this->app->db()->update('recordings', ['transcript_status' => 'under_review'], ['id' => (int) $rec['id']]);
        }
    }

    public function addManualSegment(array $rec, int $startMs, int $endMs, string $speaker, string $text, int $userId): void
    {
        if ($rec['transcript_status'] === 'certified') {
            throw new HttpException(409, 'This transcript has been certified and can no longer be edited.');
        }
        $seq = (int) $this->app->db()->value('SELECT COALESCE(MAX(seq), 0) FROM transcript_segments WHERE recording_id = ?', [(int) $rec['id']]) + 1000000;
        $this->app->db()->insert('transcript_segments', $this->app->cipher()->encryptRow('transcript_segments', [
            'recording_id' => (int) $rec['id'],
            'seq' => $seq,
            'start_ms' => max(0, $startMs),
            'end_ms' => max($startMs, $endMs),
            'speaker' => $speaker !== '' ? $speaker : null,
            'text' => $text,
            'source' => 'manual',
            'edited_by' => $userId,
            'edited_at' => now_utc(),
            'created_at' => now_utc(),
        ]));
    }

    /**
     * Certifies the transcript as the official record. The certification hash
     * is a keyed MAC over the recording hash and every segment, so any later
     * change to either is detectable.
     */
    public function certify(array $rec, int $userId): string
    {
        if ($rec['sha256'] === null) {
            throw new \InvalidArgumentException('The recording must be finalised before its transcript can be certified.');
        }
        if ($rec['transcript_status'] === 'certified') {
            throw new \InvalidArgumentException('This transcript is already certified.');
        }
        if (!$this->verifyIntegrity($rec)) {
            throw new \RuntimeException('The recording failed its integrity check and cannot be certified.');
        }
        $keyId = $this->app->crypto()->ring()->activeId();
        $hash = $this->certificationHash($rec, $keyId);
        $this->app->db()->update('recordings', [
            'transcript_status' => 'certified',
            'certified_by' => $userId,
            'certified_at' => now_utc(),
            'certification_hash' => $hash,
            'certification_key_id' => $keyId,
        ], ['id' => (int) $rec['id']]);
        return $hash;
    }

    public function verifyCertification(array $rec): bool
    {
        if ($rec['transcript_status'] !== 'certified' || $rec['certification_hash'] === null) {
            return false;
        }
        return hash_equals((string) $rec['certification_hash'], $this->certificationHash($rec, (string) $rec['certification_key_id']));
    }

    public function verifyIntegrity(array $rec): bool
    {
        if ($rec['sha256'] === null) {
            return false;
        }
        try {
            $ok = hash_equals((string) $rec['sha256'], FileCrypto::verify($this->pathFor((string) $rec['storage_key']), $this->dataKey($rec)));
        } catch (\Throwable) {
            $ok = false;
        }
        if ($ok) {
            $this->app->db()->update('recordings', ['integrity_verified_at' => now_utc()], ['id' => (int) $rec['id']]);
        }
        return $ok;
    }

    private function certificationHash(array $rec, string $keyId): string
    {
        $lines = [$rec['id'], $rec['sha256']];
        foreach ($this->segments((int) $rec['id']) as $s) {
            $lines[] = implode("\x1f", [$s['seq'], $s['start_ms'], $s['end_ms'], $s['speaker'] ?? '', $s['text']]);
        }
        return $this->app->crypto()->mac(implode("\x1e", $lines), 'transcript-certification', $keyId);
    }

    // --- Audio playback ------------------------------------------------------

    /**
     * Streams the decrypted recording as a WAV file, honouring HTTP Range
     * requests so reviewers can jump to any transcript timestamp. Only the
     * chunks covering the requested range are decrypted.
     */
    public function audioResponse(array $rec, Request $request): Response
    {
        if ($rec['sha256'] === null) {
            throw new HttpException(404, 'The recording is not available yet.');
        }
        $path = $this->pathFor((string) $rec['storage_key']);
        $dek = $this->dataKey($rec);
        $pcmSize = FileCrypto::plaintextSize($path);
        $header = self::wavHeader($pcmSize, (int) $rec['sample_rate']);
        $total = strlen($header) + $pcmSize;

        $start = 0;
        $end = $total - 1;
        $status = 200;
        $range = $request->header('Range');
        if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $start = max(0, $total - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                $end = $m[2] !== '' ? min((int) $m[2], $total - 1) : $total - 1;
            }
            if ($start > $end || $start >= $total) {
                $r = new Response('', 416);
                $r->headers['Content-Range'] = "bytes */{$total}";
                return $r;
            }
            $status = 206;
        }

        $response = Response::stream(static function () use ($header, $start, $end, $path, $dek): void {
            $headerLen = strlen($header);
            if ($start < $headerLen) {
                echo substr($header, $start, min($end, $headerLen - 1) - $start + 1);
            }
            if ($end >= $headerLen) {
                FileCrypto::decrypt($path, $dek, static function (string $chunk): void {
                    echo $chunk;
                    flush();
                }, max(0, $start - $headerLen), $end - $headerLen);
            }
        }, $status);
        $response->headers['Content-Type'] = 'audio/wav';
        $response->headers['Accept-Ranges'] = 'bytes';
        $response->headers['Content-Length'] = (string) ($end - $start + 1);
        if ($status === 206) {
            $response->headers['Content-Range'] = "bytes {$start}-{$end}/{$total}";
        }
        return $response;
    }

    public static function wavHeader(int $dataBytes, int $sampleRate, int $channels = 1, int $bits = 16): string
    {
        $blockAlign = $channels * $bits / 8;
        return 'RIFF' . pack('V', 36 + $dataBytes) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, $sampleRate, $sampleRate * $blockAlign, $blockAlign, $bits)
            . 'data' . pack('V', $dataBytes);
    }

    // --- Re-transcription ----------------------------------------------------

    /** Asks the transcription service to (re)process a finalised recording. */
    public function requestTranscription(array $rec): void
    {
        $base = rtrim((string) Config::require('TRANSCRIPTION_INTERNAL_URL'), '/');
        $path = '/jobs';
        $body = json_encode([
            'recording_id' => (int) $rec['id'],
            'storage_key' => $rec['storage_key'],
            'wrapped_key' => $rec['wrapped_key'],
            'sample_rate' => (int) $rec['sample_rate'],
            'language' => $rec['language'],
        ], JSON_THROW_ON_ERROR);
        $headers = ['Content-Type: application/json'];
        foreach ($this->app->serviceAuth()->signRequest('POST', $path, $body) as $k => $v) {
            $headers[] = "{$k}: {$v}";
        }
        $ch = curl_init($base . $path);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => Config::isProduction() ? CURLPROTO_HTTPS : (CURLPROTO_HTTPS | CURLPROTO_HTTP),
        ];
        if ($ca = Config::get('TRANSCRIPTION_CA_FILE')) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $result = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false || $code >= 300) {
            throw new \RuntimeException('Transcription service rejected the job: ' . ($error ?: "HTTP {$code}"));
        }
        $this->app->db()->update('recordings', ['status' => 'processing'], ['id' => (int) $rec['id']]);
    }

    public function pathFor(string $storageKey): string
    {
        return $this->app->storagePath('recordings') . '/' . $storageKey . '.jcf';
    }

    private function dataKey(array $rec): string
    {
        return $this->app->crypto()->unwrapKey((string) $rec['wrapped_key'], 'recordings:' . $rec['storage_key'], 'recording-kek');
    }
}
