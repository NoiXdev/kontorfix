import {
    startAuthentication,
    startRegistration,
    type PublicKeyCredentialCreationOptionsJSON,
    type PublicKeyCredentialRequestOptionsJSON,
} from '@simplewebauthn/browser';

/** Reads the XSRF-TOKEN cookie set by Laravel for the CSRF header. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error('Die Passkey-Anfrage ist fehlgeschlagen.');
    }

    return (await response.json()) as T;
}

async function postJson<T>(url: string, body: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error('Die Passkey-Anfrage ist fehlgeschlagen.');
    }

    return (await response.json()) as T;
}

/** Registers a new passkey for the logged-in user. */
export async function registerPasskey(name: string): Promise<void> {
    const { options } = await getJson<{ options: PublicKeyCredentialCreationOptionsJSON }>(
        route('passkey.registration-options'),
    );

    const credential = await startRegistration({ optionsJSON: options });

    await postJson(route('passkey.store'), { name, credential });
}

/** Signs in passwordlessly via passkey; returns the redirect target. */
export async function loginWithPasskey(remember: boolean): Promise<string> {
    const { options } = await getJson<{ options: PublicKeyCredentialRequestOptionsJSON }>(
        route('passkey.login-options'),
    );

    const credential = await startAuthentication({ optionsJSON: options });

    const { redirect } = await postJson<{ redirect: string }>(route('passkey.login'), {
        credential,
        remember,
    });

    return redirect;
}

/** WebAuthn available in the browser? */
export function passkeysSupported(): boolean {
    return typeof window !== 'undefined' && !!window.PublicKeyCredential;
}
