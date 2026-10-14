<?php use JC\Service\RecordingService; ?>
<div class="mb-2">
    <label class="form-label small" for="up-audio">Audio file</label>
    <input class="form-control" type="file" id="up-audio" name="audio" required accept="audio/*,video/mp4,video/webm,.m4a,.mp3,.wav,.ogg,.opus,.flac,.amr,.aac">
    <div class="form-text">MP3, M4A, WAV, OGG, FLAC, AMR or MP4, up to <?= (int) JC\Core\Config::int('MAX_UPLOAD_MB', 100) ?> MB. Encrypted the moment it is stored.</div>
</div>
<div class="mb-2">
    <label class="form-label small" for="up-lang">Language</label>
    <select class="form-select" id="up-lang" name="language">
        <?php foreach (RecordingService::LANGUAGES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
    </select>
</div>
<div class="mb-3">
    <label class="form-label small" for="up-label">Description (optional)</label>
    <input class="form-control" id="up-label" name="label" maxlength="200" placeholder="e.g. Dictated notes on submissions">
</div>
<button class="btn btn-dark w-100" type="submit"><i class="bi bi-cloud-arrow-up"></i> Upload and transcribe</button>
<p class="small text-muted mt-2 mb-0">The audio is sent to <?= e(RecordingService::processorNotice()) ?> for transcription. The text is a draft until it is certified.</p>
