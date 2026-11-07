<?php

declare(strict_types=1);

namespace App;

/**
 * Informes del negocio. Se calculan solo aquí para que la app y la web muestren
 * siempre los mismos números, en un formato común:
 *
 *   summary: cifras destacadas  [{label, value, type, tone?}]
 *   columns: columnas de la tabla [{label, type}]
 *   rows:    filas, con un valor por columna
 *
 * Tipos: text, money, count, percent, days, date. Las facturas y los pagos
 * anulados no cuentan en ningún informe, salvo en el de anulaciones.
 */
final class ReportService
{
    public const CATALOG = [
        'facturado-vs-recaudado' => ['Facturado vs. recaudado', '¿Cobré lo que vendí en el periodo?', true],
        'cartera' => ['Cartera por cobrar', '¿Cuánto me deben hoy y quién?', false],
        'antiguedad' => ['Cartera por antigüedad', '¿Qué tan atrasada está la deuda?', false],
        'deudores' => ['Clientes que más deben', '¿A quién debo llamar primero?', false],
        'mejores-clientes' => ['Mejores clientes', '¿Quién me compra más?', true],
        'evolucion' => ['Evolución mensual', '¿Voy creciendo? Los últimos 12 meses.', false],
        'medios-de-pago' => ['Recaudo por medio de pago', '¿Cómo me pagan?', true],
        'tiempo-de-cobro' => ['Tiempo promedio de cobro', '¿Cuántos días tardan en pagarme?', true],
        'anulaciones' => ['Anulaciones', '¿Cuánto se anula, quién lo hace y por qué?', true],
    ];

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
        'septiembre', 'octubre', 'noviembre', 'diciembre'];

    // ------------------------------------------------------------- permisos

    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    /** Informes que ve alguien: un administrador, todos; un miembro, los marcados. */
    public static function keysFor(string $role, ?string $stored): array
    {
        if ($role === 'admin') {
            return self::keys();
        }
        return array_values(array_intersect(self::keys(), explode(',', (string) $stored)));
    }

    /** Lista recibida → texto para guardar. Rechaza los informes que no existen. */
    public static function encode(mixed $keys): string
    {
        if (!is_array($keys) || array_filter($keys, fn($k) => !is_string($k) || !isset(self::CATALOG[$k]))) {
            throw new ApiException(422, 'Hay informes que no existen en la lista.');
        }
        return implode(',', array_values(array_intersect(self::keys(), $keys)));
    }

    /** Lista de informes que puede ver el usuario. */
    public static function available(array $user): array
    {
        return array_map(fn($key) => self::info($key), $user['reports'] ?? []);
    }

    public static function assertAllowed(array $user, string $key): void
    {
        if (!isset(self::CATALOG[$key])) {
            throw new ApiException(404, 'Ese informe no existe.');
        }
        if (!in_array($key, $user['reports'] ?? [], true)) {
            throw new ApiException(403, 'No tienes permiso para ver este informe. Pídeselo a un administrador.');
        }
    }

    private static function info(string $key): array
    {
        [$title, $description, $usesPeriod] = self::CATALOG[$key];
        return ['key' => $key, 'title' => $title, 'description' => $description, 'uses_period' => $usesPeriod];
    }

    // -------------------------------------------------------------- periodo

    /** Periodo pedido; sin fechas, el mes actual. */
    public static function period(?string $from, ?string $to): array
    {
        $from = $from === null || $from === '' ? date('Y-m-01') : $from;
        $to = $to === null || $to === '' ? date('Y-m-t') : $to;
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new ApiException(422, 'Indica las fechas del periodo en formato AAAA-MM-DD.');
            }
        }
        if ($from > $to) {
            throw new ApiException(422, 'La fecha inicial no puede ser posterior a la final.');
        }
        return [$from, $to];
    }

    // -------------------------------------------------------------- informes

    public static function build(int $businessId, string $key, string $from, string $to): array
    {
        $report = match ($key) {
            'facturado-vs-recaudado' => self::invoicedVsCollected($businessId, $from, $to),
            'cartera' => self::receivable($businessId),
            'antiguedad' => self::aging($businessId),
            'deudores' => self::debtors($businessId),
            'mejores-clientes' => self::bestClients($businessId, $from, $to),
            'evolucion' => self::evolution($businessId),
            'medios-de-pago' => self::paymentMethods($businessId, $from, $to),
            'tiempo-de-cobro' => self::collectionTime($businessId, $from, $to),
            'anulaciones' => self::voids($businessId, $from, $to),
        };
        $issuer = Database::one('SELECT currency FROM issuer WHERE business_id = ?', [$businessId]);
        return self::info($key) + $report + [
            'from' => self::CATALOG[$key][2] ? $from : null,
            'to' => self::CATALOG[$key][2] ? $to : null,
            'currency' => $issuer['currency'] ?? '$',
            'note' => null,
        ];
    }

    private static function invoicedVsCollected(int $businessId, string $from, string $to): array
    {
        $invoices = array_filter(self::invoices($businessId), fn($i) => self::between($i['issue_date'], $from, $to));
        $payments = self::payments($businessId, $from, $to);
        $invoiced = self::sum($invoices, 'total');
        $collected = self::sum($payments, 'cents');
        $ofPeriod = self::sum($invoices, 'paid');
        $fromOthers = self::sum(array_filter($payments, fn($p) => !self::between($p['issue_date'], $from, $to)), 'cents');

        $months = [];
        for ($m = new \DateTimeImmutable(substr($from, 0, 7) . '-01'); $m->format('Y-m-d') <= $to; $m = $m->modify('+1 month')) {
            $months[$m->format('Y-m')] = [0, 0];
        }
        foreach ($invoices as $i) {
            $months[substr($i['issue_date'], 0, 7)][0] += $i['total'];
        }
        foreach ($payments as $p) {
            $months[substr($p['date'], 0, 7)][1] += $p['cents'];
        }
        $rows = [];
        foreach ($months as $month => [$inv, $col]) {
            $rows[] = [self::monthName($month), self::money($inv), self::money($col), self::money($inv - $col)];
        }

        return [
            'summary' => [
                self::stat('Facturado', self::money($invoiced), 'money'),
                self::stat('Recaudado', self::money($collected), 'money', 'paid'),
                self::stat('Diferencia', self::money($invoiced - $collected), 'money'),
                self::stat('Ya cobrado de lo facturado', self::percent($ofPeriod, $invoiced), 'percent'),
                self::stat('Recaudado de facturas de otros periodos', self::money($fromOthers), 'money'),
            ],
            'columns' => [self::col('Mes'), self::col('Facturado', 'money'), self::col('Recaudado', 'money'), self::col('Diferencia', 'money')],
            'rows' => $rows,
            'empty' => 'No hay facturas ni pagos en este periodo.',
        ];
    }

    private static function receivable(int $businessId): array
    {
        $open = self::open($businessId);
        $overdue = array_filter($open, fn($i) => self::daysLate($i) > 0);
        $rows = [];
        foreach (self::byClient($open) as ['name' => $client, 'list' => $list]) {
            $rows[] = [$client, count($list), self::money(self::sum($list, 'total')), self::money(self::sum($list, 'paid')),
                self::money(self::sum($list, 'balance'))];
        }
        usort($rows, fn($a, $b) => $b[4] <=> $a[4]);
        $balance = self::sum($open, 'balance');
        return [
            'summary' => [
                self::stat('Total por cobrar', self::money($balance), 'money'),
                self::stat('Vencido', self::money(self::sum($overdue, 'balance')), 'money', 'overdue'),
                self::stat('Por vencer', self::money($balance - self::sum($overdue, 'balance')), 'money'),
                self::stat('Facturas abiertas', count($open), 'count'),
                self::stat('Clientes', count($rows), 'count'),
            ],
            'columns' => [self::col('Cliente'), self::col('Facturas', 'count'), self::col('Total', 'money'),
                self::col('Abonado', 'money'), self::col('Saldo', 'money')],
            'rows' => $rows,
            'empty' => 'No hay saldos por cobrar.',
        ];
    }

    private static function aging(int $businessId): array
    {
        $open = self::open($businessId);
        $ranges = ['Por vencer' => [], '1 a 30 días' => [], '31 a 60 días' => [], '61 a 90 días' => [], 'Más de 90 días' => []];
        foreach ($open as $i) {
            $late = self::daysLate($i);
            $ranges[match (true) {
                $late <= 0 => 'Por vencer',
                $late <= 30 => '1 a 30 días',
                $late <= 60 => '31 a 60 días',
                $late <= 90 => '61 a 90 días',
                default => 'Más de 90 días',
            }][] = $i;
        }
        $balance = self::sum($open, 'balance');
        $notDue = self::sum($ranges['Por vencer'], 'balance');
        $rows = [];
        foreach ($ranges as $label => $list) {
            $cents = self::sum($list, 'balance');
            $rows[] = [$label, count($list), self::money($cents), self::percent($cents, $balance)];
        }
        return [
            'summary' => [
                self::stat('Total vencido', self::money($balance - $notDue), 'money', 'overdue'),
                self::stat('Por vencer', self::money($notDue), 'money'),
                self::stat('Vencido sobre la cartera', self::percent($balance - $notDue, $balance), 'percent'),
            ],
            'columns' => [self::col('Atraso'), self::col('Facturas', 'count'), self::col('Saldo', 'money'), self::col('% de la cartera', 'percent')],
            'rows' => $open === [] ? [] : $rows,
            'empty' => 'No hay saldos por cobrar.',
        ];
    }

    private static function debtors(int $businessId): array
    {
        $overdue = array_filter(self::open($businessId), fn($i) => self::daysLate($i) > 0);
        $rows = [];
        foreach (self::byClient($overdue) as ['name' => $client, 'list' => $list]) {
            $rows[] = [$client, $list[0]['client_tax_id'], count($list), self::money(self::sum($list, 'balance')),
                max(array_map([self::class, 'daysLate'], $list))];
        }
        usort($rows, fn($a, $b) => $b[3] <=> $a[3]);
        return [
            'summary' => [
                self::stat('Saldo vencido', self::money(self::sum($overdue, 'balance')), 'money', 'overdue'),
                self::stat('Clientes con mora', count($rows), 'count'),
                self::stat('Quien más debe', $rows[0][0] ?? null, 'text'),
            ],
            'columns' => [self::col('Cliente'), self::col('Identificación'), self::col('Facturas vencidas', 'count'),
                self::col('Saldo vencido', 'money'), self::col('Mayor atraso', 'days')],
            'rows' => $rows,
            'empty' => 'Ningún cliente tiene facturas vencidas.',
        ];
    }

    private static function bestClients(int $businessId, string $from, string $to): array
    {
        $invoices = array_filter(self::invoices($businessId), fn($i) => self::between($i['issue_date'], $from, $to));
        $invoiced = self::sum($invoices, 'total');
        $rows = [];
        foreach (self::byClient($invoices) as ['name' => $client, 'list' => $list]) {
            $total = self::sum($list, 'total');
            $rows[] = [$client, count($list), self::money($total), self::money(self::sum($list, 'paid')),
                self::money(self::sum($list, 'balance')), self::percent($total, $invoiced)];
        }
        usort($rows, fn($a, $b) => $b[2] <=> $a[2]);
        return [
            'summary' => [
                self::stat('Facturado', self::money($invoiced), 'money'),
                self::stat('Clientes', count($rows), 'count'),
                self::stat('Cliente principal', $rows[0][0] ?? null, 'text'),
            ],
            'columns' => [self::col('Cliente'), self::col('Facturas', 'count'), self::col('Facturado', 'money'),
                self::col('Cobrado', 'money'), self::col('Saldo', 'money'), self::col('% del total', 'percent')],
            'rows' => $rows,
            'empty' => 'No hay facturas en este periodo.',
        ];
    }

    private static function evolution(int $businessId): array
    {
        $first = new \DateTimeImmutable(date('Y-m-01'));
        $months = [];
        for ($n = 12; $n >= 0; $n--) {
            $months[$first->modify("-$n months")->format('Y-m')] = [0, 0];
        }
        foreach (self::invoices($businessId) as $i) {
            $m = substr($i['issue_date'], 0, 7);
            if (isset($months[$m])) {
                $months[$m][0] += $i['total'];
            }
        }
        $oldest = array_key_first($months) . '-01';
        foreach (self::payments($businessId, $oldest, date('Y-m-t')) as $p) {
            $months[substr($p['date'], 0, 7)][1] += $p['cents'];
        }

        // El mes 13 solo sirve para calcular la variación del más antiguo.
        $rows = [];
        $previous = null;
        foreach ($months as $m => [$inv, $col]) {
            if ($previous !== null) {
                $rows[] = [self::monthName($m), self::money($inv), self::change($inv, $previous[0]),
                    self::money($col), self::change($col, $previous[1])];
            }
            $previous = [$inv, $col];
        }
        $average = array_sum(array_column(array_slice(array_values($months), 1), 0)) / 12;
        $current = $rows[11];
        return [
            'summary' => [
                self::stat('Facturado este mes', $current[1], 'money'),
                self::stat('Frente al mes anterior', $current[2], 'percent'),
                self::stat('Recaudado este mes', $current[3], 'money', 'paid'),
                self::stat('Frente al mes anterior', $current[4], 'percent'),
                self::stat('Promedio facturado al mes', round($average / 100, 2), 'money'),
            ],
            'columns' => [self::col('Mes'), self::col('Facturado', 'money'), self::col('Variación', 'percent'),
                self::col('Recaudado', 'money'), self::col('Variación', 'percent')],
            'rows' => $rows,
            'empty' => '',
            'note' => 'El mes actual sigue en curso: compáralo con cuidado.',
        ];
    }

    private static function paymentMethods(int $businessId, string $from, string $to): array
    {
        $payments = self::payments($businessId, $from, $to);
        $collected = self::sum($payments, 'cents');
        $groups = [];
        foreach ($payments as $p) {
            $name = trim($p['method']) === '' ? 'Sin indicar' : trim($p['method']);
            $groups[mb_strtolower($name)]['name'] ??= $name;
            $groups[mb_strtolower($name)]['list'][] = $p;
        }
        $rows = [];
        foreach ($groups as $g) {
            $cents = self::sum($g['list'], 'cents');
            $rows[] = [$g['name'], count($g['list']), self::money($cents), self::percent($cents, $collected)];
        }
        usort($rows, fn($a, $b) => $b[2] <=> $a[2]);
        return [
            'summary' => [
                self::stat('Recaudado', self::money($collected), 'money', 'paid'),
                self::stat('Pagos', count($payments), 'count'),
                self::stat('Medio principal', $rows[0][0] ?? null, 'text'),
            ],
            'columns' => [self::col('Medio de pago'), self::col('Pagos', 'count'), self::col('Valor', 'money'), self::col('% del total', 'percent')],
            'rows' => $rows,
            'empty' => 'No hay pagos en este periodo.',
        ];
    }

    private static function collectionTime(int $businessId, string $from, string $to): array
    {
        $paid = array_filter(self::invoices($businessId),
            fn($i) => $i['paid_flag'] && $i['paid_date'] !== null && self::between($i['paid_date'], $from, $to));
        $days = fn(array $i) => max(0, self::daysBetween($i['issue_date'], $i['paid_date']));
        $withDue = array_filter($paid, fn($i) => $i['due_date'] !== null);
        $onTime = array_filter($withDue, fn($i) => $i['paid_date'] <= $i['due_date']);

        $rows = [];
        foreach (self::byClient($paid) as ['name' => $client, 'list' => $list]) {
            $d = array_map($days, $list);
            $rows[] = [$client, count($list), round(array_sum($d) / count($d), 1), max($d)];
        }
        usort($rows, fn($a, $b) => $b[2] <=> $a[2]);
        $all = array_map($days, $paid);
        return [
            'summary' => [
                self::stat('Promedio de cobro', $all ? round(array_sum($all) / count($all), 1) : null, 'days'),
                self::stat('Facturas cobradas', count($paid), 'count'),
                self::stat('Pagadas a tiempo', self::percent(count($onTime), count($withDue)), 'percent', 'paid'),
                self::stat('Cobro más lento', $all ? max($all) : null, 'days'),
            ],
            'columns' => [self::col('Cliente'), self::col('Facturas', 'count'), self::col('Días promedio', 'days'), self::col('Mayor demora', 'days')],
            'rows' => $rows,
            'empty' => 'Ninguna factura se terminó de pagar en este periodo.',
            'note' => '"A tiempo" solo tiene en cuenta las facturas con fecha de vencimiento.',
        ];
    }

    private static function voids(int $businessId, string $from, string $to): array
    {
        $invoices = [];
        foreach (self::invoices($businessId, true) as $i) {
            if ($i['voided_at'] !== null && self::between(substr($i['voided_at'], 0, 10), $from, $to)) {
                $invoices[] = $i;
            }
        }
        // Los pagos anulados junto con su factura ya cuentan dentro de ella.
        $payments = Database::all(
            'SELECT p.amount, p.receipt_number, p.voided_at, p.void_reason, i.client_name, u.name AS voider
             FROM payments p JOIN invoices i ON i.id = p.invoice_id LEFT JOIN users u ON u.id = p.voided_by
             WHERE p.business_id = ? AND p.deleted_at IS NULL AND p.voided_at IS NOT NULL
               AND DATE(p.voided_at) BETWEEN ? AND ? AND (i.voided_at IS NULL OR p.voided_at < i.voided_at)',
            [$businessId, $from, $to],
        );
        $names = array_column(Database::all('SELECT id, name FROM users WHERE id IN (
            SELECT voided_by FROM invoices WHERE business_id = ? AND voided_by IS NOT NULL)', [$businessId]), 'name', 'id');

        $rows = [];
        foreach ($invoices as $i) {
            $rows[] = [substr($i['voided_at'], 0, 10), 'Factura', $i['number'], $i['client_name'], self::money($i['total']),
                $i['void_reason'], $names[$i['voided_by']] ?? null, $i['voided_at']];
        }
        $paymentCents = 0;
        foreach ($payments as $p) {
            $cents = (int) round($p['amount'] * 100);
            $paymentCents += $cents;
            $rows[] = [substr($p['voided_at'], 0, 10), 'Pago', $p['receipt_number'], $p['client_name'], self::money($cents),
                $p['void_reason'], $p['voider'], $p['voided_at']];
        }
        usort($rows, fn($a, $b) => strcmp($b[7], $a[7]));
        return [
            'summary' => [
                self::stat('Facturas anuladas', count($invoices), 'count'),
                self::stat('Valor de las facturas', self::money(self::sum($invoices, 'total')), 'money', 'overdue'),
                self::stat('Pagos anulados', count($payments), 'count'),
                self::stat('Valor de los pagos', self::money($paymentCents), 'money', 'overdue'),
            ],
            'columns' => [self::col('Fecha', 'date'), self::col('Tipo'), self::col('Número'), self::col('Cliente'),
                self::col('Valor', 'money'), self::col('Motivo'), self::col('Anuló')],
            'rows' => array_map(fn($r) => array_slice($r, 0, 7), $rows),
            'empty' => 'No hubo anulaciones en este periodo.',
            'note' => 'Los pagos anulados junto con su factura no se listan aparte.',
        ];
    }

    // ---------------------------------------------------------------- datos

    /** Facturas con total, pagado y saldo en centavos. Sin anuladas, salvo que se pidan. */
    private static function invoices(int $businessId, bool $withVoided = false): array
    {
        $paid = array_column(Database::all(
            'SELECT invoice_id, COALESCE(SUM(ROUND(amount * 100)), 0) AS c FROM payments
             WHERE business_id = ? AND deleted_at IS NULL AND voided_at IS NULL GROUP BY invoice_id',
            [$businessId],
        ), 'c', 'invoice_id');
        $rows = Database::all(
            'SELECT * FROM invoices WHERE business_id = ? AND deleted_at IS NULL'
            . ($withVoided ? '' : ' AND voided_at IS NULL') . ' ORDER BY issue_date DESC, number DESC',
            [$businessId],
        );
        return array_map(function (array $r) use ($paid) {
            $total = SyncService::totalCents($r);
            $cents = (int) ($paid[$r['id']] ?? 0);
            // "paid" pasa a ser lo pagado en centavos; el estado queda en "paid_flag".
            return ['total' => $total, 'paid' => $cents, 'balance' => max(0, $total - $cents), 'paid_flag' => (bool) $r['paid']] + $r;
        }, $rows);
    }

    private static function open(int $businessId): array
    {
        return array_filter(self::invoices($businessId), fn($i) => !$i['paid_flag']);
    }

    /** Pagos vigentes del periodo, en centavos. */
    private static function payments(int $businessId, string $from, string $to): array
    {
        return array_map(fn($p) => $p + ['cents' => (int) round($p['amount'] * 100)], Database::all(
            'SELECT p.amount, p.date, p.method, i.issue_date FROM payments p JOIN invoices i ON i.id = p.invoice_id
             WHERE p.business_id = ? AND p.deleted_at IS NULL AND p.voided_at IS NULL AND i.deleted_at IS NULL
               AND p.date BETWEEN ? AND ?',
            [$businessId, $from, $to],
        ));
    }

    /**
     * Agrupa por cliente: por identificación si la tiene ("NIT 1" y "nit-1" son
     * el mismo) y si no por nombre. Se muestra el nombre de su factura más reciente.
     */
    private static function byClient(array $invoices): array
    {
        $groups = [];
        foreach ($invoices as $i) {
            $tax = preg_replace('/[^a-z0-9]/', '', mb_strtolower($i['client_tax_id']));
            $id = $tax !== '' ? "t:$tax" : 'n:' . mb_strtolower(trim($i['client_name']));
            $groups[$id]['name'] ??= $i['client_name'];
            $groups[$id]['list'][] = $i;
        }
        return array_values($groups);
    }

    private static function daysLate(array $invoice): int
    {
        return $invoice['due_date'] === null ? 0 : self::daysBetween($invoice['due_date'], date('Y-m-d'));
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }

    private static function between(string $date, string $from, string $to): bool
    {
        return $date >= $from && $date <= $to;
    }

    private static function sum(array $rows, string $field): int
    {
        return (int) array_sum(array_column($rows, $field));
    }

    private static function money(int $cents): float
    {
        return $cents / 100;
    }

    private static function percent(int|float $part, int|float $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    /** Variación frente al mes anterior; sin base con la que comparar, null. */
    private static function change(int $now, int $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    private static function monthName(string $yearMonth): string
    {
        [$year, $month] = explode('-', $yearMonth);
        return self::MONTHS[(int) $month - 1] . " $year";
    }

    private static function stat(string $label, mixed $value, string $type, ?string $tone = null): array
    {
        return ['label' => $label, 'value' => $value, 'type' => $type, 'tone' => $tone];
    }

    private static function col(string $label, string $type = 'text'): array
    {
        return ['label' => $label, 'type' => $type];
    }
}
