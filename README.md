# Judicial Chambers

**One workspace. Every case. Every hearing.**

Judicial Chambers is a judge-centred platform for digital chambers, hearings and courtroom transcription. It is designed for the Judiciary of Zambia and other African courts.

* **PHP / MySQL application** (`app/`): the judge's workspace. It holds cases, digital bundles, the calendar, staff and permissions, transcript review, certification and the audit trail.
* **Node.js transcription service** (`transcription/`): secure courtroom audio capture over WSS, encrypted as it arrives, with live and batch speech-to-text. The engine is AssemblyAI or a local one, chosen per language.

**Security first.** All sensitive data is encrypted at rest with AES-256-GCM:

* database fields;
* every document and recording, each under its own key;
* sessions;
* the browser's offline audio buffer.

Every hop is encrypted in transit with TLS 1.2/1.3: browser, WSS, service-to-service and MySQL. See [docs/SECURITY.md](docs/SECURITY.md).

> AI assists; the judge decides. Machine transcripts are always drafts until a judge certifies them against the recording.

## Features

| Module | What it does |
| --- | --- |
| Judge's dashboard | Today's hearings, hearings awaiting confirmation, judgments reserved, tasks, recent cases |
| Staff portal | The judge invites marshals, clerks, judicial assistants, researchers and court reporters, and chooses each person's permissions. Staff see only the cases assigned to them. |
| Case management | Civil, criminal, constitutional, commercial, appellate and family cases, with parties, advocates, status and sealing |
| Digital case bundles | Pleadings, affidavits, submissions, exhibits, draft orders and private judgment drafts. Files are encrypted on upload and integrity-checked. |
| Judicial calendar | Week view and printable cause list. Staff propose hearings, the judge confirms, adjourns or completes them. |
| Courtroom transcription | Browser recording console with speaker buttons, bookmarks, a live draft transcript and offline buffering. The original is sealed and tamper-evident. |
| Transcript review | Click any passage to replay the audio, search, correct text (the machine version is kept) and add missing passages. The judge certifies; export is printable. |
| Private notes | Deliberations encrypted under a separate key, visible only to the judge and people the judge grants access to |
| Tasks | Assign work to chambers staff, per case or in general |
| Audit | A hash-chained, append-only log of every access and change, with one-click verification |

## Quick start (development)

Requirements: PHP 8.2+ with `pdo_sqlite` (or `pdo_mysql`), `openssl`, `mbstring`, `fileinfo`, `curl`; Node.js 20+.

```bash
# 1. PHP application
cd app
cp .env.example .env
php bin/jc keys:generate      # → put the output in KEYRING=, and its id (before ':') in ACTIVE_KEY_ID=
php bin/jc secret:generate    # → SERVICE_HMAC_SECRET=
# In .env for local development set:
#   APP_ENV=development
#   DB_DSN="sqlite:/absolute/path/jc.sqlite"
#   STORAGE_PATH=/absolute/path/storage
#   TRANSCRIPTION_PUBLIC_URL=ws://127.0.0.1:8090
#   TRANSCRIPTION_INTERNAL_URL=http://127.0.0.1:8090
php bin/jc migrate
php bin/jc demo:seed          # demo judge, marshal and court reporter
php -S 127.0.0.1:8080 -t public bin/dev-router.php

# 2. Transcription service (another terminal)
cd transcription
npm install
cp .env.example .env
# Copy KEYRING, ACTIVE_KEY_ID and SERVICE_HMAC_SECRET from app/.env. Then set:
#   APP_ENV=development
#   HOST=127.0.0.1
#   PORT=8090
#   PHP_INTERNAL_URL=http://127.0.0.1:8080
#   STORAGE_PATH=<same as the app>
#   ALLOWED_ORIGINS=http://127.0.0.1:8080
#   ASR_PROVIDER=mock   (or assemblyai with ASSEMBLYAI_API_KEY)
npm start
```

Open http://127.0.0.1:8080 and sign in as `judge@demo.local` with the password printed by `demo:seed`. You will be asked to enrol an authenticator app.

To exercise the recording pipeline without a microphone, run:

```bash
node transcription/scripts/simulate-courtroom.js <ws-url> <token> 30 --drop
```

Get the token from the recording console's `/recordings/{id}/token` endpoint.

## Speech recognition

Set `ASR_PROVIDER` in `transcription/.env`. You can route individual hearing languages to a different engine with `ASR_LANGUAGE_ROUTES`.

| Engine | Where audio goes | Notes |
| --- | --- | --- |
| `assemblyai` | AssemblyAI cloud (**external processor**) | Real-time draft in the console (Universal-Streaming), plus a full-hearing pass after sealing with speaker diarization. Remote transcripts are deleted after retrieval. Requires `EXTERNAL_ASR_APPROVED=true` in production; see [SECURITY.md §5](docs/SECURITY.md). |
| `whisper-cpp` | Stays on the host | [whisper.cpp](https://github.com/ggml-org/whisper.cpp) |
| `http` | Stays in the court network | Any self-hosted OpenAI-compatible endpoint. It receives `language` as `en`, `bem`, `nya`, `toi` or `loz`, so it suits a local-language model. |
| `mock` | Nowhere | Placeholder text for development |

**Speaker names.** Diarized voices ("Speaker A", "Speaker B") are named from the speaker buttons the court reporter taps during the hearing. Each voice takes the label that covers most of its speaking time. Reviewers confirm or correct the names.

**Languages.** AssemblyAI covers English but not Bemba, Nyanja, Tonga or Lozi. Hearings in those languages are recorded and sealed but not sent to AssemblyAI. Route them to a local-language engine, for example:

```
ASR_PROVIDER=assemblyai
ASR_LANGUAGE_ROUTES=bem:http,nya:http,toi:http,loz:http
```

Measure real accuracy on court audio before offering any language as supported.

## Tests

```bash
cd app && php tests/run.php                # 28 tests: crypto, TOTP, audit chain, access control, recordings…
cd transcription && npm test               # 18 tests: file format, crash-resume, WSS protocol, PHP⇄Node interop, AssemblyAI (mocked)
```

The PHP suite can also run against MySQL/MariaDB. Set `TEST_MYSQL_DSN`, `TEST_MYSQL_USER`, `TEST_MYSQL_PASS` and `TEST_MYSQL_CA`, and point them at a throwaway database.

## Production

See [docs/SECURITY.md](docs/SECURITY.md) for the pre-production checklist, and `deploy/` for:

* nginx TLS termination and WSS proxying;
* MySQL TLS and InnoDB encryption;
* least-privilege DB accounts;
* a hardened systemd unit.

With `APP_ENV=production`:

* the PHP application refuses plain HTTP, and refuses MySQL connections without TLS;
* the transcription service refuses to start without TLS certificates and an HTTPS callback URL.

Architecture and roadmap: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
