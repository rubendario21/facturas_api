<?php

declare(strict_types=1);

/**
 * Dos dispositivos registran pagos a la vez sobre la misma factura.
 * Ejecutar después de api_test.php (usa el usuario "ruben" que crea esa prueba):
 *
 *   php tests/concurrency_test.php http://127.0.0.1:8080
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');

function request(string $base, string $path, array $body, ?string $token = null): CurlHandle
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', $token ? "Authorization: Bearer $token" : null]),
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    return $ch;
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

$ch = request($base, '/auth/login', ['username' => 'ruben', 'password' => 'secreto1']);
$login = json_decode((string) curl_exec($ch), true);
$token = $login['access_token'] ?? exit("No se pudo iniciar sesión: " . json_encode($login) . "\n");

$invoiceId = uuid();
$ch = request($base, '/sync', ['cursor' => 0, 'changes' => [['type' => 'invoice_upsert', 'data' => [
    'id' => $invoiceId, 'issue_date' => '2026-10-01', 'client_name' => 'Concurrencia', 'tax_rate' => 19,
    'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100]],
]]]], $token);
curl_exec($ch);

// 10 rondas: dos pagos de 60 simultáneos sobre 119 de saldo → solo uno entra.
$failures = 0;
for ($round = 1; $round <= 10; $round++) {
    $id = uuid();
    $ch = request($base, '/sync', ['cursor' => 0, 'changes' => [['type' => 'invoice_upsert', 'data' => [
        'id' => $id, 'issue_date' => '2026-10-01', 'client_name' => "Ronda $round", 'tax_rate' => 19,
        'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100]],
    ]]]], $token);
    curl_exec($ch);

    $multi = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 2; $i++) {
        $handles[$i] = request($base, '/sync', ['cursor' => 0, 'changes' => [['type' => 'payment_add', 'data' => [
            'id' => uuid(), 'invoice_id' => $id, 'amount' => 60, 'date' => '2026-10-02',
        ]]]], $token);
        curl_multi_add_handle($multi, $handles[$i]);
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi);
    } while ($running > 0);

    $statuses = [];
    foreach ($handles as $h) {
        $r = json_decode((string) curl_multi_getcontent($h), true);
        $statuses[] = $r['results'][0]['status'] ?? ('error: ' . json_encode($r));
    }
    sort($statuses);
    $ok = $statuses === ['ok', 'rejected'];
    $failures += $ok ? 0 : 1;
    echo ($ok ? '  OK   ' : '  FALLA ') . "ronda $round: " . implode(' + ', $statuses) . "\n";
}
echo $failures === 0 ? "\nTODO CORRECTO: nunca se aceptaron dos pagos que superan el total\n" : "\n$failures RONDA(S) FALLIDA(S)\n";
exit($failures === 0 ? 0 : 1);
