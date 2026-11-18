/**
 * Tests for scripts/release-notes.mjs — run with `node --test scripts/`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { extractReleaseNotes } from './release-notes.mjs';

const SAMPLE = `# Changelog

## [Unreleased]

### Added

- pending work

## [1.2.0] - 2026-11-18

### Added

- the feature
- another feature

### Fixed

- the bug

## [1.1.0] - 2026-10-01

### Added

- older stuff
`;

test('extracts the section for an exact version', () => {
    const notes = extractReleaseNotes(SAMPLE, '1.2.0');

    assert.match(notes, /### Added/);
    assert.match(notes, /- the feature/);
    assert.match(notes, /### Fixed/);
    assert.match(notes, /- the bug/);
    assert.doesNotMatch(notes, /older stuff/);
    assert.doesNotMatch(notes, /pending work/);
    assert.doesNotMatch(notes, /^## /m);
});

test('accepts a v-prefixed tag', () => {
    assert.match(extractReleaseNotes(SAMPLE, 'v1.2.0'), /- the feature/);
});

test('extracts Unreleased sections', () => {
    assert.match(extractReleaseNotes(SAMPLE, 'Unreleased'), /pending work/);
});

test('does not confuse similar version numbers', () => {
    assert.match(extractReleaseNotes(SAMPLE, '1.1.0'), /older stuff/);
    assert.doesNotMatch(extractReleaseNotes(SAMPLE, '1.1.0'), /the feature/);
});

test('falls back for a missing version', () => {
    assert.match(extractReleaseNotes(SAMPLE, '9.9.9'), /CHANGELOG\.md/);
});

test('falls back for an empty section', () => {
    assert.match(extractReleaseNotes('# C\n\n## [2.0.0] - 2026-01-01\n\n## [1.0.0]\n\n- x\n', '2.0.0'), /CHANGELOG\.md/);
});

test('extracts from the real repository changelog', () => {
    const changelog = readFileSync(new URL('../CHANGELOG.md', import.meta.url), 'utf8');
    const notes = extractReleaseNotes(changelog, 'Unreleased');

    assert.match(notes, /broadcasting driver/i);
    assert.doesNotMatch(notes, /^## /m);
});
