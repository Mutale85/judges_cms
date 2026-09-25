<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\HttpException;
use JC\Core\Request;
use JC\Core\Response;
use JC\Service\CaseService;
use JC\Service\NoteService;

final class NoteController extends Controller
{
    public function store(Request $request): Response
    {
        $this->auth()->authorize('notes.private');
        $case = $this->app->service(CaseService::class)->requireAccessible((int) $request->param('id'));
        $back = "/cases/{$case['id']}?tab=notes";
        return $this->attempt($back, function () use ($case, $request, $back): Response {
            $id = $this->app->service(NoteService::class)->create((int) $case['id'], $this->auth()->id(), (string) $request->input('title'), (string) ($request->post['body'] ?? ''));
            $this->app->log('note.created', 'note', $id, ['case_id' => (int) $case['id']]);
            return $this->redirect($back, 'Private note saved (encrypted with the judicial-notes key).');
        });
    }

    public function update(Request $request): Response
    {
        $this->auth()->authorize('notes.private');
        $notes = $this->app->service(NoteService::class);
        $note = $notes->find((int) $request->param('id')) ?? throw new HttpException(404);
        $case = $this->app->service(CaseService::class)->requireAccessible((int) $note['case_id']);
        $back = "/cases/{$case['id']}?tab=notes";
        return $this->attempt($back, function () use ($notes, $note, $request, $back): Response {
            $notes->update((int) $note['id'], (string) $request->input('title'), (string) ($request->post['body'] ?? ''));
            $this->app->log('note.updated', 'note', (int) $note['id']);
            return $this->redirect($back, 'Note updated.');
        });
    }
}
