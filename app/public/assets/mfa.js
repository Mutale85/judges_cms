(function () {
    'use strict';
    var el = document.getElementById('mfa-qr');
    if (!el || typeof qrcode !== 'function') {
        return;
    }
    var qr = qrcode(0, 'M');
    qr.addData(el.getAttribute('data-uri'));
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
})();
