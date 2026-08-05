import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        // Optional: broadcasting is only wired up when a Reverb key was present at
        // build time. Consumers already guard with `window.Echo?.`.
        Echo?: Echo<'reverb'>;
        Pusher: typeof Pusher;
    }
}

window.Pusher = Pusher;

// VITE_* values are inlined when `npm run build` runs — they cannot be supplied at
// container start. If the key is missing, `new Echo()` throws inside pusher-js
// ("You must pass your app key when you instantiate Pusher."). Since this module is
// imported before createInertiaApp(), that exception aborts the whole entry bundle
// and leaves the user with a blank page. Broadcasting is optional, so treat a
// missing key as "realtime off" instead of taking the app down with it.
const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        // Reverb is served from the app's own host unless told otherwise.
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} else if (import.meta.env.DEV) {
    console.warn('[echo] VITE_REVERB_APP_KEY missing at build time — realtime updates are disabled.');
}
