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
[, $html] = $web->go('GET', "/admin/facturas/$webInvoice");
check('sin permiso no se ofrece anular', !str_contains($html, 'void-form'), $html);
$grant = function (bool $can) use ($adminA): void {
    [, $team] = $adminA->go('GET', '/admin/equipo');
    preg_match('#/admin/equipo/(\d+)">Web A#', $team, $u);
    $adminA->go('GET', '/admin/equipo/' . ($u[1] ?? '0'));
    $adminA->go('POST', '/admin/equipo/' . ($u[1] ?? '0'),
        ['role' => 'member', 'web_access' => '1', 'active' => '1'] + ($can ? ['can_void' => '1'] : []));
};
[, $form] = $adminA->go('GET', '/admin/equipo/nuevo');
check('el formulario de usuario tiene el check de anular', str_contains($form, 'name="can_void"'), $form);
$grant(true);
[, $team] = $adminA->go('GET', '/admin/equipo');
check('el equipo muestra quién puede anular', str_contains($team, 'Puede anular'), $team);
[, $html] = $web->go('GET', "/admin/facturas/$webInvoice");
check('con permiso se ofrece anular', str_contains($html, 'void-form'), $html);
[$st] = $web->go('POST', '/admin/pagos/' . ($pm[1] ?? '') . '/anular', ['invoice_id' => $webInvoice, 'reason' => 'Cobrado por error']);
[, $html] = $web->go('GET', "/admin/facturas/$webInvoice");
check('con permiso anula un pago desde la web', $st === 302 && str_contains($html, 'Cobrado por error'), [$st, $html]);
$grant(false);
[$st] = $web->go('POST', "/admin/facturas/$webInvoice/anular", ['reason' => 'Prueba']);
check('al quitar el permiso vuelve a 403', $st === 403);

echo "Permiso de informes en la web\n";
[, $html] = $web->go('GET', '/admin/resumen');
check('sin informes no aparece la sección', !str_contains($html, '/admin/informes'), $html);
[$st] = $web->go('GET', '/admin/informes');
check('ni se puede abrir la lista (403)', $st === 403);
[$st] = $web->go('GET', '/admin/informes/cartera');
check('ni un informe (403)', $st === 403);
[, $team] = $adminA->go('GET', '/admin/equipo');
preg_match('#/admin/equipo/(\d+)">Web A#', $team, $u);
[, $form] = $adminA->go('GET', '/admin/equipo/' . ($u[1] ?? '0'));
check('el formulario ofrece los 9 informes', substr_count($form, 'name="reports[]"') === 9, $form);
$adminA->go('POST', '/admin/equipo/' . ($u[1] ?? '0'), ['role' => 'member', 'web_access' => '1', 'active' => '1', 'reports' => ['cartera', 'deudores']]);
[, $team] = $adminA->go('GET', '/admin/equipo');
check('el equipo muestra cuántos informes ve', str_contains($team, '2 informes'), $team);
[, $form] = $adminA->go('GET', '/admin/equipo/' . ($u[1] ?? '0'));
check('el formulario recuerda los marcados', substr_count($form, 'data-report checked') === 2, $form);
[$st, $html] = $web->go('GET', '/admin/informes');
check('ve solo los informes permitidos', $st === 200 && substr_count($html, 'class="report-card"') === 2 && str_contains($html, '/admin/informes/cartera'), [$st, $html]);
[$st] = $web->go('GET', '/admin/informes/cartera');
check('abre un informe permitido', $st === 200);
[$st] = $web->go('GET', '/admin/informes/anulaciones');
check('y no uno sin permiso (403)', $st === 403);
[, $r] = api('POST', '/sync', ['cursor' => 0, 'changes' => []], $a['access_token']);
$fromWeb = array_values(array_filter($r['invoices'], fn($i) => $i['number'] === 'F-0002'));
check('la app recibe la factura creada en la web', ($fromWeb[0]['client_name'] ?? '') === 'Cliente web', $r['invoices']);

echo "Superadministrador\n";
[$st, $html] = $super->go('POST', '/admin/negocio-actual', ['business_id' => $bId]);
[, $html] = $super->go('GET', '/admin/resumen');
check('el superadmin entra en la web a un negocio del que no es miembro', str_contains($html, 'Cliente B'), $html);
$aId = $ids['Cliente A'];
echo "Quitar personas del negocio\n";
$adminA->go('GET', '/admin/equipo/nuevo');
$adminA->go('POST', '/admin/equipo/nuevo', ['name' => 'Temp A', 'username' => 'tempA', 'password' => 'claveT1', 'role' => 'member', 'web_access' => '1']);
$temp = new Browser($base);
[$st] = $temp->login('tempA', 'claveT1');
check('la persona entra a la web', $st === 302);
[, $tempApi] = api('POST', '/auth/login', ['username' => 'tempA', 'password' => 'claveT1']);
[, $team] = $adminA->go('GET', '/admin/equipo');
preg_match('#/admin/equipo/(\d+)">Temp A#', $team, $t);
$tempId = $t[1] ?? '0';
[, $form] = $adminA->go('GET', "/admin/equipo/$tempId");
check('su ficha ofrece quitarla', str_contains($form, "/admin/equipo/$tempId/quitar"), $form);
$web->go('GET', '/admin/resumen');
[$st] = $web->go('POST', "/admin/equipo/$tempId/quitar");
check('un miembro no puede quitar a nadie (403)', $st === 403);
[$st, , $loc] = $adminA->go('POST', "/admin/equipo/$tempId/quitar");
check('el administrador la quita', $st === 302 && str_ends_with((string) $loc, '/admin/equipo'), [$st, $loc]);
[, $team] = $adminA->go('GET', '/admin/equipo');
check('avisa y ya no aparece en el equipo', str_contains($team, 'ya no pertenece') && !str_contains($team, 'Temp A">') && !str_contains($team, 'tempA'), $team);
[$st, , $loc] = $temp->go('GET', '/admin/resumen');
check('se cierra su sesión web', $st === 302 && str_ends_with((string) $loc, '/admin/login'), [$st, $loc]);
[$st] = api('GET', '/auth/me', null, $tempApi['access_token'] ?? '');
check('y la de la app', $st === 401);
[$st] = api('POST', '/auth/login', ['username' => 'tempA', 'password' => 'claveT1']);
check('sin negocio no puede volver a entrar (403)', $st === 403);

$adminAId = (int) ($a['user']['id'] ?? 0);
[, $form] = $adminA->go('GET', "/admin/equipo/$adminAId");
check('en su propia ficha no se ofrece', !str_contains($form, '/quitar"'), $form);
[$st, , $loc] = $adminA->go('POST', "/admin/equipo/$adminAId/quitar");
[, $form] = $adminA->go('GET', "/admin/equipo/$adminAId");
check('nadie se quita a sí mismo', $st === 302 && str_contains($form, 'No puedes quitarte'), $form);

$super->go('GET', "/admin/negocios/$aId");
$super->go('POST', "/admin/negocios/$aId/miembros", ['username' => 'ruben', 'role' => 'admin']);
[$st, $me] = api('POST', '/auth/login', ['username' => 'ruben', 'password' => 'secreto1']);
$rubenId = (int) ($me['user']['id'] ?? 0);
[, $form] = $adminA->go('GET', "/admin/equipo/$rubenId");
check('el admin del cliente no ve la opción con el superadmin', !str_contains($form, '/quitar"'), $form);
$adminA->go('POST', "/admin/equipo/$rubenId/quitar");
[, $form] = $adminA->go('GET', "/admin/equipo/$rubenId");
check('ni puede quitarlo', str_contains($form, 'Solo el superadministrador puede quitar'), $form);
[, $html] = $super->go('GET', "/admin/negocios/$aId");
check('el superadmin ve Quitar en cada persona', str_contains($html, "/miembros/$rubenId/quitar") && str_contains($html, "/miembros/$adminAId/quitar"), $html);
$super->go('POST', "/admin/negocios/$aId/miembros/$rubenId/quitar");
[, $html] = $super->go('GET', "/admin/negocios/$aId");
check('el superadmin se quita de un negocio', str_contains($html, 'ya no pertenece') && !str_contains($html, "/miembros/$rubenId/quitar"), $html);
$super->go('POST', "/admin/negocios/$aId/miembros/$adminAId/quitar");
[, $html] = $super->go('GET', "/admin/negocios/$aId");
check('no se puede dejar el negocio sin administrador', str_contains($html, 'al menos un administrador') && str_contains($html, "/miembros/$adminAId/quitar"), $html);

echo "Copia en Excel y eliminar negocio\n";
[$st, $bytes] = $adminA->go('GET', "/admin/negocios/$aId/copia");
check('el admin del cliente no descarga desde Negocios (403)', $st === 403);
[$st, $bytes] = $super->go('GET', "/admin/negocios/$aId/copia");
check('el superadmin descarga un archivo xlsx', $st === 200 && str_starts_with($bytes, 'PK'), [$st, substr($bytes, 0, 200)]);
$xlsx = tempnam(sys_get_temp_dir(), 'copia');
file_put_contents($xlsx, $bytes);
$zip = new ZipArchive();
check('es un ZIP válido con sus 4 hojas', $zip->open($xlsx) === true && $zip->locateName('xl/worksheets/sheet4.xml') !== false);
$facturas = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
$detalle = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
$pagos = (string) $zip->getFromName('xl/worksheets/sheet4.xml');
check('la cabecera trae la factura del cliente', str_contains($facturas, 'Cliente web') && str_contains($facturas, 'F-0002'), substr($facturas, 0, 300));
check('el detalle trae sus conceptos', str_contains($detalle, 'F-0002') && str_contains($detalle, 'Descripción'), substr($detalle, 0, 300));
check('los pagos incluyen el anulado con su motivo', str_contains($pagos, 'RC-0001') && str_contains($pagos, 'Anulado') && str_contains($pagos, 'Cobrado por error'), substr($pagos, 0, 400));
check('todos los XML son válidos', (function () use ($zip) {
    for ($n = 0; $n < $zip->numFiles; $n++) {
        if (simplexml_load_string((string) $zip->getFromIndex($n)) === false) {
            return false;
        }
    }
    return true;
})());
$zip->close();
unlink($xlsx);
[$st] = $super->go('GET', '/admin/negocios/999999/copia');
check('copia de un negocio que no existe: 404', $st === 404);

$super->go('GET', '/admin/negocios/nuevo');
$super->go('POST', '/admin/negocios/nuevo', ['name' => 'Negocio a borrar', 'admin_name' => 'Solo Aquí', 'admin_username' => 'soloaqui', 'admin_password' => 'claveS1']);
[, $all] = api('GET', '/businesses', null, $s['access_token']);
$delId = array_column($all['businesses'] ?? [], 'id', 'name')['Negocio a borrar'] ?? 0;
check('se crea el negocio de prueba', $delId > 0, $all);
[, $solo] = api('POST', '/auth/login', ['username' => 'soloaqui', 'password' => 'claveS1']);
$delInvoice = uuid();
api('POST', '/sync', ['cursor' => 0, 'changes' => [
    ['type' => 'invoice_upsert', 'data' => ['id' => $delInvoice, 'issue_date' => '2026-10-01', 'client_name' => 'Cliente a borrar', 'tax_rate' => 0, 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 50]]]],
    ['type' => 'payment_add', 'data' => ['id' => uuid(), 'invoice_id' => $delInvoice, 'amount' => 20, 'date' => '2026-10-02']],
]], $solo['access_token'] ?? '');
$adminA->go('GET', '/admin/resumen');
$super->go('GET', "/admin/negocios/$delId");
$super->go('POST', "/admin/negocios/$delId/miembros", ['username' => 'adminA', 'role' => 'member']);
[, $form] = $super->go('GET', "/admin/negocios/$delId");
check('la ficha ofrece descargar y eliminar', str_contains($form, "/negocios/$delId/copia") && str_contains($form, "/negocios/$delId/eliminar"), $form);
[$st] = $adminA->go('POST', "/admin/negocios/$delId/eliminar", ['confirm_name' => 'Negocio a borrar']);
check('el admin de un cliente no puede eliminar (403)', $st === 403);
[$st, $html] = $super->go('POST', "/admin/negocios/$delId/eliminar", ['confirm_name' => 'otro nombre']);
check('sin el nombre exacto no se elimina', $st === 200 && str_contains($html, 'nombre exacto'), [$st, $html]);
[$st] = api('GET', '/auth/me', null, $solo['access_token'] ?? '');
check('y el negocio sigue funcionando', $st === 200);
[$st, , $loc] = $super->go('POST', "/admin/negocios/$delId/eliminar", ['confirm_name' => ' Negocio a borrar ']);
check('con el nombre exacto se elimina', $st === 302 && str_ends_with((string) $loc, '/admin/negocios'), [$st, $loc]);
[, $html] = $super->go('GET', '/admin/negocios');
check('avisa qué borró y ya no aparece', str_contains($html, 'Se eliminó Negocio a borrar: 1 facturas, 1 pagos') && !str_contains($html, "/negocios/$delId\""), $html);
[$st] = api('GET', '/auth/me', null, $solo['access_token'] ?? '');
check('se corta la sesión de su equipo', $st === 401);
[$st] = api('POST', '/auth/login', ['username' => 'soloaqui', 'password' => 'claveS1']);
check('la cuenta que solo trabajaba allí desaparece', $st === 401);
[$st] = api('POST', '/auth/login', ['username' => 'adminA', 'password' => 'claveA1']);
check('la cuenta compartida con otro negocio se conserva', $st === 200);
[, $r] = api('POST', '/sync', ['cursor' => 0, 'changes' => []], $a['access_token']);
check('los datos de los demás negocios siguen intactos', in_array('F-0002', array_column($r['invoices'] ?? [], 'number'), true), $r);
[$st] = $super->go('POST', "/admin/negocios/$delId/eliminar", ['confirm_name' => 'Negocio a borrar']);
check('eliminar otra vez no rompe nada', $st === 302);

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
