<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Support\ChannelPath;

/**
 * Load the shared channel-path fixture. The same file is consumed by the
 * JS connector's test suite, so it must stay language-neutral.
 *
 * @return array<int, array{channel: string, defaultNamespace?: string, path?: string, throws?: bool}>
 */
function channelPathCases(): array
{
    $cases = json_decode(
        file_get_contents(__DIR__.'/../../fixtures/channel-paths.json') ?: '[]',
        true,
    );

    return is_array($cases) ? $cases : [];
}

it('maps Laravel channel names to AppSync channel paths', function (array $case): void {
    $path = ChannelPath::from($case['channel'], $case['defaultNamespace'] ?? 'default');

    expect($path)->toBe($case['path']);
})->with(collect(channelPathCases())
    ->reject(fn (array $case): bool => $case['throws'] ?? false)
    ->mapWithKeys(fn (array $case): array => [
        "{$case['channel']} => {$case['path']}" => [$case],
    ])
    ->all());

it('rejects channel names that produce invalid paths', function (array $case): void {
    ChannelPath::from($case['channel'], $case['defaultNamespace'] ?? 'default');
})->with(collect(channelPathCases())
    ->filter(fn (array $case): bool => $case['throws'] ?? false)
    ->mapWithKeys(fn (array $case): array => [
        "{$case['channel']} throws" => [$case],
    ])
    ->all())
    ->throws(InvalidArgumentException::class);
