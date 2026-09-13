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
 *
 * The trade, measured rather than assumed: the package drags a large transitive tree, and
 * `npm audit` goes from 4 advisories to 16 because of it. None of them reach a running
 * container — only the two prebuilt files below are imported, the rest of the tree is never
 * executed, and `docker/Dockerfile` builds assets in a throwaway stage from which only
 * `public/build` is copied into the runtime image. `npm audit --omit=dev` stays at zero.
 * Twelve advisories in a discarded build stage is the better side of a swap against a CDN
 * that can execute script in an operator admin's session on every page load.
 */
import '@stoplight/elements/styles.min.css';
import '@stoplight/elements/web-components.min.js';
