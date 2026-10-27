<?php

declare(strict_types=1);

/**
 * Prueba del portal web (/admin) como lo usaría un navegador, contra una base
 * de pruebas que tenga (o pueda crear) el administrador ruben / secreto1.
 *
 *   php tests/portal_test.php http://127.0.0.1:8080
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$jar = tempnam(sys_get_temp_dir(), 'portal');
$failures = 0;

function web(string $method, string $path, array $form = [], bool $multipart = false): array
{
    global $base, $jar;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $form : http_build_query($form));
    }
    $raw = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $size), $m);
    return [$status, substr($raw, $size), $m[1] ?? null];
}

function api(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', $token ? "Authorization: Bearer $token" : null]),
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $raw = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) $raw, true) ?? []];
}

function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  OK   ' : '  FALLA ') . $label . "\n";
    if (!$ok) {
        $failures++;
        echo '        ' . substr(is_string($detail) ? strip_tags($detail) : json_encode($detail), 0, 300) . "\n";
    }
}

function csrf(string $html): string
{
    preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $m);
    return $m[1] ?? '';
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// Preparación: administrador y una factura con dos pagos.
[, $status] = api('GET', '/status');
if ($status['needs_setup'] ?? false) {
    api('POST', '/auth/setup', ['business_name' => 'Pruebas', 'name' => 'Rubén', 'username' => 'ruben', 'password' => 'secreto1']);
}
[, $s] = api('POST', '/auth/login', ['username' => 'ruben', 'password' => 'secreto1']);
$token = $s['access_token'] ?? exit("No se pudo entrar como ruben.\n");
$invoiceId = uuid();
$paymentId = uuid();
api('POST', '/sync', ['cursor' => 0, 'changes' => [
    ['type' => 'invoice_upsert', 'data' => ['id' => $invoiceId, 'issue_date' => '2026-10-01', 'client_name' => 'Cliente portal',
        'tax_rate' => 0, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100]]]],
    ['type' => 'payment_add', 'data' => ['id' => $paymentId, 'invoice_id' => $invoiceId, 'amount' => 40, 'date' => '2026-10-02']],
]], $token);

echo "Acceso\n";
[$st, , $loc] = web('GET', '/admin');
check('sin sesión redirige al login', $st === 302 && str_ends_with((string) $loc, '/admin/login'), [$st, $loc]);
[$st, $html] = web('GET', '/admin/login');
check('el login se muestra', $st === 200 && str_contains($html, 'Entra con tu usuario'));
[$st] = web('POST', '/admin/login', ['username' => 'ruben', 'password' => 'secreto1']);
check('sin token CSRF se rechaza (403)', $st === 403);
$token1 = csrf($html);
[$st, $html] = web('POST', '/admin/login', ['_csrf' => $token1, 'username' => 'ruben', 'password' => 'mala']);
check('contraseña incorrecta', str_contains($html, 'incorrectos'), $html);
[$st, , $loc] = web('POST', '/admin/login', ['_csrf' => $token1, 'username' => 'ruben', 'password' => 'secreto1']);
check('el administrador entra', $st === 302 && preg_match('#/admin/(resumen)?$#', (string) $loc) === 1, [$st, $loc]);

echo "Resumen y facturas\n";
[$st, $html] = web('GET', '/admin/resumen');
check('resumen con "Por cobrar"', $st === 200 && str_contains($html, 'Por cobrar'), $html);
$csrf = csrf($html);
[$st, $html] = web('GET', '/admin/facturas?q=portal');
check('búsqueda encuentra la factura', str_contains($html, 'Cliente portal'), $html);
[$st, $html] = web('GET', '/admin/facturas?q=' . urlencode('PORTÁL cliente'));
check('búsqueda sin tildes ni orden de palabras', str_contains($html, 'Cliente portal'), $html);
preg_match('#>(F-0*(\d+))</a>#', $html, $num);
$digits = $num[2] ?? '0';
foreach ([$num[1] ?? 'F-?', $digits, 'f' . $digits, 'F-' . $digits] as $term) {
    [$st, $html] = web('GET', '/admin/facturas?q=' . urlencode($term));
    check("búsqueda por número «{$term}»", str_contains($html, 'Cliente portal'), $html);
}
[$st, $html] = web('GET', '/admin/facturas?q=' . urlencode('rc' . $digits));
check('otro prefijo no coincide', !str_contains($html, 'Cliente portal') && str_contains($html, 'Ninguna factura coincide'), $html);
[$st, $html] = web('GET', '/admin/facturas?q=' . urlencode('100%'));
check('comodines de SQL se toman literal', $st === 200 && !str_contains($html, 'Cliente portal'), $html);
[$st, $html] = web('GET', "/admin/facturas/$invoiceId");
check('detalle muestra el pago y el saldo', str_contains($html, 'RC-') && str_contains($html, '$ 60,00'), $html);

echo "Equipo\n";
$luis = 'luis' . random_int(1000, 9999);
[$st, , $loc] = web('POST', '/admin/equipo/nuevo',
    ['_csrf' => $csrf, 'name' => '<script>alert(1)</script> Luis', 'username' => $luis, 'password' => 'clave123', 'role' => 'member']);
check('crear usuario desde el portal', $st === 302, [$st, $loc]);
[$st, $u] = api('POST', '/auth/login', ['username' => $luis, 'password' => 'clave123']);
check('el usuario nuevo entra en la app', $st === 200 && $u['user']['role'] === 'member', $u);
$luisToken = $u['access_token'] ?? '';
$luisId = $u['user']['id'] ?? 0;
[$st, $html] = web('GET', '/admin/equipo');
check('los nombres se escapan (sin inyección de código)', !str_contains($html, '<script>alert(1)</script>')
    && str_contains($html, '&lt;script&gt;'), $html);
[$st, $html] = web('GET', '/admin/equipo/nuevo');
check('crear un usuario duplicado se rechaza con mensaje',
    str_contains(web('POST', '/admin/equipo/nuevo', ['_csrf' => $csrf, 'name' => 'X', 'username' => $luis,
        'password' => 'clave123', 'role' => 'member'])[1], 'ya existe'));

web('POST', "/admin/equipo/$luisId", ['_csrf' => $csrf, 'name' => 'Luis', 'role' => 'member', 'active' => '1', 'password' => 'nueva456']);
[$st] = api('POST', '/auth/login', ['username' => $luis, 'password' => 'clave123']);
check('restablecer: la contraseña anterior ya no sirve', $st === 401);
[$st] = api('POST', '/auth/login', ['username' => $luis, 'password' => 'nueva456']);
check('restablecer: la nueva funciona', $st === 200);
[$st] = api('GET', '/auth/me', null, $luisToken);
check('restablecer cierra sus sesiones en la app', $st === 401);

web('POST', "/admin/equipo/$luisId", ['_csrf' => $csrf, 'name' => 'Luis', 'role' => 'member', 'password' => '']);
[$st, $r] = api('POST', '/auth/login', ['username' => $luis, 'password' => 'nueva456']);
check('desactivar impide entrar en la app', $st === 403, $r);
[$st, $r] = api('POST', '/auth/login', ['username' => $luis, 'password' => 'nueva456']);

echo "Emisor\n";
$png = sys_get_temp_dir() . '/logo_test.png';
file_put_contents($png, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32));
[$st, , $loc] = web('POST', '/admin/emisor', [
    '_csrf' => $csrf, 'name' => 'Emisor desde portal', 'tax_id' => 'NIT 1', 'address' => '', 'city' => '', 'phone' => '',
    'email' => '', 'bank_account' => '', 'invoice_prefix' => 'F-', 'currency' => '$', 'default_tax_rate' => '19',
    'next_invoice_number' => '', 'logo' => new CURLFile($png, 'image/png', 'logo.png'),
], true);
check('guardar emisor con logo', $st === 302, [$st, $loc]);
[, $sync] = api('POST', '/sync', ['cursor' => 0, 'changes' => []], $token);
check('la app recibe el emisor y el logo', ($sync['issuer']['name'] ?? '') === 'Emisor desde portal'
    && base64_decode($sync['issuer']['logo'] ?? '') === file_get_contents($png), $sync['issuer'] ?? null);

echo "Anular pagos y facturas\n";
[, $html] = web('POST', "/admin/pagos/$paymentId/anular", ['_csrf' => $csrf, 'invoice_id' => $invoiceId, 'reason' => '']);
[, $html] = web('GET', "/admin/facturas/$invoiceId");
check('anular un pago sin motivo se rechaza', str_contains($html, 'motivo') && !str_contains($html, 'Motivo: '), $html);
[$st, , $loc] = web('POST', "/admin/pagos/$paymentId/anular", ['_csrf' => $csrf, 'invoice_id' => $invoiceId, 'reason' => 'Cheque sin fondos']);
check('anular un pago vuelve al detalle', $st === 302 && str_contains((string) $loc, $invoiceId), [$st, $loc]);
[, $html] = web('GET', "/admin/facturas/$invoiceId");
check('el pago queda anulado con su motivo y el saldo vuelve al total',
    str_contains($html, 'Motivo: Cheque sin fondos') && str_contains($html, 'Saldo $ 100,00'), $html);
[$st, $html] = web('GET', '/admin/pagos/' . $paymentId . '/recibo');
check('el recibo del pago anulado lleva el sello ANULADO', str_contains($html, 'ANULADO') && str_contains($html, 'Cheque sin fondos'), $html);
[$st, , $loc] = web('POST', "/admin/facturas/$invoiceId/anular", ['_csrf' => $csrf, 'reason' => 'Factura duplicada']);
[, $html] = web('GET', "/admin/facturas/$invoiceId");
check('anular la factura muestra el aviso con el motivo', str_contains($html, 'Factura anulada') && str_contains($html, 'Factura duplicada'), $html);
check('una factura anulada no ofrece registrar pagos ni editar',
    !str_contains($html, 'Registrar pago o abono') && !str_contains($html, '/editar"'), $html);
[, $html] = web('GET', "/admin/facturas/$invoiceId/imprimir");
check('el PDF de la factura lleva el sello ANULADA', str_contains($html, 'ANULADA') && str_contains($html, 'Factura duplicada'), $html);
[, $html] = web('GET', '/admin/facturas?estado=anuladas');
check('aparece en el filtro Anuladas', str_contains($html, 'Cliente portal'), $html);
[, $html] = web('GET', '/admin/facturas?estado=por-cobrar');
check('y ya no aparece en Por cobrar', !str_contains($html, 'Cliente portal'), $html);
[, $sync] = api('POST', '/sync', ['cursor' => 0, 'changes' => []], $token);
$fromApp = array_values(array_filter($sync['invoices'], fn($x) => $x['id'] === $invoiceId))[0] ?? [];
check('la app recibe la anulación', ($fromApp['voided'] ?? false) === true && ($fromApp['void_reason'] ?? '') === 'Factura duplicada', $fromApp);

echo "Salir\n";
web('POST', '/admin/salir', ['_csrf' => $csrf]);
[$st, , $loc] = web('GET', '/admin/resumen');
check('tras salir pide login otra vez', $st === 302 && str_ends_with((string) $loc, '/admin/login'), [$st, $loc]);

echo $failures === 0 ? "\nTODO CORRECTO\n" : "\n$failures PRUEBA(S) FALLIDA(S)\n";
exit($failures === 0 ? 0 : 1);
