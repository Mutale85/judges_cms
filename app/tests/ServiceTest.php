<?php

declare(strict_types=1);

use JC\Core\App;
use JC\Core\HttpException;
use JC\Security\FileCrypto;
use JC\Service\CaseService;
use JC\Service\DocumentService;
use JC\Service\HearingService;
use JC\Service\NoteService;
use JC\Service\RecordingService;
use JC\Service\UserService;

/** Builds chambers with a judge, a marshal and a clerk, and signs in as $who. */
function chambersFixture(): array
{
    $app = freshApp();
    $users = $app->service(UserService::class);
    $pw = 'Correct-Horse-9-Battery';
    $judge = $users->create('judge@t.zm', 'Judge J', $pw);
    $chambers = $users->createChambers('Chambers', 'High Court', null, null, $judge);
    $marshal = $users->create('marshal@t.zm', 'Marshal M', $pw);
    $users->addMember($chambers, $marshal, 'marshal', $judge);
    $clerk = $users->create('clerk@t.zm', 'Clerk C', $pw);
    $users->addMember($chambers, $clerk, 'clerk', $judge, ['cases.view', 'cases.all']);
    return [$app, $chambers, $judge, $marshal, $clerk];
}

function actAs(App $app, int $userId, int $chambersId): App
{
    // A fresh App shares the database but has its own Auth state.
    $next = new App($app->db(), ring());
    $_SESSION = ['user_id' => $userId, 'chambers_id' => $chambersId];
    $next->auth()->load();
    return $next;
}

test('passwords: strength rules, hashing and lockout', function (): void {
    $app = freshApp();
    $users = $app->service(UserService::class);
    throws(fn () => $users->create('a@b.zm', 'A', 'short'), InvalidArgumentException::class);
    throws(fn () => $users->create('a@b.zm', 'A', 'alllowercaseletters'), InvalidArgumentException::class);
    $id = $users->create('a@b.zm', 'A', 'Good-Password-2026');
    check($users->attempt('A@B.zm', 'Good-Password-2026') !== null, 'case-insensitive e-mail lookup');
    for ($i = 0; $i < UserService::MAX_FAILED_LOGINS; $i++) {
        same(null, $users->attempt('a@b.zm', 'wrong'));
    }
    throws(fn () => $users->attempt('a@b.zm', 'Good-Password-2026'), RuntimeException::class, 'locked out');
    $row = $app->db()->fetch('SELECT email, full_name, password_hash FROM users WHERE id = ?', [$id]);
    check(!str_contains($row['email'], '@'), 'e-mail stored encrypted');
    check(str_starts_with($row['password_hash'], '$argon2id$') || str_starts_with($row['password_hash'], '$2y$'), 'modern password hash');
});

test('case access: judge sees all, staff only assigned; sealed cases need explicit assignment', function (): void {
    [$app, $chambers, $judge, $marshal, $clerk] = chambersFixture();
    $asJudge = actAs($app, $judge, $chambers);
    $cases = $asJudge->service(CaseService::class);
    $open = $cases->create($chambers, $judge, ['case_number' => '2026/HP/1', 'title' => 'Open', 'case_type' => 'civil']);
    $sealed = $cases->create($chambers, $judge, ['case_number' => '2026/HP/2', 'title' => 'Sealed', 'case_type' => 'civil', 'sealed' => true]);
    same(2, count($cases->listAccessible()));

    $asMarshal = actAs($app, $marshal, $chambers);
    same(0, count($asMarshal->service(CaseService::class)->listAccessible()), 'marshal has no assignments yet');
    throws(fn () => $asMarshal->service(CaseService::class)->requireAccessible($open), HttpException::class);

    $cases->assign($open, $marshal, $judge);
    $asMarshal = actAs($app, $marshal, $chambers);
    same(['2026/HP/1'], array_column($asMarshal->service(CaseService::class)->listAccessible(), 'case_number'));

    $asClerk = actAs($app, $clerk, $chambers);
    same(['2026/HP/1'], array_column($asClerk->service(CaseService::class)->listAccessible(), 'case_number'), '"all cases" still excludes sealed');
    throws(fn () => $asClerk->service(CaseService::class)->requireAccessible($sealed), HttpException::class);

    $cases->assign($sealed, $clerk, $judge);
    $asClerk = actAs($app, $clerk, $chambers);
    same(2, count($asClerk->service(CaseService::class)->listAccessible()));
});

test('case numbers are unique per chambers via the blind index, and stored encrypted', function (): void {
    [$app, $chambers, $judge] = chambersFixture();
    $cases = actAs($app, $judge, $chambers)->service(CaseService::class);
    $cases->create($chambers, $judge, ['case_number' => '2026/HPC/0142', 'title' => 'A v B', 'case_type' => 'commercial']);
    throws(fn () => $cases->create($chambers, $judge, ['case_number' => ' 2026/hpc/0142', 'title' => 'dup', 'case_type' => 'civil']), InvalidArgumentException::class);
    $raw = $app->db()->fetch('SELECT case_number, title FROM cases');
    check(!str_contains($raw['case_number'], '0142') && !str_contains($raw['title'], 'A v B'));
});

test('documents: encrypted upload, verification, confidential filtering and sealing', function (string $tmp): void {
    [$app, $chambers, $judge, $marshal] = chambersFixture();
    $asJudge = actAs($app, $judge, $chambers);
    $cases = $asJudge->service(CaseService::class);
    $caseId = $cases->create($chambers, $judge, ['case_number' => 'C/1', 'title' => 'T', 'case_type' => 'civil']);
    $cases->assign($caseId, $marshal, $judge);
    $case = $cases->find($caseId);

    $upload = static function (string $content) use ($tmp): array {
        $path = $tmp . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);
        return ['name' => 'affidavit.txt', 'tmp_name' => $path, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    };
    $docs = $asJudge->service(DocumentService::class);
    $public = $docs->upload($case, $upload('Affidavit of service'), ['category' => 'affidavit'], $judge);
    $private = $docs->upload($case, $upload('Draft judgment text'), ['category' => 'judgment_draft'], $judge);
    $doc = $docs->find($public);
    same('Affidavit of service', FileCrypto::decryptToString($docs->path($doc), $docs->dataKey($doc)));
    check($docs->verify($doc));

    $asMarshal = actAs($app, $marshal, $chambers);
    same([$public], array_map(fn ($d) => (int) $d['id'], $asMarshal->service(DocumentService::class)->forCase($caseId)), 'marshal cannot see private drafts');
    throws(fn () => $asMarshal->service(DocumentService::class)->upload($case, $upload('x'), ['category' => 'judgment_draft'], $marshal), HttpException::class);

    // Sealing re-wraps keys under the sealed-material key and moves files.
    $cases->setSealed($caseId, true);
    $doc = $docs->find($public);
    same('sealed-kek', $doc['key_purpose']);
    check(str_contains($docs->path($doc), '/sealed/'));
    same('Affidavit of service', FileCrypto::decryptToString($docs->path($doc), $docs->dataKey($doc)));
    same($private, (int) $docs->find($private)['id']);
});

test('private notes are encrypted under their own key purpose', function (): void {
    [$app, $chambers, $judge] = chambersFixture();
    $asJudge = actAs($app, $judge, $chambers);
    $caseId = $asJudge->service(CaseService::class)->create($chambers, $judge, ['case_number' => 'N/1', 'title' => 'T', 'case_type' => 'civil']);
    $id = $asJudge->service(NoteService::class)->create($caseId, $judge, 'Issues', 'The credibility of PW2 is doubtful.');
    $raw = $app->db()->fetch('SELECT body FROM judicial_notes WHERE id = ?', [$id]);
    throws(fn () => $app->crypto()->decrypt($raw['body'], 'judicial_notes.body', 'field'), JC\Security\CryptoException::class);
    same('The credibility of PW2 is doubtful.', $asJudge->service(NoteService::class)->find($id)['body']);
});

test('recordings: finalise, ingest, correct, certify, freeze', function (string $tmp): void {
    [$app, $chambers, $judge] = chambersFixture();
    $asJudge = actAs($app, $judge, $chambers);
    $caseId = $asJudge->service(CaseService::class)->create($chambers, $judge, ['case_number' => 'R/1', 'title' => 'T', 'case_type' => 'civil']);
    $case = $asJudge->service(CaseService::class)->find($caseId);
    $hid = $asJudge->service(HearingService::class)->propose($case, ['scheduled_at' => '2026-10-01T09:00', 'hearing_type' => 'trial'], $judge, true);
    $recs = $asJudge->service(RecordingService::class);
    $recId = $recs->start($asJudge->service(HearingService::class)->find($hid), $case, $judge, 'en');

    // Simulate the transcription service writing the encrypted original.
    $pcm = random_bytes(32000 * 3);
    $storageKey = bin2hex(random_bytes(20));
    $dek = FileCrypto::newDataKey();
    @mkdir($tmp . '/recordings', 0700, true);
    file_put_contents("{$tmp}/pcm", $pcm);
    FileCrypto::encryptFile("{$tmp}/pcm", $recs->pathFor($storageKey), $dek);
    $wrapped = $app->crypto()->wrapKey($dek, 'recordings:' . $storageKey, 'recording-kek');

    throws(fn () => $recs->finalize($recId, ['storage_key' => $storageKey, 'wrapped_key' => $wrapped, 'sha256' => str_repeat('0', 64)]), HttpException::class, 'wrong hash rejected');
    $recs->finalize($recId, ['storage_key' => $storageKey, 'wrapped_key' => $wrapped, 'sha256' => hash('sha256', $pcm)]);
    $rec = $recs->find($recId);
    same(3000, (int) $rec['duration_ms']);
    check($recs->verifyIntegrity($rec));

    $recs->ingestSegments($recId, [
        ['seq' => 0, 'start_ms' => 0, 'end_ms' => 1500, 'speaker' => 'Judge', 'text' => 'Counsel, you may proceed.'],
        ['seq' => 1, 'start_ms' => 1500, 'end_ms' => 3000, 'speaker' => 'Counsel', 'text' => 'Much obliged, My Lord.'],
    ]);
    $segs = $recs->segments($recId);
    $recs->editSegment($recs->find($recId), (int) $segs[0]['id'], 'Counsel, you may proceed with your submissions.', 'Judge', $judge);

    // A new machine pass must keep the human correction and not duplicate it.
    $recs->ingestSegments($recId, [
        ['seq' => 5000000, 'start_ms' => 0, 'end_ms' => 1500, 'text' => 'machine again'],
        ['seq' => 5000001, 'start_ms' => 1500, 'end_ms' => 3000, 'text' => 'Much obliged, my Lord.'],
    ], true);
    same(['Counsel, you may proceed with your submissions.', 'Much obliged, my Lord.'], array_column($recs->segments($recId), 'text'));

    $hash = $recs->certify($recs->find($recId), $judge);
    $rec = $recs->find($recId);
    same('certified', $rec['transcript_status']);
    same($hash, $rec['certification_hash']);
    check($recs->verifyCertification($rec));
    throws(fn () => $recs->editSegment($rec, (int) $segs[0]['id'], 'changed', '', $judge), HttpException::class);
    throws(fn () => $recs->ingestSegments($recId, [['seq' => 9, 'text' => 'late']]), HttpException::class);
    throws(fn () => $app->db()->run('UPDATE transcript_segments SET start_ms = 1'), PDOException::class, 'trigger freezes certified segments');
});

test('WAV header is valid for 16 kHz mono PCM', function (): void {
    $h = RecordingService::wavHeader(32000, 16000);
    same(44, strlen($h));
    same('RIFF', substr($h, 0, 4));
    same(32000 + 36, unpack('V', substr($h, 4, 4))[1]);
    same(32000, unpack('V', substr($h, 28, 4))[1], 'byte rate');
});
