<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Security\FileCrypto;
use JC\Service\CaseService;
use JC\Service\DocumentService;

final class DocumentController extends Controller
{
    public function store(Request $request): Response
    {
        $this->auth()->authorize('documents.upload');
        $case = $this->app->service(CaseService::class)->requireAccessible((int) $request->param('id'));
        $back = "/cases/{$case['id']}?tab=documents";
        return $this->attempt($back, function () use ($case, $request, $back): Response {
            $file = $request->files['document'] ?? null;
            if (!is_array($file)) {
                throw new \InvalidArgumentException('Choose a file to upload.');
            }
            $id = $this->app->service(DocumentService::class)->upload($case, $file, $request->post, $this->auth()->id());
            $this->app->log('document.uploaded', 'document', $id, ['case_id' => (int) $case['id'], 'category' => $request->input('category')]);
            return $this->redirect($back, 'Document encrypted and added to the case bundle.');
        });
    }

    public function download(Request $request): Response
    {
        [$doc] = $this->load((int) $request->param('id'));
        $docs = $this->app->service(DocumentService::class);
        $path = $docs->path($doc);
        $dek = $docs->dataKey($doc);
        $this->app->log('document.downloaded', 'document', (int) $doc['id'], ['case_id' => (int) $doc['case_id']]);

        $inline = $request->input('inline') === '1' && in_array($doc['mime_type'], ['application/pdf', 'image/png', 'image/jpeg', 'text/plain'], true);
        $name = preg_replace('/[^\w.\- ]+/u', '_', (string) $doc['original_name']) ?: 'document';

        $response = Response::stream(static function () use ($path, $dek): void {
            FileCrypto::decrypt($path, $dek, static function (string $chunk): void {
                echo $chunk;
            });
        });
        $response->headers['Content-Type'] = $doc['mime_type'];
        $response->headers['Content-Length'] = (string) FileCrypto::plaintextSize($path);
        $response->headers['Content-Disposition'] = ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string) $doc['original_name']);
        $response->headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";
        return $response;
    }

    public function verify(Request $request): Response
    {
        [$doc, $case] = $this->load((int) $request->param('id'));
        $ok = $this->app->service(DocumentService::class)->verify($doc);
        $this->app->log('document.integrity_checked', 'document', (int) $doc['id'], ['ok' => $ok]);
        return $this->redirect(
            "/cases/{$case['id']}?tab=documents",
            $ok ? 'Integrity verified: the document is unchanged since upload.' : 'WARNING: the stored document failed its integrity check.',
            $ok ? 'success' : 'danger'
        );
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function load(int $id): array
    {
        $doc = $this->app->service(DocumentService::class)->find($id) ?? throw new HttpException(404);
        $case = $this->app->service(CaseService::class)->requireAccessible((int) $doc['case_id']);
        if ((int) $doc['confidential'] === 1 && !$this->auth()->can('notes.private')) {
            throw new HttpException(404);
        }
        return [$doc, $case];
    }
}
