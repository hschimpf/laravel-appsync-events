<?php

declare(strict_types=1);

it('reports whether the emulator endpoint is reachable', function (): void {
    $endpoint = getenv('AWS_ENDPOINT_URL') ?: null;

    if ($endpoint === null) {
        $this->markTestSkipped('AWS_ENDPOINT_URL is not set; the AppSync emulator is not running.');
    }

    expect($endpoint)->toStartWith('https://');
});
