// Minimal vanilla client for videos.iaiapro.com. Handles CSRF + fetch wrappers.
(function () {
    'use strict';

    let csrfToken = null;

    async function getCsrf() {
        if (csrfToken) return csrfToken;
        const r = await fetch('/api/csrf', { credentials: 'same-origin' });
        if (!r.ok) throw new Error('csrf_failed');
        const j = await r.json();
        csrfToken = j.csrf;
        return csrfToken;
    }

    async function api(method, url, body) {
        const opts = { method, credentials: 'same-origin', headers: {} };
        if (method !== 'GET' && method !== 'HEAD') {
            opts.headers['X-CSRF-Token'] = await getCsrf();
        }
        if (body !== undefined) {
            if (body instanceof FormData) {
                opts.body = body;
                // let browser set multipart boundary
            } else {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
        }
        const r = await fetch(url, opts);
        let data = null;
        const ct = r.headers.get('content-type') || '';
        if (ct.includes('application/json')) {
            try { data = await r.json(); } catch (e) { /* ignore */ }
        }
        if (!r.ok) {
            const err = new Error((data && data.error) || ('http_' + r.status));
            err.status = r.status;
            err.data = data;
            throw err;
        }
        return data;
    }

    window.VI = {
        api,
        get: (u) => api('GET', u),
        post: (u, b) => api('POST', u, b),
        put: (u, b) => api('PUT', u, b),
        del: (u) => api('DELETE', u),
        upload: (u, fd) => api('POST', u, fd),
    };
})();
