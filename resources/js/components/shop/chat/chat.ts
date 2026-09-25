/**
 * Chat widget helpers: transcript storage (sessionStorage) and a small JSON POST helper.
 * Everything the bot says is rendered as plain text; nothing here builds HTML.
 */

export interface ChatTurn {
    id: string;
    from: 'user' | 'bot';
    text: string;
    /** The FAQ question the answer came from (matched answers only). */
    about?: string | null;
    /** Related FAQ questions offered as chips. */
    related?: string[];
    /** Offer the "Talk to a person" button under this turn. */
    handoff?: boolean;
    /** Error and notice turns are shown differently and are not sent to staff. */
    tone?: 'error' | 'notice';
}

export interface AskResponse {
    matched: boolean;
    answer: string | null;
    question: string | null;
    related: string[];
    handoff: boolean;
}

const STORAGE_PREFIX = 'swa-chat';
let owner = 'guest';

/** Scope the saved chat to the signed-in account (or "guest"). */
export function setChatOwner(userId: number | null): void {
    owner = userId === null ? 'guest' : `user-${userId}`;
}

function storageKey(): string {
    return `${STORAGE_PREFIX}:${owner}`;
}

export const MAX_TURNS = 20;

let counter = 0;

export function turnId(): string {
    counter += 1;

    return `${Date.now().toString(36)}-${counter}`;
}

function isStringArray(value: unknown): value is string[] {
    return Array.isArray(value) && value.every((v) => typeof v === 'string');
}

function isTurn(value: unknown): value is ChatTurn {
    if (!value || typeof value !== 'object') return false;
    const t = value as Record<string, unknown>;

    return (
        typeof t.id === 'string' &&
        (t.from === 'user' || t.from === 'bot') &&
        typeof t.text === 'string' &&
        (t.related === undefined || isStringArray(t.related)) &&
        (t.about === undefined || t.about === null || typeof t.about === 'string') &&
        (t.tone === undefined || t.tone === 'error' || t.tone === 'notice')
    );
}

export function loadTurns(): ChatTurn[] {
    try {
        const raw = window.sessionStorage.getItem(storageKey());
        if (!raw) return [];
        const parsed: unknown = JSON.parse(raw);

        return Array.isArray(parsed) ? parsed.filter(isTurn).slice(-MAX_TURNS) : [];
    } catch {
        return [];
    }
}

export function saveTurns(turns: ChatTurn[]): void {
    try {
        window.sessionStorage.setItem(storageKey(), JSON.stringify(turns.slice(-MAX_TURNS)));
    } catch {
        // Storage full or blocked (private mode): the chat still works for this page view.
    }
}

export function clearTurns(): void {
    try {
        window.sessionStorage.removeItem(storageKey());
    } catch {
        // Nothing to clear.
    }
}

function xsrfToken(): string {
    try {
        const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

        return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
    } catch {
        return '';
    }
}

export interface JsonResult {
    status: number;
    data: unknown;
}

/** POST JSON with the session cookie and CSRF token. Throws only on network failure. */
export async function postJson(url: string, body: unknown): Promise<JsonResult> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    let data: unknown = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }

    return { status: response.status, data };
}

/** First message per field from a Laravel 422 body. */
export function fieldErrors(data: unknown): Record<string, string> {
    const errors = (data as { errors?: Record<string, unknown> } | null)?.errors;
    if (!errors || typeof errors !== 'object') return {};

    return Object.fromEntries(
        Object.entries(errors).map(([key, messages]) => [key, Array.isArray(messages) ? String(messages[0] ?? '') : String(messages)]),
    );
}

export const THROTTLED_MESSAGE = "You're sending messages quickly — please wait a minute.";
export const FRIENDLY_ERROR = 'Something went wrong on our side. Please try again in a moment.';
export const EXPIRED_MESSAGE = 'Your session expired. Refresh the page and try again.';
export const OFFLINE_MESSAGE = "We couldn't reach the shop. Check your connection and try again.";
