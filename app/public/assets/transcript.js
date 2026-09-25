/*
 * Transcript review: click any timestamp or bookmark to replay that moment,
 * follow along as the audio plays, search, and correct passages in place.
 */
(function () {
    'use strict';

    var root = document.getElementById('transcript');
    if (!root) {
        return;
    }
    var player = root.querySelector('[data-role="player"]');
    var segments = Array.prototype.slice.call(root.querySelectorAll('.jc-segment'));

    function fmt(seconds) {
        var s = Math.floor(seconds);
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor((s % 3600) / 60)) + ':' + pad(s % 60);
    }

    root.addEventListener('click', function (e) {
        var seek = e.target.closest('[data-seek]');
        if (seek && player) {
            player.currentTime = parseInt(seek.getAttribute('data-seek'), 10) / 1000;
            player.play();
            return;
        }
        var edit = e.target.closest('[data-action="edit"]');
        if (edit) {
            var seg = edit.closest('.jc-segment');
            seg.querySelector('.jc-segment-edit').classList.remove('d-none');
            seg.querySelector('.jc-segment-text').classList.add('d-none');
            seg.querySelector('textarea').focus();
            return;
        }
        var cancel = e.target.closest('[data-action="cancel-edit"]');
        if (cancel) {
            var s = cancel.closest('.jc-segment');
            s.querySelector('.jc-segment-edit').classList.add('d-none');
            s.querySelector('.jc-segment-text').classList.remove('d-none');
        }
    });

    if (player) {
        var current = null;
        player.addEventListener('timeupdate', function () {
            var ms = player.currentTime * 1000;
            var active = null;
            for (var i = 0; i < segments.length; i++) {
                var start = +segments[i].getAttribute('data-start');
                var end = +segments[i].getAttribute('data-end');
                if (ms >= start && ms <= Math.max(end, start + 1000)) {
                    active = segments[i];
                }
            }
            if (active !== current) {
                if (current) {
                    current.classList.remove('jc-playing');
                }
                if (active) {
                    active.classList.add('jc-playing');
                    active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
                current = active;
            }
        });

        root.querySelectorAll('[data-role="current-time"]').forEach(function (input) {
            input.addEventListener('focus', function () {
                if (!input.value) {
                    input.value = fmt(player.currentTime);
                }
            });
        });
    }

    var search = root.querySelector('[data-role="search"]');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            segments.forEach(function (seg) {
                var textEl = seg.querySelector('.jc-segment-text');
                var text = textEl.textContent;
                var speaker = seg.querySelector('.jc-speaker-label').textContent;
                var hit = !q || text.toLowerCase().indexOf(q) !== -1 || speaker.toLowerCase().indexOf(q) !== -1;
                seg.classList.toggle('jc-hidden', !hit);
                // Rebuild highlighting with text nodes only (never innerHTML with user text).
                textEl.textContent = text;
                if (q && hit) {
                    var idx = text.toLowerCase().indexOf(q);
                    if (idx !== -1) {
                        textEl.textContent = '';
                        textEl.appendChild(document.createTextNode(text.slice(0, idx)));
                        var mark = document.createElement('mark');
                        mark.textContent = text.slice(idx, idx + q.length);
                        textEl.appendChild(mark);
                        textEl.appendChild(document.createTextNode(text.slice(idx + q.length)));
                    }
                }
            });
        });
    }
})();
