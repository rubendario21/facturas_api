<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\BusinessService;
use App\Request;

/** Negocios de la plataforma por API (solo el superadministrador). */
final class BusinessController
{
    public static function list(Request $request): array
    {
        Auth::requireSuperadmin($request);
        return ['businesses' => array_map([self::class, 'out'], BusinessService::list())];
    }

    /**
     * {name, include_me?: bool, admin?: {name, username, password}}
     * Crea un negocio para un cliente, con su primer administrador.
     */
    public static function create(Request $request): array
    {
        $ctx = Auth::requireSuperadmin($request);
        $admin = is_array($request->body['admin'] ?? null) ? $request->body['admin'] : null;
        $business = BusinessService::create(
            $request->string('name'),
            $ctx,
            (bool) ($request->body['include_me'] ?? true),
            $admin,
        );
        return ['business' => self::out($business)];
    }

    /** {name?, active?} */
    public static function update(Request $request): array
    {
        Auth::requireSuperadmin($request);
        return ['business' => self::out(BusinessService::update((int) $request->params['id'], $request->body))];
    }

    /** {username, role} — añade una cuenta existente al negocio. */
    public static function addMember(Request $request): array
    {
        Auth::requireSuperadmin($request);
        $user = BusinessService::addMemberByUsername(
            (int) $request->params['id'],
            $request->string('username'),
            $request->string('role', 'member'),
        );
        return ['user' => Auth::publicUser($user)];
    }

    private static function out(array $b): array
    {
        return [
            'id' => (int) $b['id'],
            'name' => $b['name'],
            'active' => (bool) $b['active'],
            'members' => isset($b['members']) ? (int) $b['members'] : null,
            'invoices' => isset($b['invoices']) ? (int) $b['invoices'] : null,
        ];
    }
}
