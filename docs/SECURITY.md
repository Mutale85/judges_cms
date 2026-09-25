# Security design

Encryption of data **at rest** and **in transit** is the primary security control of Judicial Chambers. Everything else (access control, audit, integrity) is layered on top of it.

## 1. Data at rest

### 1.1 Key hierarchy

```
KEYRING (master keys, 256-bit, from the environment / secrets manager / HSM)
 └─ HKDF-SHA256(master, salt="judicial-chambers-v1", info="jc/<purpose>")  → purpose subkeys
     ├─ field            case titles, numbers, parties, tasks, hearing notes, transcripts…
     ├─ judicial-notes   private judicial notes & deliberations (separate key)
     ├─ mfa              TOTP secrets
     ├─ audit            audit-log details and IP addresses
     ├─ session          PHP session files
     ├─ file-kek         wraps per-document data keys
     ├─ sealed-kek       wraps data keys of documents in sealed cases (separate key)
     ├─ recording-kek    wraps per-recording data keys
     ├─ blind-index      HMAC key for searchable indexes (never used to encrypt)
     └─ mac/*            HMAC keys for the audit chain and transcript certification
```

* Every **document** and **recording** has its own random 256-bit data key (DEK). Only the wrapped DEK is stored.
* Subkeys are derived identically in PHP (`app/src/Security/KeyRing.php`) and Node (`transcription/src/crypto.js`). The test suite checks that each side can decrypt what the other writes.

### 1.2 Field encryption (database)

`AES-256-GCM`, random 96-bit nonce, 128-bit tag. Format: `jc1:<keyId>:<base64(nonce | ciphertext | tag)>`.

The additional authenticated data is `jc1|<purpose>|<keyId>|<table.column>`, so a ciphertext cannot be copied into another column, table or purpose: decryption would fail.

The columns that are encrypted are listed in one place, `app/src/Security/FieldCipher.php`. They include names, e-mail addresses and phone numbers, case numbers, titles and summaries, party details, document titles and file names, hearing notes and outcomes, tasks, private notes, transcript text and speakers, bookmarks, MFA secrets, and audit-log details and IP addresses.

Exact-match lookups (sign-in by e-mail, duplicate case numbers) use **blind indexes**: keyed HMAC-SHA256 values, with a key that is never used for encryption.

### 1.3 File encryption (documents and recordings) — `JCF1`

```
header   = "JCF1" | 0x01 | 000000 | chunkSize (u32 BE) | noncePrefix (8 random bytes)
chunk i  = AES-256-GCM(DEK, nonce = noncePrefix | u32BE(i), aad = header | finalFlag) || tag
```

* The chunk index in the nonce detects reordering. The final flag detects truncation.
* Uploads are encrypted as they arrive. Recordings are encrypted by the transcription service frame by frame. Plaintext is never written to permanent storage.
* Chunks can be decrypted independently. This gives HTTP Range support, so reviewers can jump to any timestamp without decrypting a whole hearing.
* The SHA-256 of the plaintext is recorded at upload or seal time. It is re-verified on demand and by `bin/jc integrity:verify`.

### 1.4 Other data at rest

* **Sessions**: PHP session payloads are encrypted before being written (`EncryptedSessionHandler`).
* **Browser outbox**: if the network drops during a hearing, audio frames waiting to be uploaded are kept in IndexedDB. Each frame is encrypted with AES-GCM under a non-extractable WebCrypto key. Frames are deleted as soon as the server confirms they are stored durably.
* **Speech engine temp files**: whisper.cpp needs a WAV file. It is written with mode 0600 to `SECURE_TMP_DIR` (RAM-backed `/dev/shm`), then overwritten and deleted immediately.
* **MySQL**: `deploy/mysql/judicial-chambers.cnf` also turns on InnoDB tablespace, redo/undo-log and binlog encryption. This is defence in depth: it covers indexes and metadata the application does not encrypt.
* **Backups** of the database and of `STORAGE_PATH` contain only ciphertext. Back up the key ring **separately**, under dual control.

### 1.5 Key management and rotation

* Generate a key: `php app/bin/jc keys:generate`. Put it in `KEYRING` in **both** services. Never commit it.
* To rotate, add a new key, set `ACTIVE_KEY_ID` to it, then run `php app/bin/jc keys:rotate`:
  * database fields are re-encrypted;
  * document data keys are re-wrapped;
  * file contents do not need re-encryption.
* Keep retired keys in the ring. Audit-log entries (append-only) and finalised recordings (immutable) remain readable only with the key that was active when they were written.
* For production, load the key ring from an HSM- or KMS-backed secrets manager rather than a file.

## 2. Data in transit

| Link | Protection |
| --- | --- |
| Browser ⇄ PHP | HTTPS only. In production, HTTP is redirected (GET) or refused (anything else). HSTS 2 years with preload. `Secure`, `HttpOnly`, `SameSite=Strict` `__Host-` session cookie. |
| Browser ⇄ transcription | WSS only (TLS 1.2+). A short-lived HMAC capability token from PHP, sent inside the socket rather than in the URL. `Origin` allow-list. |
| Transcription ⇄ PHP | HTTPS with CA verification (`NODE_EXTRA_CA_CERTS`). Every request is HMAC-signed over method, path, timestamp, nonce and body hash, with a ±5 minute window and single-use nonces. Optional mTLS for `/jobs`. |
| PHP ⇄ MySQL | TLS with server-certificate verification (`DB_SSL_CA`). The app refuses to connect without TLS in production, and checks that the session really negotiated a cipher. The server has `require_secure_transport=ON`, and the DB user is `REQUIRE SSL`. |
| nginx ⇄ Node | Re-encrypted (`proxy_pass https://`, `proxy_ssl_verify on`). |
| Transcription ⇄ speech engine | Local process (whisper.cpp), or `https://` required in production for a self-hosted HTTP engine. |

Only TLS 1.2 and 1.3 with forward-secret AEAD suites are enabled (`deploy/nginx.conf`, `transcription/src/server.js`).

## 3. Access control

* **Two-factor authentication** (TOTP) is mandatory for all chamber roles by default (`MFA_REQUIRED_ROLES`). Codes cannot be replayed.
* **Passwords**: at least 12 characters mixing character classes, hashed with Argon2id. Accounts lock for 15 minutes after 5 failed attempts. Sign-in responses take the same time whether or not the account exists.
* **Sessions**: 30-minute idle timeout, 12-hour absolute timeout, and a new session ID on every privilege change.
* **CSRF tokens** on every state-changing request.
* **Strict CSP**: `script-src 'self'`, no inline script anywhere. Also `frame-ancestors 'none'` and `no-store` caching for authenticated pages.
* **Chamber permissions**: each staff member has an explicit capability list that the judge controls. Approving judgments, certifying transcripts and managing staff are reserved to the judge and cannot be delegated.
* **Case-level isolation**: staff see only cases they are assigned to, unless granted "all cases". **Sealed** cases are visible only to the judge and explicitly assigned staff. Sealed documents live in a separate directory under a separate key. Missing and forbidden cases return the same 404, so sealed matters are not disclosed.
* **Private notes and judgment drafts**: visible only with `notes.private`. They are encrypted under their own key purpose.
* **System administrators** have no access to case content unless they are members of the chambers.

## 4. Integrity and the official record

* **Audit trail**: every sign-in, view, download, playback, edit, permission change and certification is logged.
  * Each entry stores `HMAC(auditKey, previousHash | entry)`. Deleted, modified, inserted or re-ordered entries are detected by `bin/jc audit:verify` or the *Verify hash chain* button.
  * Database triggers reject `UPDATE` and `DELETE` on the log.
* **Recordings are originals**:
  * Once sealed, their hash, key and location cannot change, and they cannot be deleted (triggers).
  * The transcription service also records a hash chain over the sealed chunks as capture evidence.
* **Draft vs certified transcripts**:
  * Machine output is always labelled a draft. The original machine text is kept alongside every human correction.
  * Only the judge can certify a transcript, and only after the recording passes an integrity check.
  * Certification stores a keyed MAC over the recording hash and every segment, and the database then freezes the segments.
  * Any later change is reported on the review page and by `bin/jc integrity:verify`.

## 5. AI safeguards

* Speech recognition runs on court-controlled infrastructure (local whisper.cpp or a self-hosted endpoint). No case data goes to third-party services, and none is used for model training.
* The UI never presents machine output as the record. Reviewers can replay the audio for any passage, and low-confidence passages are flagged.
* Language support is honest:
  * English is the baseline.
  * Bemba, Nyanja, Tonga and Lozi are labelled *experimental* until accuracy has been measured on real proceedings.

## 6. Before production — checklist

- [ ] Formal assessment against the Data Protection Act, 2021 and the Cyber Security and Cyber Crimes Act, 2021, and Judiciary ICT security requirements.
- [ ] Key ring in an HSM/KMS-backed secrets store; documented dual-control key recovery.
- [ ] Certificates from the institution's PKI; internal CA for service-to-service links.
- [ ] `APP_ENV=production` in both services (enforces TLS everywhere).
- [ ] MySQL: `require_secure_transport`, InnoDB encryption with a keyring component, least-privilege accounts.
- [ ] `/internal/` restricted to the transcription host at the network layer.
- [ ] Retention and deletion policy agreed with the Judiciary (recordings are deliberately undeletable from the application).
- [ ] Independent penetration test and code review.
- [ ] Measured transcription accuracy per language before any language is offered as supported.
