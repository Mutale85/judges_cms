# Architecture

```
 Judges · Marshals · Clerks · Court reporters        (browser / tablet, Bootstrap 5)
        │ HTTPS                         │ WSS (audio frames)
        ▼                               ▼
┌──────────────────────────┐   signed HTTPS   ┌─────────────────────────────────┐
│  PHP application (app/)  │◀────────────────▶│ Node transcription (transcription/) │
│  · auth, MFA, permissions│   callbacks/jobs  │  · capture sessions, resume       │
│  · cases, bundles, notes │                   │  · streaming AES-GCM encryption   │
│  · calendar, tasks       │                   │  · job queue → speech engine      │
│  · transcript review     │                   │    (AssemblyAI / local, per lang) │
│  · audit, integrity      │                   └──────────────┬──────────────────┘
└──────┬─────────────┬─────┘                                  │
       │ TLS         │ ciphertext only                        │ ciphertext only
       ▼             ▼                                        ▼
   MySQL 8     STORAGE_PATH/  documents/  sealed/  recordings/ (shared volume)
```

## Why the split

Long hearings produce continuous audio and CPU-heavy speech recognition. Keeping this in a separate Node.js service means that:

* capture and transcription never slow down the judge's workspace;
* the PHP application stays the single source of truth and the only user interface. PHP "sees everything": segments, bookmarks and recording integrity data are all reported to it;
* the speech engine can run on a separate GPU host.

## Recording flow

1. An authorised user opens the **recording console** for a confirmed hearing. PHP creates a `recordings` row and issues a 5-minute capture token.
2. The browser converts microphone audio to 16 kHz mono PCM in an AudioWorklet and sends numbered 0.5 s frames over WSS.
3. The Node service writes the frames into a `JCF1` encrypted file with its own data key. Each frame is acknowledged only once it is in a sealed, fsync'd chunk. Until then the browser keeps it in its encrypted IndexedDB outbox.
4. **Live draft.** The engine for the hearing's language (`ASR_PROVIDER`, or `ASR_LANGUAGE_ROUTES` for that language) produces a draft while the hearing runs:
   * **AssemblyAI** streams the audio in real time. Each finished turn becomes a draft passage; in-progress words are shown in the console but never stored.
   * **Local engines** receive `LIVE_WINDOW_SECONDS` windows through the job queue.

   Passages are labelled with the speaker selected in the console, stored encrypted in PHP, and echoed to the console. Languages with no capable engine are recorded without a machine draft.
5. **Disconnects** are handled at three levels:
   * **Browser to service drops**: the browser reconnects and resumes from the first frame the service is missing.
   * **Service restarts**: a sidecar file holding the wrapped key and counters lets the service continue the same file.
   * **The courtroom never returns**: the recording is sealed automatically after `SESSION_GRACE_MINUTES`.
6. **Stop** closes the live stream (flushing the last turn), then seals the file with its final chunk and computes the SHA-256 and chunk hash chain. PHP verifies the file decrypts to that hash before accepting it, and records it in the audit chain.
7. Reviewers correct the draft while listening. Clicking any passage seeks the audio, which is served by HTTP Range requests that decrypt only the chunks needed. The judge then certifies the transcript, which freezes it.
8. **Full-hearing pass.** With AssemblyAI (`ASR_FINAL_PASS=true`), the sealed hearing is decrypted chunk by chunk and uploaded in one request. The resulting diarized transcript replaces the live draft, and diarized voices are named from the tapped speaker timeline. The remote transcript is then deleted.
9. *Re-run machine transcription* sends a signed job to Node. Node re-processes the sealed original and replaces unedited machine passages. Human corrections are kept.

## Code map

| Path | Purpose |
| --- | --- |
| `app/public/index.php` | Front controller |
| `app/routes.php` | All routes and their auth mode (`full`, `none`, `service`) |
| `app/src/Core/` | Kernel, router, request/response, sessions, database, migrator |
| `app/src/Security/` | Key ring, field and file encryption, audit chain, TOTP, service auth, transport security, permissions |
| `app/src/Service/` | Domain logic: cases, documents, hearings, tasks, notes, recordings, users |
| `app/src/Controller/` | HTTP controllers (thin) |
| `app/views/` | PHP templates (Bootstrap 5, no inline JS) |
| `app/public/assets/` | `recorder.js` (capture console), `pcm-worklet.js`, `transcript.js` (review), vendored Bootstrap |
| `app/database/migrations/` | Schema for MySQL and SQLite, including integrity triggers |
| `app/bin/jc` | CLI: keys, migrations, provisioning, rotation, verification |
| `transcription/src/server.js` | HTTPS/WSS server, `/capture`, `/jobs`, `/health` |
| `transcription/src/session.js` | One live recording: durability, resume, windows, sealing |
| `transcription/src/crypto.js` | Byte-compatible port of the PHP encryption |
| `transcription/src/providers/` | Speech engines and per-language routing: `assemblyai` (external), `whisper-cpp`, `http` (self-hosted), `mock` |
| `transcription/src/speakers.js` | Names diarized voices from the tapped speaker timeline |
| `deploy/` | nginx (TLS), MySQL (TLS + InnoDB encryption), systemd |

## Roadmap status

| Phase | Status in this codebase |
| --- | --- |
| 1 — Digital chambers | **Built**: judge & staff accounts, MFA, delegated permissions, case registration, parties, encrypted digital bundles, sealed cases, calendar and cause lists, hearing proposal/confirmation/adjournment, tasks, private notes, audit trail |
| 2 — Courtroom transcription | **Built**: secure browser capture with offline buffering, encrypted originals, live and batch speech-to-text, speaker labelling, bookmarks, correction with audio replay, certification, printable export |
| 3 — Judicial productivity | Next: ONLYOFFICE judgment editor, templates, AI summaries with citations to document/page/timestamp, full-text search of bundles (OCR) |
| 4 — Institutional integration | Next: registry/e-filing integration, configurable court hierarchies, institution administration UI, reporting |
