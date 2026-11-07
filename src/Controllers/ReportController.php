<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\ReportService;
use App\Request;

final class ReportController
{
    /** GET /reports: los informes que puede ver el usuario. */
    public static function list(Request $request): array
    {
        return ['reports' => ReportService::available(Auth::requireUser($request))];
    }

    /** GET /reports/{key}?from=AAAA-MM-DD&to=AAAA-MM-DD (sin fechas, el mes actual). */
    public static function show(Request $request): array
    {
        $user = Auth::requireUser($request);
        $key = (string) $request->params['key'];
        ReportService::assertAllowed($user, $key);
        [$from, $to] = ReportService::period($_GET['from'] ?? null, $_GET['to'] ?? null);
        return ReportService::build((int) $user['business_id'], $key, $from, $to);
    }
}
