#!/usr/bin/env node
/**
 * Extracts the release notes for one version from CHANGELOG.md
 * (Keep a Changelog format) for `gh release create --notes-file`.
 *
 * Usage: node scripts/release-notes.mjs <version> [changelog-path]
 *
 * Prints the body of the matching `## [x.y.z]` section (everything up to
 * the next `## ` heading). When the section is missing or empty, prints
 * a fallback pointing at the changelog.
 */

import { readFileSync } from 'node:fs';

const FALLBACK = 'See [CHANGELOG.md](CHANGELOG.md) for this release.';

/**
 * Extract the notes body for a version heading.
 *
 * @param {string} changelog - The changelog file contents.
 * @param {string} version - Tag or version (`v1.2.3`, `1.2.3` or `Unreleased`).
 * @returns {string} The notes body, or the fallback text.
 */
export function extractReleaseNotes(changelog, version) {
    const name = version.replace(/^v/, '');
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const heading = new RegExp(`^## \\[?${escaped}\\]?(?:\\s+-\\s+\\d{4}-\\d{2}-\\d{2})?\\s*$`, 'm');
    const match = heading.exec(changelog);

    if (match === null) {
        return FALLBACK;
    }

    const rest = changelog.slice(match.index + match[0].length);
    const next = rest.search(/^## /m);
    const body = (next === -1 ? rest : rest.slice(0, next)).trim();

    return body === '' ? FALLBACK : body;
}

/* c8 ignore next -- CLI entry point. */
if (process.argv[1] !== undefined && process.argv[1].endsWith('release-notes.mjs')) {
    const [version, changelogPath = 'CHANGELOG.md'] = process.argv.slice(2);

    if (version === undefined) {
        console.error('Usage: node scripts/release-notes.mjs <version> [changelog-path]');
        process.exit(2);
    }

    process.stdout.write(`${extractReleaseNotes(readFileSync(changelogPath, 'utf8'), version)}\n`);
}
