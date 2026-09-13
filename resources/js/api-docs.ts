/**
 * The API reference's third-party renderer, served from this origin.
 *
 * Scramble's shipped view pulls Stoplight Elements from unpkg.com on every visit, with no
 * subresource integrity — so whoever controls that CDN gets script execution on this
 * origin, in the session of an operator admin, which is the only role allowed to open the
 * page at all. That is the polyfill.io shape exactly. Importing the bundle here makes it a
 * build artifact like everything else: pinned in package-lock.json, hashed by Vite, and
 * served from `self`, which is what lets SecurityHeaders drop both the unpkg allowance and
 * the `'unsafe-inline'` weakening that came with it.
 */
import '@stoplight/elements/styles.min.css';
import '@stoplight/elements/web-components.min.js';
