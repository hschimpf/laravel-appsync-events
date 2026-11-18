<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Tests\Support\Emulator\EmulatorSuite;

beforeEach(function (): void {
    $this->api = EmulatorSuite::requireApi($this);
});

it('provisions an Event API with loopback endpoints', function (): void {
    expect($this->api->apiId)->not->toBe('')
        ->and($this->api->httpHost)->toMatch('/^127\.0\.0\.1:\d+$/')
        ->and($this->api->httpEndpoint)->toBe("http://{$this->api->httpHost}/event")
        ->and($this->api->realtimeEndpoint)->toMatch('/^ws:\/\/127\.0\.0\.1:\d+\/event\/realtime$/')
        ->and($this->api->apiKey)->toStartWith('da2-');
});

it('creates channel namespaces on demand', function (): void {
    $namespace = 'ns'.bin2hex(random_bytes(4));

    $result = $this->api->createNamespace($namespace);

    expect((string) $result->get('channelNamespace')['name'] ?? $namespace)->toBe($namespace);
});
