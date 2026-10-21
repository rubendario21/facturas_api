<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Request;
use App\UserService;

/**
 * Equipo del negocio actual por API (solo administradores). Lo normal es
 * gestionarlo desde el portal web, que usa la misma lógica.
 */
final class UserController
{
    public static function list(Request $request): array
    {
        $admin = Auth::requireAdmin($request);
        return ['users' => array_map([Auth::class, 'publicUser'], UserService::list((int) $admin['business_id']))];
    }

    public static function create(Request $request): array
    {
        $admin = Auth::requireAdmin($request);
        $user = UserService::create(
            (int) $admin['business_id'],
            $request->string('name'),
            $request->string('username'),
            (string) ($request->body['password'] ?? ''),
            $request->string('role', 'member'),
            (bool) ($request->body['web_access'] ?? false),
        );
        return ['user' => Auth::publicUser($user)];
    }

    public static function update(Request $request): array
    {
        $admin = Auth::requireAdmin($request);
        $user = UserService::update((int) $admin['business_id'], $admin, (int) $request->params['id'], $request->body);
        return ['user' => Auth::publicUser($user)];
    }
}
