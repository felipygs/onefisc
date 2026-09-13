/**
 * JSON `fetch` against same-origin Laravel endpoints (catalog editor apply
 * flow). Inertia's router always follows the 2.1/2.2 redirects, which would
 * navigate away mid-flow — `fetch` performs both writes and lets the page
 * refresh its own props afterwards.
 *
 * CSRF rides the `XSRF-TOKEN` cookie that `VerifyCsrfToken` sets on every
 * response, the standard Laravel SPA convention.
 */
export function xsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    for (const part of document.cookie.split(';')) {
        const trimmed = part.trim();

        if (trimmed.startsWith('XSRF-TOKEN=')) {
            try {
                return decodeURIComponent(trimmed.slice('XSRF-TOKEN='.length));
            } catch {
                return null;
            }
        }
    }

    return null;
}

export function jsonHeaders(
    extra: Record<string, string> = {},
): Record<string, string> {
    const token = xsrfToken();

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
        ...extra,
    };
}
