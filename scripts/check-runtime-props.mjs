#!/usr/bin/env node
/**
 * Compiles every .vue SFC under resources/js/components/ui with
 * @vue/compiler-sfc's parse + compileScript, and reports the runtime prop
 * names each component actually emits.
 *
 * This exists because `interface Props extends <ImportedType> { ... }` (an
 * intersection of an imported/external type into the props interface, most
 * often paired with `/* @vue-ignore *\/` on the extended member) silently
 * compiles to a component with NO runtime `props:` field at all — not just
 * the inherited members, the locally declared ones too. No type check and
 * no lint rule catches this; the only way to know is to compile the SFC and
 * look at what `compileScript` actually produced.
 *
 * Usage:
 *   node scripts/check-runtime-props.mjs [--json] [globPrefix]
 *
 * Exits non-zero if any component resolves to zero runtime props while its
 * <script setup> block references `defineProps` with a type argument (a
 * strong signal the type-only prop declaration silently vanished).
 */

import { createRequire } from 'node:module';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { extname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as vueCompilerSfc from '@vue/compiler-sfc';

const { parse, compileScript, registerTS } = vueCompilerSfc;

// compileScript resolves named type imports from packages (e.g. `TooltipContentEmits`
// from radix-vue) via TypeScript's own module resolution, but only if a TS loader has
// been registered — otherwise it throws "typescript is required as a peer dep". This
// mirrors what vue-tsc / the IDE tooling wire up for us automatically.
const require = createRequire(import.meta.url);
registerTS(() => require('typescript'));

const repoRoot = join(fileURLToPath(new URL('.', import.meta.url)), '..');
const defaultTarget = join(repoRoot, 'resources/js/components/ui');

const args = process.argv.slice(2);
const asJson = args.includes('--json');
const target = args.find((a) => !a.startsWith('--')) ?? defaultTarget;

function walk(dir, out = []) {
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        const stat = statSync(full);
        if (stat.isDirectory()) {
            walk(full, out);
        } else if (extname(entry) === '.vue') {
            out.push(full);
        }
    }
    return out;
}

// compileScript's type-resolution needs an `fs` implementation to follow
// imported types (e.g. `PrimitiveProps` from radix-vue) across files —
// omitting this option throws when any type is imported from elsewhere.
const fsOption = {
    fileExists(file) {
        try {
            return statSync(file).isFile();
        } catch {
            return false;
        }
    },
    readFile(file) {
        try {
            return readFileSync(file, 'utf-8');
        } catch {
            return undefined;
        }
    },
};

function checkFile(file) {
    const source = readFileSync(file, 'utf-8');
    const { descriptor, errors: parseErrors } = parse(source, { filename: file });

    if (parseErrors.length) {
        return { file, error: `parse error: ${parseErrors.map((e) => e.message).join('; ')}` };
    }

    if (!descriptor.scriptSetup && !descriptor.script) {
        return { file, props: [], note: 'no <script> block' };
    }

    // Whether the SFC declares props via `defineProps<Type>()` (type-only form) at all —
    // read from the raw source, since compileScript's output has already erased types.
    const rawScript = (descriptor.scriptSetup?.content ?? '') + (descriptor.script?.content ?? '');
    const usesTypeOnlyProps = /defineProps\s*<\s*[\w.]/.test(rawScript);

    try {
        const compiled = compileScript(descriptor, {
            id: file,
            fs: fsOption,
        });

        return {
            file,
            props: extractRuntimePropNames(compiled.content),
            usesTypeOnlyProps,
        };
    } catch (err) {
        return { file, error: err.message, usesTypeOnlyProps };
    }
}

// Ground truth is the `props: ...` value inside the compiled `_defineComponent({ ... })`
// call — NOT `compiled.bindings`. Bindings mark a prop's binding type as 'props' only if
// nothing else in scope shadows that identifier; several components here declare a local
// `const modelValue = useVModel(...)` alongside a `modelValue` prop of the same name,
// which shadows the binding without affecting the actual runtime declaration. Parsing the
// emitted `props:` value directly avoids that false negative.
//
// The value takes one of three shapes depending on which macros the component uses:
//   props: { class: {...}, id: {...} }                        - plain defineProps
//   props: ["a", "b"]                                          - untyped array form
//   props: /*@__PURE__*/ _mergeModels({ ... }, { ... })        - defineProps + defineModel
function extractRuntimePropNames(compiledContent) {
    const marker = /\bprops:\s*/.exec(compiledContent);
    if (!marker) return [];

    let i = marker.index + marker[0].length;
    i = skipPureComment(compiledContent, i);

    return collectKeysFromValue(compiledContent, i).keys;
}

function skipPureComment(text, i) {
    const rest = text.slice(i);
    const m = /^\/\*[^*]*\*\/\s*/.exec(rest);
    return m ? i + m[0].length : i;
}

// Reads the value starting at index `i` and returns its extracted prop keys plus the
// index just past the value, so callers (mergeModels argument splitting) can continue.
function collectKeysFromValue(text, i) {
    if (text[i] === '{') {
        const { body, end } = readBalanced(text, i, '{', '}');
        return { keys: extractTopLevelKeys(body), end };
    }
    if (text[i] === '[') {
        const { body, end } = readBalanced(text, i, '[', ']');
        const keys = [...body.matchAll(/"([^"]+)"|'([^']+)'/g)].map((m) => m[1] ?? m[2]);
        return { keys, end };
    }
    const callMatch = /^_?mergeModels\s*\(/.exec(text.slice(i));
    if (callMatch) {
        const parenStart = i + callMatch[0].length - 1;
        const { body, end } = readBalanced(text, parenStart, '(', ')');
        const keys = splitTopLevelArgs(body).flatMap((arg) => {
            const trimmed = arg.replace(/^\s+/, '');
            return collectKeysFromValue(trimmed, 0).keys;
        });
        return { keys, end };
    }
    return { keys: [], end: i };
}

function readBalanced(text, start, openCh, closeCh) {
    let depth = 0;
    for (let i = start; i < text.length; i++) {
        if (text[i] === openCh) depth++;
        else if (text[i] === closeCh) {
            depth--;
            if (depth === 0) return { body: text.slice(start + 1, i), end: i + 1 };
        }
    }
    return { body: text.slice(start + 1), end: text.length };
}

function splitTopLevelArgs(text) {
    const args = [];
    let depth = 0;
    let start = 0;
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if ('{[('.includes(ch)) depth++;
        else if ('}])'.includes(ch)) depth--;
        else if (ch === ',' && depth === 0) {
            args.push(text.slice(start, i));
            start = i + 1;
        }
    }
    args.push(text.slice(start));
    return args.filter((a) => a.trim().length > 0);
}

// Extracts identifier/quoted keys at brace-depth 0 of an object literal body, e.g.
// `class: { type: null, required: false }, id: { type: String }` -> ['class', 'id'].
function extractTopLevelKeys(objectBody) {
    const keys = [];
    let depth = 0;
    let i = 0;
    while (i < objectBody.length) {
        const ch = objectBody[i];
        if (ch === '{' || ch === '[' || ch === '(') depth++;
        else if (ch === '}' || ch === ']' || ch === ')') depth--;
        if (depth === 0 && /[\w"'`]/.test(ch)) {
            const keyMatch = /^\s*(?:"([^"]+)"|'([^']+)'|(\w+))\s*:/.exec(objectBody.slice(i));
            if (keyMatch) {
                keys.push(keyMatch[1] ?? keyMatch[2] ?? keyMatch[3]);
                i += keyMatch[0].length;
                continue;
            }
        }
        i++;
    }
    return keys;
}

const files = walk(target).sort();
const results = files.map(checkFile);

if (asJson) {
    console.log(JSON.stringify(results, null, 2));
} else {
    let suspicious = 0;
    for (const r of results) {
        const rel = relative(repoRoot, r.file);
        if (r.error) {
            console.log(`ERROR  ${rel}: ${r.error}`);
            continue;
        }
        const propsList = r.props.length ? r.props.join(', ') : '(none)';
        const flag = r.usesTypeOnlyProps && r.props.length === 0 ? '  <-- SUSPECT: type-only props but ZERO runtime props' : '';
        if (flag) suspicious++;
        console.log(`${r.props.length.toString().padStart(2)} props  ${rel}: ${propsList}${flag}`);
    }
    console.log(`\n${files.length} files checked, ${suspicious} suspect (type-only defineProps but zero runtime props emitted).`);
}

const hasSuspects = results.some((r) => !r.error && r.usesTypeOnlyProps && r.props.length === 0 && !r.note);
process.exit(hasSuspects ? 1 : 0);
