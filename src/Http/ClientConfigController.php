<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Http;

use HDSSolutions\Laravel\AppSyncEvents\AppSyncEventsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serves the client configuration consumed by the JS connector.
 *
 * Registered as the `appsync-events.client-config` route only when
 * `appsync-events.routes.client_config.enabled` is true. Returns the
 * connector configuration for the authenticated user, or a JSON 401 for
 * guests whose middleware lets them reach the controller.
 */
final class ClientConfigController
{
    /**
     * Return the client configuration for the current user.
     *
     * @param  Request  $request  The incoming request.
     * @param  AppSyncEventsManager  $manager  The package manager.
     */
    public function __invoke(Request $request, AppSyncEventsManager $manager): JsonResponse
    {
        $config = $manager->clientConfig($request->user());

        if ($config === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json($config);
    }
}
