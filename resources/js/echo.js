/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const isHttps = window.location.protocol === 'https:'
const host = window.location.hostname
const port = window.location.port
    ? Number(window.location.port)
    : (isHttps ? 443 : 80)

// The Reverb app key comes from the page at runtime (<meta name="reverb-key">,
// rendered from the node's own config/broadcasting.php), not from the build:
// the bundle is built once in CI (assets.yml) with no .env, and each PBX node
// runs its own Reverb with its own key. VITE_REVERB_APP_KEY stays as a fallback
// for local `npm run dev`. With no key at all, skip Echo rather than throw —
// `new Echo` without a key throws "You must pass your app key" before Vue
// mounts, which blanked every page (2026-09-28).
const reverbKey = document.querySelector('meta[name="reverb-key"]')?.content
    || import.meta.env.VITE_REVERB_APP_KEY

if (!reverbKey) {
    console.warn('Reverb app key missing: real-time updates are disabled')
} else {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,

        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: isHttps,

        enabledTransports: ['ws', 'wss'],
        wsPath: '/ws',
    })
}
