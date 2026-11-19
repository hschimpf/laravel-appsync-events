<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Support\ChannelPath;

/**
 * Load the shared channel-path fixture. The same file is consumed by the
 * JS connector's test suite, so it must stay language-neutral.
 *
 * @return array<int, array{channel: string, publicNamespace?: string, privateNamespace?: string, path?: string, throws?: bool}>
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
    $path = ChannelPath::from(
        $case['channel'],
        $case['publicNamespace'] ?? 'public',
        $case['privateNamespace'] ?? 'private',
    );

    expect($path)->toBe($case['path']);
})->with(collect(channelPathCases())
    ->reject(fn (array $case): bool => $case['throws'] ?? false)
    ->mapWithKeys(fn (array $case): array => [
        "{$case['channel']} => {$case['path']}" => [$case],
    ])
    ->all());

it('rejects channel names that produce invalid paths', function (array $case): void {
    ChannelPath::from(
        $case['channel'],
        $case['publicNamespace'] ?? 'public',
        $case['privateNamespace'] ?? 'private',
    );
})->with(collect(channelPathCases())
    ->filter(fn (array $case): bool => $case['throws'] ?? false)
    ->mapWithKeys(fn (array $case): array => [
        "{$case['channel']} throws" => [$case],
    ])
    ->all())
    ->throws(InvalidArgumentException::class);

it('rejects presence- channel names as unsupported', function (string $channel): void {
    ChannelPath::from($channel);
})->with(['presence-room.5', 'presence-lobby', 'presence-'])
    ->throws(InvalidArgumentException::class, 'presence channels (presence- prefix) are not supported');

it('rejects private-encrypted- channel names as unsupported', function (): void {
    ChannelPath::from('private-encrypted-orders.9');
})->throws(InvalidArgumentException::class, 'payload encryption is not implemented by this package, so private-encrypted channels are not supported (use private-)');

it('rejects channel names that exceed the 5-segment AppSync limit', function (): void {
    ChannelPath::from('private-org.1.team.2.board');
})->throws(InvalidArgumentException::class, '6 exceeds the limit of 5 segments (namespace and at most 4 channel name segments)');
