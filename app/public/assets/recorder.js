/*
 * Courtroom recording console.
 *
 * Microphone -> AudioWorklet (16 kHz PCM16) -> numbered frames -> WSS to the
 * Node transcription service, which encrypts them on arrival.
 *
 * Resilience: every frame stays in a local outbox until the service
 * acknowledges it. The outbox lives in IndexedDB and each frame is encrypted
 * there with AES-GCM under a non-extractable WebCrypto key, so a network drop
 * (or even a browser crash) does not lose audio, and nothing readable is left
 * on the device. On reconnection the outbox is replayed from the first frame
 * the service has not yet stored.
 */
(function () {
    'use strict';

    var root = document.getElementById('recorder');
    if (!root) {
        return;
    }

    var recId = root.getAttribute('data-rec-id');
    var wsUrl = root.getAttribute('data-ws-url');
    var tokenUrl = root.getAttribute('data-token-url');
    var reviewUrl = root.getAttribute('data-review-url');
    var sampleRate = parseInt(root.getAttribute('data-sample-rate'), 10) || 16000;
    var csrf = document.body.getAttribute('data-csrf');

    var $ = function (sel) { return root.querySelector(sel); };
    var ui = {
        start: $('[data-action="start"]'),
        stop: $('[data-action="stop"]'),
        bookmark: $('[data-action="bookmark"]'),
        bookmarkLabel: $('[data-role="bookmark-label"]'),
        state: $('[data-role="state"]'),
        clock: $('[data-role="clock"]'),
        connection: $('[data-role="connection"]'),
        buffer: $('[data-role="buffer"]'),
        level: $('[data-role="level"]'),
        live: $('[data-role="live"]'),
        placeholder: $('[data-role="placeholder"]'),
        customSpeaker: $('[data-role="custom-speaker"]'),
        speakers: root.querySelectorAll('.jc-speaker')
    };

    var state = {
        recording: false,
        stopping: false,
        finalized: false,
        samples: 0,          // samples captured (defines the recording clock)
        nextSeq: 0,          // next frame number to assign
        ackedSeq: -1,        // highest frame durably stored by the service
        sentSeq: -1,         // highest frame sent on the current connection
        outbox: new Map(),   // seq -> ArrayBuffer (frame incl. header) kept in memory
        control: new Map(),  // cid -> control message awaiting ack
        ws: null,
        authed: false,
        reconnectDelay: 1000,
        audio: null,
        stream: null,
        node: null,
        speaker: null
    };

    // --- Encrypted local outbox (IndexedDB + non-extractable AES-GCM key) -------

    var store = {
        db: null,
        key: null,
        open: function () {
            return new Promise(function (resolve) {
                if (!window.indexedDB || !window.crypto || !window.crypto.subtle) {
                    resolve(false);
                    return;
                }
                var req = indexedDB.open('jc-recorder', 1);
                req.onupgradeneeded = function () {
                    var db = req.result;
                    db.createObjectStore('keys');
                    db.createObjectStore('frames');
                };
                req.onerror = function () { resolve(false); };
                req.onsuccess = function () {
                    store.db = req.result;
                    store.loadKey().then(function () { resolve(true); }, function () { resolve(false); });
                };
            });
        },
        tx: function (name, mode) {
            return store.db.transaction(name, mode).objectStore(name);
        },
        loadKey: function () {
            return new Promise(function (resolve, reject) {
                var get = store.tx('keys', 'readonly').get('rec-' + recId);
                get.onsuccess = function () {
                    if (get.result) {
                        store.key = get.result;
                        resolve();
                        return;
                    }
                    crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']).then(function (key) {
                        store.key = key;
                        var put = store.tx('keys', 'readwrite').put(key, 'rec-' + recId);
                        put.onsuccess = function () { resolve(); };
                        put.onerror = reject;
                    }, reject);
                };
                get.onerror = reject;
            });
        },
        frameKey: function (seq) {
            return recId + ':' + String(seq).padStart(10, '0');
        },
        save: function (seq, frame) {
            if (!store.db) {
                return Promise.resolve();
            }
            var iv = crypto.getRandomValues(new Uint8Array(12));
            return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, store.key, frame).then(function (ct) {
                store.tx('frames', 'readwrite').put({ iv: iv, ct: ct }, store.frameKey(seq));
            }).catch(function () { /* memory copy is still kept */ });
        },
        remove: function (seq) {
            if (store.db) {
                store.tx('frames', 'readwrite').delete(store.frameKey(seq));
            }
        },
        /** Restores frames left behind by a crashed or closed tab. */
        restore: function () {
            if (!store.db) {
                return Promise.resolve(0);
            }
            return new Promise(function (resolve) {
                var range = IDBKeyRange.bound(recId + ':', recId + ':￿');
                var req = store.tx('frames', 'readonly').openCursor(range);
                var pending = [];
                req.onsuccess = function () {
                    var cursor = req.result;
                    if (cursor) {
                        var seq = parseInt(String(cursor.key).split(':')[1], 10);
                        var rec = cursor.value;
                        pending.push(crypto.subtle.decrypt({ name: 'AES-GCM', iv: rec.iv }, store.key, rec.ct).then(function (frame) {
                            state.outbox.set(seq, frame);
                            state.nextSeq = Math.max(state.nextSeq, seq + 1);
                        }, function () { store.remove(seq); }));
                        cursor.continue();
                    } else {
                        Promise.all(pending).then(function () { resolve(pending.length); });
                    }
                };
                req.onerror = function () { resolve(0); };
            });
        },
        destroy: function () {
            if (store.db) {
                store.tx('keys', 'readwrite').delete('rec-' + recId);
            }
        }
    };

    // --- UI helpers --------------------------------------------------------------

    function fmt(ms) {
        var s = Math.floor(ms / 1000);
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor((s % 3600) / 60)) + ':' + pad(s % 60);
    }

    function nowMs() {
        return Math.round(state.samples * 1000 / sampleRate);
    }

    function setConnection(text, icon, cls) {
        ui.connection.innerHTML = '';
        var i = document.createElement('i');
        i.className = 'bi ' + icon + ' ' + (cls || '');
        ui.connection.appendChild(i);
        ui.connection.appendChild(document.createTextNode(' ' + text));
    }

    function updateBuffer() {
        var pendingMs = 0;
        state.outbox.forEach(function (frame) { pendingMs += (frame.byteLength - 4) / 2 * 1000 / sampleRate; });
        ui.buffer.textContent = pendingMs > 1500 ? 'Buffered locally (encrypted): ' + fmt(pendingMs) : '';
    }

    function appendSegment(seg) {
        if (ui.placeholder) {
            ui.placeholder.remove();
            ui.placeholder = null;
        }
        var existing = ui.live.querySelector('[data-seq="' + seg.seq + '"]');
        var el = existing || document.createElement('div');
        el.className = 'jc-live-seg';
        el.setAttribute('data-seq', seg.seq);
        el.innerHTML = '';
        var meta = document.createElement('div');
        meta.className = 'jc-live-meta';
        meta.textContent = fmt(seg.start_ms) + (seg.speaker ? ' · ' + seg.speaker : '');
        var text = document.createElement('div');
        text.textContent = seg.text;
        el.appendChild(meta);
        el.appendChild(text);
        if (!existing) {
            ui.live.appendChild(el);
            ui.live.scrollTop = ui.live.scrollHeight;
        }
    }

    /** In-progress words of the current turn (real-time engines only); never stored. */
    function showPartial(text) {
        if (!ui.partial) {
            ui.partial = document.createElement('div');
            ui.partial.className = 'jc-live-partial text-muted fst-italic';
            ui.live.parentNode.appendChild(ui.partial);
        }
        ui.partial.textContent = text ? text + ' …' : '';
    }

    function showNotice(message) {
        if (ui.placeholder) {
            ui.placeholder.textContent = message;
            ui.placeholder.classList.add('text-warning-emphasis');
        }
    }

    // --- Transport -----------------------------------------------------------------

    function fetchToken() {
        return fetch(tokenUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }
        }).then(function (r) {
            return r.json().then(function (body) {
                if (!r.ok) {
                    throw new Error(body.error || 'Could not authorise the recording.');
                }
                return body.token;
            });
        });
    }

    function connect() {
        if (state.finalized || (state.ws && state.ws.readyState <= 1)) {
            return;
        }
        setConnection('Connecting…', 'bi-arrow-repeat');
        fetchToken().then(function (token) {
            var ws = new WebSocket(wsUrl);
            ws.binaryType = 'arraybuffer';
            state.ws = ws;
            state.authed = false;
            ws.onopen = function () {
                ws.send(JSON.stringify({ type: 'auth', token: token }));
            };
            ws.onmessage = function (event) { onMessage(JSON.parse(event.data)); };
            ws.onclose = function () {
                state.authed = false;
                if (state.finalized) {
                    return;
                }
                setConnection('Offline — audio is being kept on this device', 'bi-wifi-off', 'text-danger');
                setTimeout(connect, state.reconnectDelay);
                state.reconnectDelay = Math.min(state.reconnectDelay * 2, 15000);
            };
            ws.onerror = function () { /* onclose handles retry */ };
        }).catch(function (err) {
            setConnection(err.message, 'bi-exclamation-triangle', 'text-danger');
            if (!state.finalized) {
                setTimeout(connect, state.reconnectDelay);
                state.reconnectDelay = Math.min(state.reconnectDelay * 2, 15000);
            }
        });
    }

    function onMessage(msg) {
        switch (msg.type) {
            case 'ready':
                state.authed = true;
                state.finalRequested = false;
                state.sentSeq = msg.next_seq - 1;
                state.reconnectDelay = 1000;
                setConnection('Connected (TLS)', 'bi-shield-lock-fill', 'text-success');
                if (!state.clockSynced) {
                    // After a reload, continue the clock from what the service already holds.
                    var pending = 0;
                    state.outbox.forEach(function (frame, seq) { if (seq >= msg.next_seq) { pending += (frame.byteLength - 4) / 2; } });
                    state.samples = Math.max(state.samples, (msg.stored_samples || 0) + pending);
                    state.nextSeq = Math.max(state.nextSeq, msg.next_seq);
                    state.clockSynced = true;
                    ui.clock.textContent = fmt(nowMs());
                }
                acknowledge(msg.durable_seq);
                flushOutbox();
                break;
            case 'ack':
                acknowledge(msg.seq);
                break;
            case 'ack_ctl':
                state.control.delete(msg.cid);
                maybeFinish();
                break;
            case 'segment':
                showPartial('');
                appendSegment(msg.segment);
                break;
            case 'partial':
                showPartial(msg.text);
                break;
            case 'notice':
                showNotice(msg.message);
                break;
            case 'finalized':
                state.finalized = true;
                store.destroy();
                ui.state.textContent = 'Finalised';
                setConnection('Recording sealed · SHA-256 ' + String(msg.sha256).slice(0, 12) + '…', 'bi-patch-check-fill', 'text-success');
                window.location.href = reviewUrl;
                break;
            case 'error':
                setConnection(msg.message || 'Service error', 'bi-exclamation-triangle', 'text-danger');
                if (msg.fatal) {
                    state.finalized = true;
                    stopAudio();
                }
                break;
        }
    }

    function acknowledge(seq) {
        if (seq <= state.ackedSeq) {
            return;
        }
        for (var s = state.ackedSeq + 1; s <= seq; s++) {
            if (state.outbox.delete(s)) {
                store.remove(s);
            }
        }
        state.ackedSeq = seq;
        updateBuffer();
        maybeFinish();
    }

    function flushOutbox() {
        if (!state.authed) {
            return;
        }
        Array.from(state.outbox.keys()).sort(function (a, b) { return a - b; }).forEach(function (seq) {
            if (seq > state.sentSeq) {
                state.ws.send(state.outbox.get(seq));
                state.sentSeq = seq;
            }
        });
        state.control.forEach(function (msg) { state.ws.send(JSON.stringify(msg)); });
        maybeFinish();
    }

    function sendControl(msg) {
        msg.cid = msg.type + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
        state.control.set(msg.cid, msg);
        if (state.authed) {
            state.ws.send(JSON.stringify(msg));
        }
    }

    // --- Audio -------------------------------------------------------------------

    function onFrame(data) {
        var pcm = new Uint8Array(data.pcm);
        var frame = new Uint8Array(4 + pcm.byteLength);
        var seq = state.nextSeq++;
        new DataView(frame.buffer).setUint32(0, seq);
        frame.set(pcm, 4);
        state.samples += pcm.byteLength / 2;
        state.outbox.set(seq, frame.buffer);
        store.save(seq, frame.buffer);
        if (state.authed && seq === state.sentSeq + 1) {
            state.ws.send(frame.buffer);
            state.sentSeq = seq;
        }
        ui.clock.textContent = fmt(nowMs());
        ui.level.style.width = Math.min(100, Math.round(data.rms * 300)) + '%';
        updateBuffer();
    }

    function startAudio() {
        return navigator.mediaDevices.getUserMedia({
            audio: { channelCount: 1, echoCancellation: false, noiseSuppression: false, autoGainControl: true }
        }).then(function (stream) {
            state.stream = stream;
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            state.audio = ctx;
            return ctx.audioWorklet.addModule('/assets/pcm-worklet.js').then(function () {
                var source = ctx.createMediaStreamSource(stream);
                var node = new AudioWorkletNode(ctx, 'pcm-capture', { processorOptions: { targetRate: sampleRate } });
                node.port.onmessage = function (e) { onFrame(e.data); };
                source.connect(node);
                state.node = node;
            });
        });
    }

    function stopAudio() {
        if (state.node) {
            state.node.port.postMessage('stop');
        }
        if (state.stream) {
            state.stream.getTracks().forEach(function (t) { t.stop(); });
        }
        setTimeout(function () {
            if (state.audio) {
                state.audio.close();
            }
        }, 300);
    }

    /*
     * Asks the service to seal the recording once every frame has been sent and
     * every control message acknowledged. The service checks it holds all
     * frames up to final_seq (and asks for a resend otherwise) before sealing;
     * the remaining frames are acknowledged when the final chunk is written.
     */
    function maybeFinish() {
        if (state.stopping && state.audioStopped && state.authed && state.sentSeq >= state.nextSeq - 1 && state.control.size === 0 && !state.finalRequested) {
            state.finalRequested = true;
            ui.state.textContent = 'Sealing recording…';
            state.ws.send(JSON.stringify({ type: 'stop', final_seq: state.nextSeq - 1 }));
        }
    }

    // --- Controls ----------------------------------------------------------------

    function setSpeaker(label) {
        state.speaker = label;
        ui.speakers.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-speaker') === label); });
        if (state.recording) {
            sendControl({ type: 'speaker', label: label, at_ms: nowMs() });
        }
    }

    function addBookmark() {
        if (!state.recording) {
            return;
        }
        var label = ui.bookmarkLabel.value.trim() || 'Bookmark';
        sendControl({ type: 'bookmark', label: label, at_ms: nowMs() });
        ui.bookmarkLabel.value = '';
        appendSegment({ seq: 'bm-' + Date.now(), start_ms: nowMs(), speaker: 'Bookmark', text: label });
    }

    ui.start.addEventListener('click', function () {
        ui.start.disabled = true;
        startAudio().then(function () {
            state.recording = true;
            root.classList.add('jc-recording');
            ui.state.textContent = 'Recording';
            ui.stop.disabled = false;
            ui.bookmark.disabled = false;
            if (state.speaker) {
                sendControl({ type: 'speaker', label: state.speaker, at_ms: nowMs() });
            }
            connect();
        }).catch(function (err) {
            ui.start.disabled = false;
            window.alert('Microphone unavailable: ' + err.message);
        });
    });

    ui.stop.addEventListener('click', function () {
        if (!window.confirm('Stop recording and seal the original? This cannot be resumed.')) {
            return;
        }
        state.recording = false;
        state.stopping = true;
        root.classList.remove('jc-recording');
        ui.stop.disabled = true;
        ui.bookmark.disabled = true;
        ui.state.textContent = 'Uploading remaining audio…';
        if (state.node) {
            state.node.port.postMessage('flush');
        }
        setTimeout(function () {
            stopAudio();
            state.audioStopped = true;
            flushOutbox();
            maybeFinish();
        }, 400);
    });

    ui.bookmark.addEventListener('click', addBookmark);
    ui.speakers.forEach(function (b) {
        b.addEventListener('click', function () { setSpeaker(b.getAttribute('data-speaker')); });
    });
    $('[data-action="custom-speaker"]').addEventListener('click', function () {
        var label = ui.customSpeaker.value.trim();
        if (label) {
            setSpeaker(label);
        }
    });

    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || e.ctrlKey || e.metaKey || e.altKey) {
            return;
        }
        if (e.key >= '1' && e.key <= '9' && ui.speakers[e.key - 1]) {
            setSpeaker(ui.speakers[e.key - 1].getAttribute('data-speaker'));
        } else if (e.key === 'b' || e.key === 'B') {
            addBookmark();
        }
    });

    window.addEventListener('beforeunload', function (e) {
        if (state.recording || (state.stopping && !state.finalized) || state.outbox.size > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Recover frames left from an interrupted session of this recording.
    store.open().then(function () { return store.restore(); }).then(function (restored) {
        if (restored > 0) {
            ui.state.textContent = 'Recovered ' + restored + ' unsent frames';
            updateBuffer();
            connect();
        }
    });
})();
