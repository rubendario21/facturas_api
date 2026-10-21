<?php

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Auth;
use App\Request;
use App\SyncService;

final class SyncController
{
    private const MAX_CHANGES = 500;

    /**
     * POST /sync  { "cursor": 0, "changes": [ {"type": "...", "data": {...}} ] }
     * Aplica los cambios del dispositivo y devuelve los del servidor.
     */
    public static function sync(Request $request): array
    {
        $user = Auth::requireUser($request);
        $changes = $request->body['changes'] ?? [];
        if (!is_array($changes) || count($changes) > self::MAX_CHANGES) {
            throw new ApiException(422, 'Envía como mucho ' . self::MAX_CHANGES . ' cambios por petición.');
        }
        $cursor = max(0, (int) ($request->body['cursor'] ?? 0));

        $service = new SyncService($user);
        $results = $service->push($changes);
        return ['results' => $results] + $service->pull($cursor);
    }
}
