<?php

declare(strict_types=1);

/**
 * Prueba de extremo a extremo de la API contra una base de datos VACÍA.
 *
 *   php -S 127.0.0.1:8080 -t public public/index.php
 *   php tests/api_test.php http://127.0.0.1:8080
 *
 * ¡No la ejecutes contra el servidor de producción!
 */

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
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$status, json_decode((string) $raw, true) ?? ['raw' => $raw]];
}

function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? "  OK   " : "  FALLA ") . $label . "\n";
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

function sync(string $token, array $changes, int $cursor = 0): array
{
    return call('POST', '/sync', ['cursor' => $cursor, 'changes' => $changes], $token);
}

$invoice = fn(string $id, array $extra = []) => ['type' => 'invoice_upsert', 'data' => $extra + [
    'id' => $id, 'issue_date' => '2026-10-01', 'due_date' => '2026-10-31', 'client_name' => 'Cliente S.A.S.',
    'tax_rate' => 19, 'items' => [['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 100]],
]];
$payment = fn(string $id, string $invoiceId, float $amount) => ['type' => 'payment_add', 'data' => [
    'id' => $id, 'invoice_id' => $invoiceId, 'amount' => $amount, 'date' => '2026-10-05', 'method' => 'Efectivo',
]];

echo "Servidor y alta inicial\n";
[$s, $r] = call('GET', '/status');
check('status indica que falta configurar', $s === 200 && $r['needs_setup'] === true, $r);
[$s, $admin] = call('POST', '/auth/setup', [
    'business_name' => 'Mi Empresa', 'name' => 'Rubén', 'username' => 'ruben', 'password' => 'secreto1',
]);
check('setup crea el administrador', $s === 200 && $admin['user']['role'] === 'admin', $admin);
$adminToken = $admin['access_token'];
[$s] = call('POST', '/auth/setup', ['business_name' => 'X', 'name' => 'X', 'username' => 'otro', 'password' => 'secreto1']);
check('un segundo setup se rechaza (409)', $s === 409);

echo "Login y equipo\n";
[$s] = call('POST', '/auth/login', ['username' => 'ruben', 'password' => 'mala']);
check('contraseña incorrecta: 401', $s === 401);
[$s, $r] = call('POST', '/auth/login', ['username' => 'ruben', 'password' => 'secreto1']);
check('login correcto', $s === 200 && isset($r['access_token']), $r);
[$s, $r] = call('POST', '/users', ['name' => 'Ana', 'username' => 'ana', 'password' => 'secreto2', 'role' => 'member'], $adminToken);
check('admin crea un miembro', $s === 200 && $r['user']['role'] === 'member', $r);
$anaId = $r['user']['id'] ?? 0;
[$s, $r] = call('POST', '/auth/login', ['username' => 'ana', 'password' => 'secreto2']);
$anaToken = $r['access_token'] ?? '';
[$s] = call('GET', '/users', null, $anaToken);
check('un miembro no puede ver usuarios (403)', $s === 403);
[$s] = call('GET', '/auth/me', null, 'token-falso');
check('token falso: 401', $s === 401);

echo "Emisor\n";
[$s, $r] = sync($adminToken, [['type' => 'issuer_update', 'data' => ['name' => 'Mi Empresa', 'tax_id' => '900.123.456-7']]]);
check('admin actualiza el emisor', $r['results'][0]['status'] === 'ok' && $r['issuer']['tax_id'] === '900.123.456-7', $r);
[$s, $r] = sync($anaToken, [['type' => 'issuer_update', 'data' => ['name' => 'Hackeo']]]);
check('miembro no puede cambiar el emisor', $r['results'][0]['status'] === 'rejected', $r);

echo "Facturas y numeración\n";
$inv1 = uuid();
[$s, $r] = sync($adminToken, [$invoice($inv1)]);
check('crear factura asigna F-0001', ($r['results'][0]['invoice']['number'] ?? '') === 'F-0001', $r);
$inv2 = uuid();
$inv3 = uuid();
[$s, $r] = sync($anaToken, [$invoice($inv2, ['legacy_number' => 'F-0007']), $invoice($inv3)]);
check('número migrado F-0007 se respeta', ($r['results'][0]['invoice']['number'] ?? '') === 'F-0007', $r);
check('la siguiente continúa en F-0008', ($r['results'][1]['invoice']['number'] ?? '') === 'F-0008', $r);
[$s, $r] = sync($anaToken, [$invoice($inv1, ['client_name' => 'Cliente Editado'])]);
check('editar factura sin pagos', ($r['results'][0]['invoice']['client_name'] ?? '') === 'Cliente Editado', $r);
[$s, $r] = sync($anaToken, [['type' => 'invoice_upsert', 'data' => ['id' => uuid(), 'issue_date' => 'mal']]]);
check('datos inválidos se rechazan con mensaje', $r['results'][0]['status'] === 'rejected', $r);

echo "Pagos y abonos (total 119,00)\n";
$p1 = uuid();
[$s, $r] = sync($anaToken, [$payment($p1, $inv1, 50)]);
check('abono de 50 con recibo RC-0001', ($r['results'][0]['payment']['receipt_number'] ?? '') === 'RC-0001', $r);
check('la factura sigue sin pagar', ($r['results'][0]['invoice']['paid'] ?? true) === false, $r);
[$s, $r] = sync($anaToken, [$payment($p1, $inv1, 50)]);
check('reenviar el mismo pago no lo duplica', ($r['results'][0]['payment']['receipt_number'] ?? '') === 'RC-0001', $r);
[$s, $r] = sync($anaToken, [$invoice($inv1, ['client_name' => 'Otro'])]);
check('no se edita una factura con abonos', $r['results'][0]['status'] === 'rejected', $r);
[$s, $r] = sync($anaToken, [$payment(uuid(), $inv1, 80)]);
check('pago mayor que el saldo (69) se rechaza', $r['results'][0]['status'] === 'rejected', $r);
$p3 = uuid();
[$s, $r] = sync($adminToken, [$payment($p3, $inv1, 69)]);
check('pago del saldo marca la factura como pagada', ($r['results'][0]['invoice']['paid'] ?? false) === true, $r);
check('con fecha de pago', ($r['results'][0]['invoice']['paid_date'] ?? '') === '2026-10-05', $r);
[$s, $r] = sync($anaToken, [['type' => 'payment_delete', 'data' => ['id' => $p3]]]);
check('miembro no puede eliminar pagos', $r['results'][0]['status'] === 'rejected', $r);
[$s, $r] = sync($adminToken, [['type' => 'payment_delete', 'data' => ['id' => $p3]]]);
check('admin elimina el pago y vuelve a pendiente',
    ($r['results'][0]['payment']['deleted'] ?? false) === true && ($r['results'][0]['invoice']['paid'] ?? true) === false, $r);

echo "Eliminar facturas\n";
[$s, $r] = sync($anaToken, [['type' => 'invoice_delete', 'data' => ['id' => $inv1]]]);
check('no se elimina una factura con pagos', $r['results'][0]['status'] === 'rejected', $r);
[$s, $r] = sync($anaToken, [['type' => 'invoice_delete', 'data' => ['id' => $inv3]]]);
check('se elimina una factura sin pagos', ($r['results'][0]['invoice']['deleted'] ?? false) === true, $r);

echo "Descarga de cambios (otro dispositivo)\n";
[$s, $full] = sync($anaToken, [], 0);
$active = array_filter($full['invoices'], fn($i) => !$i['deleted']);
check('desde cero recibe facturas, pagos y emisor',
    count($active) === 2 && count($full['payments']) === 2 && $full['issuer'] !== null, $full);
[$s, $r] = sync($anaToken, [], $full['cursor']);
check('sin cambios nuevos no recibe nada', $r['invoices'] === [] && $r['payments'] === [] && $r['issuer'] === null, $r);
sync($adminToken, [$invoice(uuid())]);
[$s, $r] = sync($anaToken, [], $full['cursor']);
check('solo recibe lo nuevo', count($r['invoices']) === 1 && $r['invoices'][0]['number'] === 'F-0009', $r);

echo "Sesiones\n";
[$s, $r] = call('POST', '/auth/refresh', ['refresh_token' => $admin['refresh_token']]);
check('renovar sesión', $s === 200 && isset($r['access_token']), $r);
[$s] = call('PATCH', "/users/$anaId", ['active' => false], $adminToken);
[$s] = call('GET', '/auth/me', null, $anaToken);
check('usuario desactivado pierde la sesión', $s === 401);
[$s, $r] = call('PATCH', "/users/{$admin['user']['id']}", ['role' => 'member'], $adminToken);
check('no se puede quitar el último administrador', $s === 422, $r);
for ($i = 0; $i < 5; $i++) {
    call('POST', '/auth/login', ['username' => 'ruben', 'password' => 'mala']);
}
[$s] = call('POST', '/auth/login', ['username' => 'ruben', 'password' => 'secreto1']);
check('tras 5 intentos fallidos se bloquea (429)', $s === 429);

echo $failures === 0 ? "\nTODO CORRECTO\n" : "\n$failures PRUEBA(S) FALLIDA(S)\n";
exit($failures === 0 ? 0 : 1);
