// Thin fetch wrapper for the eVisa JSON API.
//
// The API lives at /api/ukvi/* and is served under Laravel's "web" middleware
// group, so it relies on the same session cookie as the rest of the app and
// enforces CSRF on writes. We read the token from the <meta name="csrf-token">
// tag in the SPA shell and echo it back as X-CSRF-TOKEN.

const BASE = '/api/ukvi';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export class ApiError extends Error {
    constructor(message, { status, data } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data ?? {};
    }
}

async function request(method, path, body) {
    const headers = { Accept: 'application/json' };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-TOKEN'] = csrfToken();
    }

    let response;
    try {
        response = await fetch(`${BASE}${path}`, {
            method,
            headers,
            credentials: 'same-origin',
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
    } catch (networkError) {
        throw new ApiError('Network error. Please check your connection and try again.', {
            status: 0,
        });
    }

    let data = null;
    const text = await response.text();
    if (text) {
        try {
            data = JSON.parse(text);
        } catch {
            data = null;
        }
    }

    if (!response.ok) {
        const message =
            (data && (data.error || data.message)) ||
            'Something went wrong. Please try again.';
        throw new ApiError(message, { status: response.status, data: data ?? {} });
    }

    return data;
}

export const api = {
    get: (path) => request('GET', path),
    post: (path, body = {}) => request('POST', path, body),
};

// Absolute URLs for the browser to fetch directly (photo <img>, PDF <a>).
export const assetUrls = {
    photo: `${BASE}/evisa/photo`,
    shareCodePdf: `${BASE}/evisa/share-code`,
};
