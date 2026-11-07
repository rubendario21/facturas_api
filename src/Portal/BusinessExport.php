<?php

declare(strict_types=1);

namespace App\Portal;

use App\BusinessService;
use App\Database;
use App\Xlsx;

/** Copia en Excel de lo facturado por un negocio, para entregársela al cliente. */
final class BusinessExport
{
    private const STATUS = ['paid' => 'Pagada', 'partial' => 'Con abonos', 'pending' => 'Pendiente', 'overdue' => 'Vencida', 'voided' => 'Anulada'];

    /** @return array{0: string, 1: string} Nombre del archivo y contenido. */
    public static function build(int $businessId): array
    {
        $business = BusinessService::find($businessId);
        $issuer = Database::one('SELECT * FROM issuer WHERE business_id = ?', [$businessId]);
        $rows = Database::all(
            'SELECT i.*, c.name AS creator, v.name AS voider FROM invoices i
             LEFT JOIN users c ON c.id = i.created_by LEFT JOIN users v ON v.id = i.voided_by
             WHERE i.business_id = ? AND i.deleted_at IS NULL ORDER BY i.issue_date, i.number',
            [$businessId],
        );
        $payments = Database::all(
            'SELECT p.*, c.name AS creator, v.name AS voider FROM payments p
             LEFT JOIN users c ON c.id = p.created_by LEFT JOIN users v ON v.id = p.voided_by
             WHERE p.business_id = ? AND p.deleted_at IS NULL ORDER BY p.date, p.receipt_number',
            [$businessId],
        );
        $byInvoice = [];
        foreach ($payments as $p) {
            $byInvoice[$p['invoice_id']][] = $p;
        }

        $head = [];
        $lines = [];
        $invoices = [];
        $sum = ['total' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
        $count = array_fill_keys(array_keys(self::STATUS), 0);
        foreach ($rows as $row) {
            $i = Portal::summarize($row, $byInvoice[$row['id']] ?? []);
            $invoices[$row['id']] = $i;
            $status = self::STATUS[$i['status']];
            $count[$i['status']]++;
            $subtotal = 0.0;
            foreach (json_decode($row['items'], true) ?: [] as $item) {
                $amount = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                $subtotal += (float) $item['quantity'] * (float) $item['unit_price'];
                $lines[] = [$row['number'], $row['client_name'], $item['description'] ?? '', (float) $item['quantity'], (float) $item['unit_price'], $amount, $status];
            }
            $balance = $i['voided'] ? 0.0 : $i['balance'];
            if (!$i['voided']) {
                $sum['total'] += $i['total'];
                $sum['paid'] += $i['paid_amount'];
                $sum['balance'] += $balance;
            }
            $head[] = [
                $row['number'], $row['issue_date'], $row['due_date'], $row['client_name'], $row['client_tax_id'], $row['client_address'], $row['client_email'],
                round($subtotal, 2), (float) $row['tax_rate'], round($i['total'] - $subtotal, 2), $i['total'], $i['paid_amount'], $balance, $status,
                $row['paid_date'], $row['notes'], $row['void_reason'], $row['voided_at'], $row['voider'], $row['creator'],
            ];
        }

        $paymentRows = [];
        foreach ($payments as $p) {
            $i = $invoices[$p['invoice_id']] ?? null;
            if ($i === null) {
                continue;
            }
            $paymentRows[] = [
                $p['receipt_number'], $i['number'], $i['client_name'], $p['date'], (float) $p['amount'], $p['method'], $p['note'],
                $p['voided_at'] === null ? 'Vigente' : 'Anulado', $p['void_reason'], $p['voided_at'], $p['voider'], $p['creator'],
            ];
        }

        $emitted = count($rows) - $count['voided'];
        $money = fn(float $v) => ['v' => round($v, 2), 't' => 'money'];
        $heading = fn(string $v) => ['v' => $v, 't' => 'heading'];
        $summary = [
            [['v' => 'Copia de facturas y pagos', 't' => 'title']],
            [],
            [$heading('Negocio'), $issuer['name'] ?? $business['name']],
            [$heading('Identificación'), $issuer['tax_id'] ?? ''],
            [$heading('Generada el'), ['v' => date('Y-m-d'), 't' => 'date']],
            [],
            [$heading('Facturas sin contar las anuladas'), ['v' => $emitted, 't' => 'number']],
            [$heading('Total facturado'), $money($sum['total'])],
            [$heading('Total pagado'), $money($sum['paid'])],
            [$heading('Saldo por cobrar'), $money($sum['balance'])],
            [],
            [$heading('Facturas por estado')],
        ];
        foreach (self::STATUS as $key => $label) {
            $summary[] = [$label, ['v' => $count[$key], 't' => 'number']];
        }

        $content = Xlsx::build([
            ['name' => 'Resumen', 'widths' => [36, 34], 'rows' => $summary],
            ['name' => 'Facturas', 'rows' => $head, 'columns' => [
                ['Número', 'text', 12], ['Emisión', 'date', 12], ['Vencimiento', 'date', 12], ['Cliente', 'text', 32], ['Identificación', 'text', 20],
                ['Dirección', 'text', 28], ['Email', 'text', 26], ['Base', 'money', 16], ['IVA %', 'number', 8], ['IVA', 'money', 15], ['Total', 'money', 16],
                ['Pagado', 'money', 16], ['Saldo', 'money', 16], ['Estado', 'text', 12], ['Pagada el', 'date', 12], ['Observaciones', 'text', 30],
                ['Motivo de anulación', 'text', 30], ['Anulada el', 'date', 12], ['Anuló', 'text', 20], ['Creada por', 'text', 20],
            ]],
            ['name' => 'Detalle', 'rows' => $lines, 'columns' => [
                ['Factura', 'text', 12], ['Cliente', 'text', 32], ['Descripción', 'text', 40], ['Cantidad', 'number', 10],
                ['Precio unitario', 'money', 16], ['Importe', 'money', 16], ['Estado de la factura', 'text', 18],
            ]],
            ['name' => 'Pagos', 'rows' => $paymentRows, 'columns' => [
                ['Recibo', 'text', 12], ['Factura', 'text', 12], ['Cliente', 'text', 32], ['Fecha', 'date', 12], ['Importe', 'money', 16], ['Forma de pago', 'text', 16],
                ['Nota', 'text', 28], ['Estado', 'text', 10], ['Motivo de anulación', 'text', 30], ['Anulado el', 'date', 12], ['Anuló', 'text', 20], ['Registrado por', 'text', 20],
            ]],
        ], $issuer['currency'] ?? '$');

        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', self::ascii($business['name'])), '-');
        return ['Facturas-' . ($slug === '' ? 'negocio' : $slug) . '-' . date('Y-m-d') . '.xlsx', $content];
    }

    private static function ascii(string $text): string
    {
        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }
}
