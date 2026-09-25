<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Service\RecordingService;

/**
 * Endpoints called by the Node transcription service. Every request must carry
 * a valid HMAC signature, a fresh timestamp and an unused nonce, and in
 * production must arrive over TLS (enforced by TransportSecurity).
 */
final class InternalController extends Controller
{
    /** @return array<string,mixed> */
    private function verified(Request $request): array
    {
        $body = $request->body();
        $ok = $this->app->serviceAuth()->verifyRequest(
            $this->app->db(),
            $request->method,
            $request->path,
            $body,
            $request->header('X-JC-Timestamp'),
            $request->header('X-JC-Nonce'),
            $request->header('X-JC-Signature'),
        );
        if (!$ok) {
            throw new HttpException(401, 'Invalid service signature.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new HttpException(400, 'Invalid JSON body.');
        }
        return $data;
    }

    private function recording(Request $request): array
    {
        return $this->app->service(RecordingService::class)->find((int) $request->param('id')) ?? throw new HttpException(404);
    }

    public function started(Request $request): Response
    {
        $data = $this->verified($request);
        $rec = $this->recording($request);
        $this->app->service(RecordingService::class)->markStarted((int) $rec['id']);
        $this->app->audit()->record('recording.started', isset($data['user_id']) ? (int) $data['user_id'] : null, (int) $rec['chambers_id'], 'recording', (int) $rec['id']);
        return Response::json(['ok' => true]);
    }

    public function segments(Request $request): Response
    {
        $data = $this->verified($request);
        $rec = $this->recording($request);
        $segments = is_array($data['segments'] ?? null) ? $data['segments'] : [];
        $count = $this->app->service(RecordingService::class)->ingestSegments((int) $rec['id'], $segments, !empty($data['replace_machine']));
        return Response::json(['ok' => true, 'stored' => $count]);
    }

    public function bookmark(Request $request): Response
    {
        $data = $this->verified($request);
        $rec = $this->recording($request);
        $id = $this->app->service(RecordingService::class)->addBookmark(
            (int) $rec['id'],
            (int) ($data['at_ms'] ?? 0),
            (string) ($data['label'] ?? ''),
            isset($data['user_id']) ? (int) $data['user_id'] : null
        );
        return Response::json(['ok' => true, 'id' => $id]);
    }

    public function finalize(Request $request): Response
    {
        $data = $this->verified($request);
        $rec = $this->recording($request);
        $this->app->service(RecordingService::class)->finalize((int) $rec['id'], $data);
        return Response::json(['ok' => true]);
    }

    public function transcribed(Request $request): Response
    {
        $this->verified($request);
        $rec = $this->recording($request);
        $this->app->db()->run("UPDATE recordings SET status = 'completed' WHERE id = ? AND status = 'processing'", [(int) $rec['id']]);
        $this->app->audit()->record('transcript.machine_completed', null, (int) $rec['chambers_id'], 'recording', (int) $rec['id']);
        return Response::json(['ok' => true]);
    }

    public function failed(Request $request): Response
    {
        $data = $this->verified($request);
        $rec = $this->recording($request);
        $this->app->service(RecordingService::class)->markFailed((int) $rec['id'], (string) ($data['reason'] ?? 'unknown'));
        return Response::json(['ok' => true]);
    }
}
