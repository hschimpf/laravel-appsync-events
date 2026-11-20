<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Facades\AppSyncEvents;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\NameUidResolver;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\TestUser;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config()->set('broadcasting.default', 'appsync');
    config()->set('appsync-events.http_endpoint', 'https://abc123.appsync-api.eu-west-1.amazonaws.com/event');
    config()->set('appsync-events.signing_secret', 'route-secret');
});

it('registers exactly one broadcasting auth route pair', function (): void {
    $posts = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => $route->uri() === 'broadcasting/auth' && in_array('POST', $route->methods(), true));

    expect($posts)->toHaveCount(1);
});

it('issues a subscribe token for an authorized private channel', function (): void {
    Broadcast::channel('orders.{id}', static fn ($user, $id): bool => (string) $id === '5');

    $this->actingAs(new TestUser(7));

    $response = $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-orders.5',
    ]);

    $response->assertOk()->assertJsonStructure(['auth']);

    $claims = (new TokenSigner('route-secret'))->verify($response->json('auth'));

    expect($claims['purpose'])->toBe('subscribe')
        ->and($claims['channel'])->toBe('/private/orders/5')
        ->and($claims['uid'])->toBe('7')
        ->and($claims['exp'])->toBeGreaterThanOrEqual(time() + 3590)->toBeLessThanOrEqual(time() + 3610);
});

it('denies access when the channel callback returns false', function (): void {
    Broadcast::channel('orders.{id}', static fn ($user, $id): bool => false);

    $this->actingAs(new TestUser(7));

    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-orders.5'])
        ->assertForbidden();
});

it('denies access for guests', function (): void {
    Broadcast::channel('orders.{id}', static fn ($user, $id): bool => true);

    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-orders.5'])
        ->assertForbidden();
});

it('honours resolveUidUsing for the uid claim', function (): void {
    Broadcast::channel('orders.{id}', static fn ($user, $id): bool => true);
    AppSyncEvents::resolveUidUsing(static fn (object $user): string => 'custom-'.$user->id);

    $this->actingAs(new TestUser(7));

    $response = $this->postJson('/broadcasting/auth', ['channel_name' => 'private-orders.5']);

    $claims = (new TokenSigner('route-secret'))->verify($response->json('auth'));

    expect($claims['uid'])->toBe('custom-7');
});

it('honours the uid_resolver invokable class-string', function (): void {
    Broadcast::channel('orders.{id}', static fn ($user, $id): bool => true);
    config()->set('appsync-events.uid_resolver', NameUidResolver::class);

    $this->actingAs(new TestUser(7, 'jane'));

    $response = $this->postJson('/broadcasting/auth', ['channel_name' => 'private-orders.5']);

    $claims = (new TokenSigner('route-secret'))->verify($response->json('auth'));

    expect($claims['uid'])->toBe('name-jane');
});

it('honours a custom subscribe_ttl for the exp claim', function (): void {
    config()->set('appsync-events.tokens.subscribe_ttl', 120);
    Broadcast::channel('orders.{id}', static fn ($user, $id): bool => true);

    $this->actingAs(new TestUser(7));

    $response = $this->postJson('/broadcasting/auth', ['channel_name' => 'private-orders.5']);

    $claims = (new TokenSigner('route-secret'))->verify($response->json('auth'));

    expect($claims['exp'])->toBeGreaterThanOrEqual(time() + 110)->toBeLessThanOrEqual(time() + 130);
});
