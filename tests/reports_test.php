<?php

declare(strict_types=1);

/**
 * Prueba de los informes con cifras calculadas a mano, contra una base vacía.
 *
 *   php tests/reports_test.php http://127.0.0.1:8080
 */

// La misma zona horaria que usa el servidor por defecto.
date_default_timezone_set('America/Bogota');

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$failures = 0;

function call(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $raw = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) $raw, true) ?? ['raw' => $raw]];
}

function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  OK   ' : '  FALLA ') . $label . "\n";
    if (!$ok) {
        $failures++;
        echo '        ' . json_encode($detail, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Cifra destacada de un informe, por su etiqueta. */
function figure(array $report, string $label): mixed
{
    foreach ($report['summary'] ?? [] as $s) {
        if ($s['label'] === $label) {
            return $s['value'];
        }
    }
    return 'sin cifra';
}

function day(int $offset): string
{
    return date('Y-m-d', strtotime("$offset days"));
}

[$s, $admin] = call('POST', '/auth/setup', ['business_name' => 'Informes', 'name' => 'Rubén', 'username' => 'ruben', 'password' => 'secreto1']);
$token = $admin['access_token'] ?? exit("La base debe estar vacía.\n");

$invoice = function (string $client, string $taxId, string $issue, ?string $due, float $total) use ($token): string {
    $id = uuid();
    call('POST', '/sync', ['cursor' => 0, 'changes' => [['type' => 'invoice_upsert', 'data' => [
        'id' => $id, 'issue_date' => $issue, 'due_date' => $due, 'client_name' => $client, 'client_tax_id' => $taxId,
        'tax_rate' => 0, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => $total]],
    ]]]], $token);
    return $id;
};
$pay = function (string $invoiceId, float $amount, string $date, string $method) use ($token): string {
    $id = uuid();
    call('POST', '/sync', ['cursor' => 0, 'changes' => [['type' => 'payment_add', 'data' => [
        'id' => $id, 'invoice_id' => $invoiceId, 'amount' => $amount, 'date' => $date, 'method' => $method,
    ]]]], $token);
    return $id;
};

// Mismo cliente con la identificación escrita distinto: 1.500 facturados, 400 cobrados.
$a = $invoice('Cliente Uno', 'NIT 1', '2026-01-10', day(-40), 1000);
$pay($a, 400, '2026-01-20', 'Efectivo');
$invoice('CLIENTE UNO S.A.', 'nit-1', '2026-01-15', day(-5), 500);
// Pagada en 5 días, sin vencimiento.
$c = $invoice('Cliente Dos', '', '2026-01-20', null, 300);
$pay($c, 300, '2026-01-25', 'Transferencia');
// Del mes anterior, pagada en enero a los 16 días y a tiempo.
$d = $invoice('Cliente Tres', '', '2025-12-20', '2026-01-30', 200);
$pay($d, 200, '2026-01-05', 'efectivo');
// Anulada con su pago: no debe contar en nada, salvo en anulaciones.
$e = $invoice('Cliente Cuatro', '', '2026-01-22', day(-10), 700);
$pay($e, 100, '2026-01-23', 'Efectivo');
// Pago anulado: el saldo vuelve al total.
$f = $invoice('Cliente Cinco', '', '2026-01-25', day(10), 100);
$payF = $pay($f, 50, '2026-01-26', 'Tarjeta');
call('POST', '/sync', ['cursor' => 0, 'changes' => [
    ['type' => 'payment_void', 'data' => ['id' => $payF, 'reason' => 'Pago duplicado']],
    ['type' => 'invoice_void', 'data' => ['id' => $e, 'reason' => 'Factura repetida']],
]], $token);

$report = function (string $key, ?string $from = '2026-01-01', ?string $to = '2026-01-31', ?string $as = null) use (&$token): array {
    $query = $from === null ? '' : "?from=$from&to=$to";
    return call('GET', "/reports/$key$query", null, $as ?? $token);
};

echo "Facturado vs. recaudado\n";
[$s, $r] = $report('facturado-vs-recaudado');
check('responde', $s === 200 && $r['title'] === 'Facturado vs. recaudado', $r);
check('facturado sin la anulada ni la de otro mes', figure($r, 'Facturado') == 1900, $r['summary']);
check('recaudado sin pagos anulados', figure($r, 'Recaudado') == 900, $r['summary']);
check('diferencia', figure($r, 'Diferencia') == 1000, $r['summary']);
check('porcentaje cobrado de lo facturado', figure($r, 'Ya cobrado de lo facturado') == 36.8, $r['summary']);
check('recaudado de otros periodos', figure($r, 'Recaudado de facturas de otros periodos') == 200, $r['summary']);
check('una fila por mes', $r['rows'] == [['enero 2026', 1900, 900, 1000]], $r['rows']);
[, $r] = $report('facturado-vs-recaudado', '2025-12-15', '2026-01-31');
check('un periodo de dos meses da dos filas', count($r['rows']) === 2 && $r['rows'][0][1] == 200, $r['rows']);

echo "Cartera por cobrar\n";
[, $r] = $report('cartera', null);
check('total, vencido y por vencer', figure($r, 'Total por cobrar') == 1200 && figure($r, 'Vencido') == 1100 && figure($r, 'Por vencer') == 100, $r['summary']);
check('cuenta facturas y clientes', figure($r, 'Facturas abiertas') === 3 && figure($r, 'Clientes') === 2, $r['summary']);
check('agrupa por identificación y ordena por saldo', count($r['rows']) === 2 && $r['rows'][0][1] === 2 && $r['rows'][0][2] == 1500 && $r['rows'][0][3] == 400 && $r['rows'][0][4] == 1100, $r['rows']);
check('no tiene periodo', $r['from'] === null && $r['uses_period'] === false, $r);

echo "Antigüedad\n";
[, $r] = $report('antiguedad', null);
$byRange = array_column($r['rows'], null, 0);
check('cada saldo en su rango', $byRange['Por vencer'][2] == 100 && $byRange['1 a 30 días'][2] == 500
    && $byRange['31 a 60 días'][2] == 600 && $byRange['61 a 90 días'][2] == 0 && $byRange['Más de 90 días'][2] == 0, $r['rows']);
check('porcentaje vencido', figure($r, 'Vencido sobre la cartera') == 91.7, $r['summary']);

echo "Clientes que más deben\n";
[, $r] = $report('deudores', null);
check('solo con facturas vencidas', count($r['rows']) === 1 && $r['rows'][0][2] === 2 && $r['rows'][0][3] == 1100, $r['rows']);
check('muestra el mayor atraso', $r['rows'][0][4] === 40, $r['rows']);
check('la identificación del cliente', in_array($r['rows'][0][1], ['NIT 1', 'nit-1'], true), $r['rows']);

echo "Mejores clientes\n";
[, $r] = $report('mejores-clientes');
check('ordenados por lo facturado', array_column($r['rows'], 2) == [1500, 300, 100], $r['rows']);
check('cobrado y saldo', $r['rows'][0][3] == 400 && $r['rows'][0][4] == 1100 && $r['rows'][1][4] == 0, $r['rows']);
check('participación', $r['rows'][0][5] == 78.9, $r['rows']);

echo "Evolución mensual\n";
[, $r] = $report('evolucion', null);
$monthNames = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$expected = [];
for ($n = 11; $n >= 0; $n--) {
    $t = strtotime(date('Y-m-01') . " -$n months");
    $expected[] = $monthNames[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}
check('12 meses en orden, del más antiguo al actual', array_column($r['rows'], 0) === $expected, array_column($r['rows'], 0));
check('el resumen sigue siendo el del mes actual', figure($r, 'Facturado este mes') == $r['rows'][11][1], $r['summary']);
$jan = array_values(array_filter($r['rows'], fn($x) => $x[0] === 'enero 2026'));
if ($jan === []) {
    echo "  --   enero de 2026 ya no está en los últimos 12 meses\n";
} else {
    check('cifras del mes', $jan[0][1] == 1900 && $jan[0][3] == 900, $jan);
}

echo "Medios de pago\n";
[, $r] = $report('medios-de-pago');
check('agrupa sin distinguir mayúsculas', count($r['rows']) === 2 && $r['rows'][0][1] === 2 && $r['rows'][0][2] == 600 && $r['rows'][0][3] == 66.7, $r['rows']);
check('no cuenta pagos anulados', figure($r, 'Pagos') === 3, $r['summary']);

echo "Tiempo de cobro\n";
[, $r] = $report('tiempo-de-cobro');
check('promedio de días', figure($r, 'Promedio de cobro') == 10.5 && figure($r, 'Facturas cobradas') === 2, $r['summary']);
check('más lento y a tiempo', figure($r, 'Cobro más lento') === 16 && figure($r, 'Pagadas a tiempo') == 100, $r['summary']);
check('por cliente, de más lento a más rápido', array_column($r['rows'], 2) == [16, 5], $r['rows']);

echo "Anulaciones\n";
[, $r] = $report('anulaciones', day(0), day(0));
check('la factura y el pago suelto, sin repetir el pago de la factura', count($r['rows']) === 2, $r['rows']);
check('valores', figure($r, 'Valor de las facturas') == 700 && figure($r, 'Valor de los pagos') == 50, $r['summary']);
$tipos = array_column($r['rows'], null, 1);
check('motivo y quién anuló', $tipos['Factura'][5] === 'Factura repetida' && $tipos['Factura'][6] === 'Rubén'
    && $tipos['Pago'][5] === 'Pago duplicado' && $tipos['Pago'][6] === 'Rubén', $r['rows']);
[, $r] = $report('anulaciones');
check('otro periodo no las incluye', $r['rows'] === [], $r['rows']);

echo "Periodo\n";
[$s, $r] = $report('cartera', '2026-13-01', '2026-01-31');
check('fecha inválida: 422', $s === 422, $r);
[$s, $r] = $report('medios-de-pago', '2026-02-01', '2026-01-31');
check('periodo al revés: 422', $s === 422, $r);
[$s, $r] = $report('medios-de-pago', null);
check('sin fechas, el mes actual', $s === 200 && $r['from'] === date('Y-m-01') && $r['to'] === date('Y-m-t'), $r);

echo "Permisos\n";
[$s] = call('GET', '/reports');
check('sin sesión: 401', $s === 401);
[, $r] = call('GET', '/reports', null, $token);
check('el administrador ve los 9', count($r['reports']) === 9, $r);
check('la sesión lo indica', count($admin['user']['reports']) === 9, $admin['user']);
[$s, $r] = $report('no-existe');
check('informe que no existe: 404', $s === 404, $r);

[$s, $r] = call('POST', '/users', ['name' => 'Ana', 'username' => 'ana', 'password' => 'secreto2', 'role' => 'member', 'reports' => ['cartera']], $token);
check('se crea un miembro con informes', $s === 200 && $r['user']['reports'] === ['cartera'], $r);
$anaId = $r['user']['id'];
[, $login] = call('POST', '/auth/login', ['username' => 'ana', 'password' => 'secreto2']);
$ana = $login['access_token'];
check('la sesión del miembro los trae', $login['user']['reports'] === ['cartera'], $login['user']);
[, $r] = call('GET', '/reports', null, $ana);
check('solo ve el suyo', array_column($r['reports'], 'key') === ['cartera'], $r);
[$s] = $report('cartera', null, null, $ana);
check('abre el permitido', $s === 200);
[$s, $r] = $report('anulaciones', null, null, $ana);
check('otro: 403 con mensaje', $s === 403 && str_contains($r['error'], 'permiso'), $r);

[$s, $r] = call('PATCH', "/users/$anaId", ['reports' => ['no-existe']], $token);
check('un informe desconocido: 422', $s === 422, $r);
[$s, $r] = call('PATCH', "/users/$anaId", ['reports' => [['x']]], $token);
check('una lista con valores raros: 422', $s === 422, $r);
[$s, $r] = call('PATCH', "/users/$anaId", ['reports' => 'cartera'], $token);
check('algo que no es lista: 422', $s === 422, $r);
[$s, $r] = call('PATCH', "/users/$anaId", ['name' => 'Ana María'], $token);
check('cambiar otro dato no toca sus informes', $r['user']['reports'] === ['cartera'], $r);
[$s, $r] = call('PATCH', "/users/$anaId", ['reports' => ['deudores', 'antiguedad']], $token);
check('se guardan en orden de catálogo', $r['user']['reports'] === ['antiguedad', 'deudores'], $r);
[, $r] = call('POST', '/sync', ['cursor' => 0, 'changes' => []], $ana);
check('la sincronización informa del cambio', $r['user']['reports'] === ['antiguedad', 'deudores'], $r['user'] ?? $r);
[$s] = $report('cartera', null, null, $ana);
check('el permiso retirado deja de valer al momento', $s === 403);
call('PATCH', "/users/$anaId", ['reports' => []], $token);
[, $r] = call('GET', '/reports', null, $ana);
check('sin informes la lista queda vacía', $r['reports'] === [], $r);
[, $r] = call('PATCH', "/users/$anaId", ['role' => 'admin'], $token);
check('al ser administrador los ve todos', count($r['user']['reports']) === 9, $r);
[, $r] = call('PATCH', "/users/$anaId", ['role' => 'member'], $token);
check('al dejar de serlo no los conserva', $r['user']['reports'] === [], $r);

[, $other] = call('POST', '/businesses', ['name' => 'Otro negocio', 'include_me' => true], $token);
[, $switched] = call('POST', '/auth/switch', ['business_id' => $other['business']['id']], $token);
[, $r] = $report('cartera', null, null, $switched['access_token']);
check('cada negocio ve solo sus cifras', figure($r, 'Total por cobrar') == 0 && $r['rows'] === [], $r);

echo $failures === 0 ? "\nTODO CORRECTO\n" : "\n$failures PRUEBA(S) FALLIDA(S)\n";
exit($failures === 0 ? 0 : 1);
