<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\Config;
use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Service\CaseService;
use JC\Service\HearingService;
use JC\Service\RecordingService;

final class RecordingController extends Controller
{
    private function recordings(): RecordingService
    {
        return $this->app->service(RecordingService::class);
    }

    /** Creates a recording for a hearing and opens the capture console. */
    public function start(Request $request): Response
    {
        $this->auth()->authorize('recordings.record');
        $hearing = $this->app->service(HearingService::class)->find((int) $request->param('id')) ?? throw new HttpException(404);
        $case = $this->app->service(CaseService::class)->requireAccessible((int) $hearing['case_id']);
        if (in_array($hearing['status'], ['cancelled', 'adjourned', 'proposed'], true)) {
            return $this->redirect("/hearings/{$hearing['id']}", 'Only confirmed hearings can be recorded.', 'danger');
        }
        if (!$this->app->service(\JC\Service\SubscriptionService::class)->includesTranscription($this->auth()->chambers() ?? [])) {
            return $this->redirect("/hearings/{$hearing['id']}", 'Your current plan does not include recording and transcription. The workspace owner can change plan under Billing.', 'danger');
        }
        $id = $this->recordings()->start($hearing, $case, $this->auth()->id(), (string) $request->input('language', 'en'));
        $this->app->log('recording.created', 'recording', $id, ['hearing_id' => (int) $hearing['id'], 'case_id' => (int) $case['id']]);
        return $this->redirect("/recordings/{$id}/capture");
    }

    public function capture(Request $request): Response
    {
        $this->auth()->authorize('recordings.record');
        [$rec, $case] = $this->recordings()->requireAccessible((int) $request->param('id'));
        if ($rec['sha256'] !== null) {
            return $this->redirect("/recordings/{$rec['id']}");
        }
        $hearing = $this->app->service(HearingService::class)->find((int) $rec['hearing_id']);
        return $this->render('recordings/capture', [
            'title' => 'Courtroom recording',
            'rec' => $rec,
            'case' => $case,
            'hearing' => $hearing,
            'wsUrl' => rtrim((string) Config::get('TRANSCRIPTION_PUBLIC_URL', ''), '/') . '/capture',
        ]);
    }

    /** Short-lived capability token for the WSS connection (fetched by recorder.js). */
    public function token(Request $request): Response
    {
        $this->auth()->authorize('recordings.record');
        [$rec] = $this->recordings()->requireAccessible((int) $request->param('id'));
        if ($rec['sha256'] !== null || $rec['status'] === 'failed') {
            return Response::json(['error' => 'This recording is closed.'], 409);
        }
        $this->app->log('recording.token_issued', 'recording', (int) $rec['id']);
        return Response::json(['token' => $this->recordings()->issueCaptureToken($rec, $this->auth()->id())]);
    }

    public function show(Request $request): Response
    {
        [$rec, $case] = $this->recordings()->requireAccessible((int) $request->param('id'));
        $this->app->log('transcript.viewed', 'recording', (int) $rec['id']);
        return $this->render('recordings/show', [
            'title' => 'Transcript review',
            'rec' => $rec,
            'case' => $case,
            'hearing' => $this->app->service(HearingService::class)->find((int) $rec['hearing_id']),
            'segments' => $this->recordings()->segments((int) $rec['id']),
            'bookmarks' => $this->recordings()->bookmarks((int) $rec['id']),
            'certificationValid' => $rec['transcript_status'] === 'certified' ? $this->recordings()->verifyCertification($rec) : null,
        ]);
    }

    public function audio(Request $request): Response
    {
        [$rec] = $this->recordings()->requireAccessible((int) $request->param('id'));
        if ($request->header('Range') === null || str_starts_with((string) $request->header('Range'), 'bytes=0-')) {
            $this->app->log('recording.played', 'recording', (int) $rec['id']);
        }
        return $this->recordings()->audioResponse($rec, $request);
    }

    public function editSegment(Request $request): Response
    {
        $this->auth()->authorize('transcripts.edit');
        [$rec] = $this->recordings()->requireAccessible((int) $request->param('id'));
        $segmentId = (int) $request->param('segment');
        $this->recordings()->editSegment($rec, $segmentId, (string) $request->input('text'), (string) $request->input('speaker'), $this->auth()->id());
        $this->app->log('transcript.segment_edited', 'recording', (int) $rec['id'], ['segment_id' => $segmentId]);
        if ($request->header('Accept') === 'application/json') {
            return Response::json(['ok' => true]);
        }
        return $this->redirect("/recordings/{$rec['id']}#seg-{$segmentId}", 'Correction saved.');
    }

    public function addSegment(Request $request): Response
    {
        $this->auth()->authorize('transcripts.edit');
        [$rec] = $this->recordings()->requireAccessible((int) $request->param('id'));
        return $this->attempt("/recordings/{$rec['id']}", function () use ($rec, $request): Response {
            $text = trim((string) $request->input('text'));
            if ($text === '') {
                throw new \InvalidArgumentException('Enter the text of the passage.');
            }
            $start = self::parseClock((string) $request->input('start'));
            $end = self::parseClock((string) $request->input('end')) ?: $start;
            $this->recordings()->addManualSegment($rec, $start, $end, (string) $request->input('speaker'), $text, $this->auth()->id());
            $this->app->log('transcript.segment_added', 'recording', (int) $rec['id']);
            return $this->redirect("/recordings/{$rec['id']}", 'Passage added.');
        });
    }

    public function bookmark(Request $request): Response
    {
        $this->auth()->authorize('transcripts.edit');
        [$rec] = $this->recordings()->requireAccessible((int) $request->param('id'));
        $this->recordings()->addBookmark((int) $rec['id'], self::parseClock((string) $request->input('at')), (string) $request->input('label'), $this->auth()->id());
        $this->app->log('recording.bookmarked', 'recording', (int) $rec['id']);
        return $this->redirect("/recordings/{$rec['id']}", 'Bookmark added.');
    }

    public function certify(Request $request): Response
    {
        $this->auth()->authorize('transcripts.certify');
        [$rec] = $this->recordings()->requireAccessible((int) $request->param('id'));
        if ($request->input('confirm') !== 'yes') {
            return $this->redirect("/recordings/{$rec['id']}", 'Tick the confirmation box to certify the transcript.', 'danger');
        }
        try {
            $hash = $this->recordings()->certify($rec, $this->auth()->id());
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->redirect("/recordings/{$rec['id']}", $e->getMessage(), 'danger');
        }
        $this->app->log('transcript.certified', 'recording', (int) $rec['id'], ['certification_hash' => $hash, 'recording_sha256' => $rec['sha256']]);
        return $this->redirect("/recordings/{$rec['id']}", 'Transcript certified. It is now read-only.');
    }

    public function verify(Request $request): Response
    {
        [$rec] = $this->recordings()->requireAccessible((int) $request->param('id'));
        $ok = $this->recordings()->verifyIntegrity($rec);
        $this->app->log('recording.integrity_checked', 'recording', (int) $rec['id'], ['ok' => $ok]);
        return $this->redirect(
            "/recordings/{$rec['id']}",
            $ok ? 'Integrity verified: the original recording is unchanged (SHA-256 matches).' : 'WARNING: the recording failed its integrity check.',
            $ok ? 'success' : 'danger'
        );
    }

    public function retranscribe(Request $request): Response
    {
        $this->auth()->authorize('transcripts.edit');
        [$rec] = $this->recordings()->requireAccessible((int) $request->param('id'));
        if ($rec['sha256'] === null || $rec['transcript_status'] === 'certified') {
            return $this->redirect("/recordings/{$rec['id']}", 'This recording cannot be re-transcribed.', 'danger');
        }
        try {
            $this->recordings()->requestTranscription($rec);
        } catch (\RuntimeException $e) {
            return $this->redirect("/recordings/{$rec['id']}", $e->getMessage(), 'danger');
        }
        $this->app->log('transcript.requested', 'recording', (int) $rec['id']);
        return $this->redirect("/recordings/{$rec['id']}", 'Queued for transcription. Corrected passages will be kept.');
    }

    /** Transcription hub: recent recordings and dictation upload. */
    public function index(Request $request): Response
    {
        $this->auth()->authorize('cases.view');
        $canUpload = $this->auth()->can('recordings.record');
        return $this->render('recordings/index', [
            'title' => 'Transcription',
            'recordings' => $this->recordings()->recent(100),
            'cases' => $canUpload ? array_values(array_filter(
                $this->app->service(CaseService::class)->listAccessible(),
                static fn ($c) => !in_array($c['status'], ['closed'], true)
            )) : [],
            'canUpload' => $canUpload,
            'preselectCase' => $request->int('case_id'),
        ]);
    }

    /** Upload a dictation or a recording made elsewhere, against a case (and optionally a hearing). */
    public function upload(Request $request): Response
    {
        $this->auth()->authorize('recordings.record');
        $back = (string) ($request->input('back') ?: '/transcription');
        if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
            $back = '/transcription';
        }
        return $this->attempt($back, function () use ($request, $back): Response {
            if (!$this->app->service(\JC\Service\SubscriptionService::class)->includesTranscription($this->auth()->chambers() ?? [])) {
                throw new \InvalidArgumentException('Your current plan does not include transcription. The workspace owner can change plan under Billing.');
            }
            $case = $this->app->service(CaseService::class)->requireAccessible((int) $request->input('case_id'));
            $hearing = null;
            if ($hearingId = $request->int('hearing_id')) {
                $hearing = $this->app->service(HearingService::class)->find($hearingId) ?? throw new HttpException(404);
            }
            $result = $this->recordings()->upload($case, $hearing, $request->files['audio'] ?? [], (string) $request->input('language', 'en'), $this->auth()->id(), (string) $request->input('label'));
            $this->app->log('recording.uploaded', 'recording', $result['id'], ['case_id' => (int) $case['id'], 'queued' => $result['queued']]);
            if (!$result['queued']) {
                $this->app->session()->flash('warning', 'The recording is stored securely, but ' . $result['error'] . '. Use "Re-run machine transcription" once the service is available.');
                return $this->redirect("/recordings/{$result['id']}");
            }
            return $this->redirect("/recordings/{$result['id']}", 'Uploaded and encrypted. The transcript will appear here when it is ready — refresh in a minute or two.');
        });
    }

    public function export(Request $request): Response
    {
        [$rec, $case] = $this->recordings()->requireAccessible((int) $request->param('id'));
        $word = $request->input('format') === 'doc';
        $this->app->log('transcript.exported', 'recording', (int) $rec['id'], ['format' => $word ? 'word' : 'print']);
        if ($word) {
            // Word opens HTML documents saved with a .doc name, keeping headings and tables.
            $html = $this->app->view()->render('recordings/export_word', [
                'rec' => $rec,
                'case' => $case,
                'hearing' => $this->app->service(HearingService::class)->find((int) $rec['hearing_id']),
                'segments' => $this->recordings()->segments((int) $rec['id']),
                'chambers' => $this->auth()->chambers(),
            ], null);
            $response = new Response("\xEF\xBB\xBF" . $html);
            $response->headers['Content-Type'] = 'application/msword; charset=utf-8';
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $case['case_number']) . '-transcript-' . (int) $rec['id'] . '.doc';
            $response->headers['Content-Disposition'] = 'attachment; filename="' . $name . '"';
            return $response;
        }
        return $this->render('recordings/export', [
            'title' => 'Transcript',
            'rec' => $rec,
            'case' => $case,
            'hearing' => $this->app->service(HearingService::class)->find((int) $rec['hearing_id']),
            'segments' => $this->recordings()->segments((int) $rec['id']),
            'chambers' => $this->auth()->chambers(),
            'certificationValid' => $rec['transcript_status'] === 'certified' ? $this->recordings()->verifyCertification($rec) : null,
        ], 'layout/print');
    }

    /** Accepts "hh:mm:ss", "mm:ss" or seconds; returns milliseconds. */
    private static function parseClock(string $value): int
    {
        $parts = array_map('intval', explode(':', trim($value)));
        $seconds = 0;
        foreach ($parts as $p) {
            $seconds = $seconds * 60 + max(0, $p);
        }
        return $seconds * 1000;
    }
}
