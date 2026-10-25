<?php

declare(strict_types=1);

/**
 * Varios negocios: superadministrador, clientes aislados, cambio de negocio,
 * acceso web por persona y facturación desde la web. Base de datos VACÍA:
 *
 *   php tests/multi_business_test.php http://127.0.0.1:8080
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$failures = 0;

/** Navegador con sus propias cookies. */
final class Browser
{
    private string $jar;
    public string $csrf = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'mb');
    }

    public function go(string $method, string $path, array $form = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form + ['_csrf' => $this->csrf]));
        }
        $raw = (string) curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $html = substr($raw, $size);
        preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $size), $m);
        if (preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $c)) {
            $this->csrf = $c[1];
        }
        return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $html, $m[1] ?? null];
    }

    public function login(string $user, string $pass): array
    {
        $this->go('GET', '/admin/login');
        return $this->go('POST', '/admin/login', ['username' => $user, 'password' => $pass]);
    }
}

function api(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', $token ? "Authorization: Bearer $token" : null]),
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
    $raw = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) $raw, true) ?? []];
}

function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  OK   ' : '  FALLA ') . $label . "\n";
    if (!$ok) {
        $failures++;
        echo '        ' . substr(is_string($detail) ? trim(preg_replace('/\s+/', ' ', strip_tags($detail))) : json_encode($detail), 0, 300) . "\n";
    }
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

$invoice = fn(string $client) => ['type' => 'invoice_upsert', 'data' => ['id' => uuid(), 'issue_date' => '2026-10-01',
    'client_name' => $client, 'tax_rate' => 19, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100]]]];

echo "Plataforma\n";
[$st, $s] = api('POST', '/auth/setup', ['business_name' => 'Mi Estudio', 'name' => 'Rubén', 'username' => 'ruben', 'password' => 'secreto1']);
check('el alta inicial crea al superadministrador', ($s['user']['is_superadmin'] ?? false) === true, $s);
$superId = $s['user']['id'];

$super = new Browser($base);
$super->login('ruben', 'secreto1');
[$st, , $loc] = $super->go('POST', '/admin/negocios/nuevo', ['name' => 'Cliente A', 'include_me' => '1',
    'admin_name' => 'Ana A', 'admin_username' => 'adminA', 'admin_password' => 'claveA1']);
check('crear negocio con su administrador lleva a completar el emisor', $st === 302 && str_ends_with((string) $loc, '/admin/emisor'), [$st, $loc]);
$super->go('POST', '/admin/negocios/nuevo', ['name' => 'Cliente B',
    'admin_name' => 'Beto B', 'admin_username' => 'adminB', 'admin_password' => 'claveB1']);
[, $html] = $super->go('GET', '/admin/negocios');
check('la lista muestra los tres negocios', substr_count($html, 'Cliente ') >= 2 && str_contains($html, 'Mi Estudio'), $html);

echo "Negocios y cambio de negocio en la app\n";
[, $s] = api('POST', '/auth/login', ['username' => 'ruben', 'password' => 'secreto1']);
$names = array_column($s['businesses'] ?? [], 'name');
check('el superadmin tiene en la app los negocios en los que es miembro', $names === ['Cliente A', 'Mi Estudio'], $names);
$ids = array_column($s['businesses'], 'id', 'name');
[$st, $sa] = api('POST', '/auth/switch', ['business_id' => $ids['Cliente A']], $s['access_token']);
check('cambiar al negocio A', $st === 200 && $sa['business']['name'] === 'Cliente A', $sa);
[, $all] = api('GET', '/businesses', null, $s['access_token']);
$bId = array_column($all['businesses'] ?? [], 'id', 'name')['Cliente B'] ?? 0;
[$st] = api('POST', '/auth/switch', ['business_id' => $bId], $s['access_token']);
check('no puede cambiar en la app a un negocio del que no es miembro', $st === 403);

echo "Aislamiento entre clientes\n";
[, $a] = api('POST', '/auth/login', ['username' => 'adminA', 'password' => 'claveA1']);
[, $b] = api('POST', '/auth/login', ['username' => 'adminB', 'password' => 'claveB1']);
check('cada administrador entra en su negocio', $a['business']['name'] === 'Cliente A' && $b['business']['name'] === 'Cliente B');
api('POST', '/sync', ['cursor' => 0, 'changes' => [['type' => 'issuer_update', 'data' => ['name' => 'A S.A.S.', 'tax_id' => 'NIT A']]]], $a['access_token']);
$invA = $invoice('Cliente de A');
[, $r] = api('POST', '/sync', ['cursor' => 0, 'changes' => [$invA]], $a['access_token']);
check('la numeración de cada negocio empieza en F-0001', ($r['results'][0]['invoice']['number'] ?? '') === 'F-0001', $r);
[, $r] = api('POST', '/sync', ['cursor' => 0, 'changes' => []], $b['access_token']);
check('B no ve las facturas ni el emisor de A', $r['invoices'] === [] && ($r['issuer']['name'] ?? '') === 'Cliente B', $r);
[, $r] = api('POST', '/sync', ['cursor' => 0, 'changes' => [['type' => 'payment_add', 'data' => [
    'id' => uuid(), 'invoice_id' => $invA['data']['id'], 'amount' => 10, 'date' => '2026-10-02']]]], $b['access_token']);
check('B no puede pagar una factura de A', ($r['results'][0]['status'] ?? '') === 'rejected', $r);
[$st, $r] = api('PATCH', "/users/$superId", ['password' => 'robada1'], $a['access_token']);
check('el admin de un cliente no puede cambiar la contraseña del superadmin', $st === 403, $r);
[$st] = api('POST', '/auth/login', ['username' => 'ruben', 'password' => 'secreto1']);
check('la contraseña del superadmin sigue intacta', $st === 200);

echo "Acceso web por persona\n";
$adminA = new Browser($base);
[$st, , $loc] = $adminA->login('adminA', 'claveA1');
check('el admin del cliente entra a la web', $st === 302, [$st, $loc]);
[, $html] = $adminA->go('GET', '/admin/negocios');
check('el admin del cliente no ve la sección Negocios', !str_contains($html, 'Nuevo negocio'), $html);
$adminA->go('GET', '/admin/equipo/nuevo');
$adminA->go('POST', '/admin/equipo/nuevo', ['name' => 'Web A', 'username' => 'webA', 'password' => 'claveW1', 'role' => 'member', 'web_access' => '1']);
$adminA->go('GET', '/admin/equipo/nuevo');
$adminA->go('POST', '/admin/equipo/nuevo', ['name' => 'App A', 'username' => 'appA', 'password' => 'claveP1', 'role' => 'member']);
[, $html] = (new Browser($base))->login('appA', 'claveP1');
check('un miembro sin acceso web no entra en la web', str_contains($html, 'no tiene acceso web'), $html);
[$st] = api('POST', '/auth/login', ['username' => 'appA', 'password' => 'claveP1']);
check('pero sí en la app', $st === 200);

$web = new Browser($base);
[$st] = $web->login('webA', 'claveW1');
check('un miembro con acceso web entra', $st === 302);
[$st] = $web->go('GET', '/admin/equipo');
check('un miembro no puede gestionar el equipo (403)', $st === 403);

echo "Facturar y cobrar desde la web\n";
$web->go('GET', '/admin/facturas/nueva');
[$st, $html, $loc] = $web->go('POST', '/admin/facturas/nueva', [
    'client_name' => 'Cliente web', 'issue_date' => '2026-10-20', 'due_date' => '2026-11-19', 'tax_rate' => '19',
    'items' => [['description' => 'Diseño', 'quantity' => '2', 'unit_price' => '1.000,50'], ['description' => '', 'quantity' => '1', 'unit_price' => '']],
]);
check('crear factura desde la web', $st === 302 && preg_match('#/admin/facturas/([0-9a-f-]{36})$#', (string) $loc, $m), [$st, $html]);
$webInvoice = $m[1] ?? '';
[, $html] = $web->go('GET', "/admin/facturas/$webInvoice");
check('número siguiente F-0002 y total con IVA (2.381,19)', str_contains($html, 'F-0002') && str_contains($html, '$ 2.381,19'), $html);
$web->go('POST', "/admin/facturas/$webInvoice/pagos", ['amount' => '3000', 'date' => '2026-10-21', 'method' => 'Efectivo']);
[, $html] = $web->go('GET', "/admin/facturas/$webInvoice");
check('un pago mayor que el saldo se rechaza con mensaje', str_contains($html, 'supera el saldo'), $html);
$web->go('POST', "/admin/facturas/$webInvoice/pagos", ['amount' => '1.000,00', 'date' => '2026-10-21', 'method' => 'Transferencia', 'note' => 'Ref 123']);
[, $html] = $web->go('GET', "/admin/facturas/$webInvoice");
check('abono registrado con recibo RC-0001', str_contains($html, 'RC-0001') && str_contains($html, 'Con abonos'), $html);
preg_match('#/admin/pagos/([0-9a-f-]{36})/recibo#', $html, $pm);
[$st, $html] = $web->go('GET', '/admin/pagos/' . ($pm[1] ?? '') . '/recibo');
check('el recibo se puede imprimir', $st === 200 && str_contains($html, 'RECIBO DE PAGO') && str_contains($html, 'Abono a la factura'), $html);
[$st, $html] = $web->go('GET', "/admin/facturas/$webInvoice/imprimir");
check('la factura se puede imprimir', $st === 200 && str_contains($html, 'FACTURA') && str_contains($html, 'Saldo pendiente'), $html);
[$st] = $web->go('POST', '/admin/pagos/' . ($pm[1] ?? '') . '/anular', ['reason' => 'Prueba']);
check('un miembro no puede anular pagos desde la web (403)', $st === 403);
[$st] = $web->go('POST', "/admin/facturas/$webInvoice/anular", ['reason' => 'Prueba']);
check('ni anular facturas (403)', $st === 403);
[, $r] = api('POST', '/sync', ['cursor' => 0, 'changes' => []], $a['access_token']);
$fromWeb = array_values(array_filter($r['invoices'], fn($i) => $i['number'] === 'F-0002'));
check('la app recibe la factura creada en la web', ($fromWeb[0]['client_name'] ?? '') === 'Cliente web', $r['invoices']);

echo "Superadministrador\n";
[$st, $html] = $super->go('POST', '/admin/negocio-actual', ['business_id' => $bId]);
[, $html] = $super->go('GET', '/admin/resumen');
check('el superadmin entra en la web a un negocio del que no es miembro', str_contains($html, 'Cliente B'), $html);
$aId = $ids['Cliente A'];
$super->go('GET', "/admin/negocios/$aId");
$super->go('POST', "/admin/negocios/$aId", ['name' => 'Cliente A']);  // sin "active": se desactiva
[$st] = api('GET', '/auth/me', null, $a['access_token']);
check('desactivar el negocio corta las sesiones de su equipo', $st === 401);
[$st, $r] = api('POST', '/auth/login', ['username' => 'adminA', 'password' => 'claveA1']);
check('y no pueden volver a entrar', $st === 403, $r);
[, $html] = (new Browser($base))->login('webA', 'claveW1');
check('tampoco por la web', str_contains($html, 'no tiene acceso web'), $html);
[$st, $s2] = api('POST', '/auth/login', ['username' => 'ruben', 'password' => 'secreto1']);
check('el superadmin sigue entrando en sus otros negocios', $st === 200 && array_column($s2['businesses'], 'name') === ['Mi Estudio'], $s2);

echo $failures === 0 ? "\nTODO CORRECTO\n" : "\n$failures PRUEBA(S) FALLIDA(S)\n";
exit($failures === 0 ? 0 : 1);
