<?php

declare(strict_types=1);

namespace App;

/**
 * Sincronización con los dispositivos.
 *
 * push(): aplica, en orden, los cambios hechos en un dispositivo (también los
 * hechos sin conexión). Cada cambio va en su propia transacción y devuelve
 * "ok" o "rejected" junto con la versión del servidor de lo afectado.
 *
 * pull(): devuelve todo lo que cambió desde el último "cursor" del dispositivo.
 */
final class SyncService
{
    private const MAX_LOGO_BYTES = 1024 * 1024;

    private int $businessId;

    public function __construct(private readonly array $user)
    {
        $this->businessId = (int) $user['business_id'];
    }

    public function push(array $changes): array
    {
        $results = [];
        foreach (array_values($changes) as $change) {
            $type = is_array($change) ? (string) ($change['type'] ?? '') : '';
            $data = is_array($change['data'] ?? null) ? $change['data'] : [];
            try {
                $results[] = match ($type) {
                    'invoice_upsert' => $this->invoiceUpsert($data),
                    'invoice_delete' => $this->invoiceDelete($data),
                    'payment_add' => $this->paymentAdd($data),
                    'payment_delete' => $this->paymentDelete($data),
                    'invoice_void' => $this->invoiceVoid($data),
                    'payment_void' => $this->paymentVoid($data),
                    'issuer_update' => $this->issuerUpdate($data),
                    default => throw new ApiException(422, "Tipo de cambio desconocido: $type"),
                };
            } catch (ApiException $e) {
                if ($e->status >= 500) {
                    throw $e;
                }
                $results[] = $this->rejected($e->getMessage());
            }
        }
        return $results;
    }

    public function pull(int $cursor): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $upto = (int) Database::one('SELECT last_rev FROM businesses WHERE id = ?', [$this->businessId])['last_rev'];
            // Si el dispositivo va "por delante" (p. ej. se restauró la base de
            // datos del servidor), se le manda todo de nuevo.
            $reset = $cursor > $upto;
            if ($reset) {
                $cursor = 0;
            }
            $invoices = Database::all(
                'SELECT * FROM invoices WHERE business_id = ? AND rev > ? AND rev <= ? ORDER BY rev',
                [$this->businessId, $cursor, $upto],
            );
            $payments = Database::all(
                'SELECT * FROM payments WHERE business_id = ? AND rev > ? AND rev <= ? ORDER BY rev',
                [$this->businessId, $cursor, $upto],
            );
            $issuer = Database::one(
                'SELECT * FROM issuer WHERE business_id = ? AND rev > ? AND rev <= ?',
                [$this->businessId, $cursor, $upto],
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'cursor' => $upto,
            'reset' => $reset,
            'invoices' => array_map([self::class, 'invoiceOut'], $invoices),
            'payments' => array_map([self::class, 'paymentOut'], $payments),
            'issuer' => $issuer ? self::issuerOut($issuer) : null,
        ];
    }

    // ---------------------------------------------------------------- facturas

    private function invoiceUpsert(array $d): array
    {
        $id = Validate::uuid($d['id'] ?? null);
        $fields = [
            'issue_date' => Validate::date($d['issue_date'] ?? null, 'Fecha de emisión'),
            'due_date' => Validate::optionalDate($d['due_date'] ?? null, 'Fecha de vencimiento'),
            'client_name' => Validate::text($d['client_name'] ?? '', 'Nombre del cliente', 150, true),
            'client_tax_id' => Validate::text($d['client_tax_id'] ?? '', 'Identificación del cliente', 60),
            'client_address' => Validate::text($d['client_address'] ?? '', 'Dirección del cliente', 255),
            'client_email' => Validate::text($d['client_email'] ?? '', 'Email del cliente', 120),
            'tax_rate' => Validate::number($d['tax_rate'] ?? null, 'Impuesto', 0, 100),
            'notes' => Validate::text($d['notes'] ?? '', 'Observaciones', 5000),
            'items' => json_encode(Validate::items($d['items'] ?? null), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        ];
        if ($fields['due_date'] !== null && $fields['due_date'] < $fields['issue_date']) {
            throw new ApiException(422, 'El vencimiento no puede ser anterior a la fecha de emisión.');
        }
        $legacyNumber = isset($d['legacy_number']) ? Validate::text($d['legacy_number'], 'Número', 40) : '';

        return Database::transaction(function () use ($id, $fields, $legacyNumber) {
            $rev = Database::nextRev($this->businessId);
            $row = $this->lockInvoice($id);

            if ($row === null) {
                if (Database::one('SELECT id FROM invoices WHERE id = ?', [$id])) {
                    throw new ApiException(422, 'Identificador de factura no válido.');
                }
                $number = $this->assignNumber('invoice', $legacyNumber);
                Database::exec(
                    'INSERT INTO invoices (id, business_id, number, issue_date, due_date, client_name, client_tax_id,
                       client_address, client_email, tax_rate, notes, items, created_by, rev)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $this->businessId, $number, $fields['issue_date'], $fields['due_date'],
                        $fields['client_name'], $fields['client_tax_id'], $fields['client_address'],
                        $fields['client_email'], $fields['tax_rate'], $fields['notes'], $fields['items'],
                        $this->user['id'], $rev],
                );
                return $this->ok(['invoice' => $this->invoice($id)]);
            }

            if ($row['deleted_at'] !== null) {
                return $this->rejected('La factura fue eliminada en otro dispositivo.', ['invoice' => self::invoiceOut($row)]);
            }
            if ($row['voided_at'] !== null) {
                return $this->rejected(
                    "La factura {$row['number']} está anulada y no se puede modificar.",
                    ['invoice' => self::invoiceOut($row)],
                );
            }
            if ($row['paid'] || $this->activePaymentCount($id) > 0) {
                return $this->rejected(
                    "La factura {$row['number']} ya tiene pagos registrados y no se puede modificar.",
                    ['invoice' => self::invoiceOut($row)],
                );
            }
            Database::exec(
                'UPDATE invoices SET issue_date = ?, due_date = ?, client_name = ?, client_tax_id = ?,
                   client_address = ?, client_email = ?, tax_rate = ?, notes = ?, items = ?, rev = ?
                 WHERE id = ?',
                [$fields['issue_date'], $fields['due_date'], $fields['client_name'], $fields['client_tax_id'],
                    $fields['client_address'], $fields['client_email'], $fields['tax_rate'], $fields['notes'],
                    $fields['items'], $rev, $id],
            );
            return $this->ok(['invoice' => $this->invoice($id)]);
        });
    }

    private function invoiceDelete(array $d): array
    {
        $id = Validate::uuid($d['id'] ?? null);
        return Database::transaction(function () use ($id) {
            $rev = Database::nextRev($this->businessId);
            $row = $this->lockInvoice($id);
            if ($row === null || $row['deleted_at'] !== null) {
                return $this->ok(['invoice' => $row ? self::invoiceOut($row) : null]);
            }
            if ($row['voided_at'] !== null) {
                return $this->rejected("La factura {$row['number']} está anulada; queda en el historial.",
                    ['invoice' => self::invoiceOut($row)]);
            }
            if ($this->activePaymentCount($id) > 0) {
                return $this->rejected(
                    "La factura {$row['number']} tiene pagos registrados y no se puede eliminar.",
                    ['invoice' => self::invoiceOut($row)],
                );
            }
            Database::exec('UPDATE invoices SET deleted_at = NOW(), rev = ? WHERE id = ?', [$rev, $id]);
            return $this->ok(['invoice' => $this->invoice($id)]);
        });
    }

    // ------------------------------------------------------------------- pagos

    private function paymentAdd(array $d): array
    {
        $id = Validate::uuid($d['id'] ?? null);
        $invoiceId = Validate::uuid($d['invoice_id'] ?? null);
        $cents = Validate::cents($d['amount'] ?? null);
        $date = Validate::date($d['date'] ?? null, 'Fecha del pago');
        $method = Validate::text($d['method'] ?? '', 'Forma de pago', 40);
        $note = Validate::text($d['note'] ?? '', 'Nota', 255);
        $legacyReceipt = isset($d['legacy_receipt_number'])
            ? Validate::text($d['legacy_receipt_number'], 'Número de recibo', 40) : '';

        return Database::transaction(function () use ($id, $invoiceId, $cents, $date, $method, $note, $legacyReceipt) {
            $rev = Database::nextRev($this->businessId);

            // Reenvío del mismo pago (p. ej. se cortó la conexión): ya aplicado.
            $existing = Database::one('SELECT * FROM payments WHERE id = ?', [$id]);
            if ($existing !== null) {
                if ((int) $existing['business_id'] !== $this->businessId) {
                    throw new ApiException(422, 'Identificador de pago no válido.');
                }
                return $this->ok([
                    'payment' => self::paymentOut($existing),
                    'invoice' => $this->invoice($existing['invoice_id']),
                ]);
            }

            $invoice = $this->lockInvoice($invoiceId);
            if ($invoice === null || $invoice['deleted_at'] !== null) {
                return $this->rejected('La factura de este pago no existe o fue eliminada.',
                    ['invoice' => $invoice ? self::invoiceOut($invoice) : null]);
            }
            if ($invoice['voided_at'] !== null) {
                return $this->rejected("La factura {$invoice['number']} está anulada y no admite pagos.",
                    ['invoice' => self::invoiceOut($invoice)]);
            }
            $balance = self::totalCents($invoice) - $this->paidCents($invoiceId);
            if ($cents > $balance) {
                return $this->rejected(
                    sprintf('El pago supera el saldo pendiente de la factura %s (saldo actual: %s).',
                        $invoice['number'], number_format(max($balance, 0) / 100, 2, ',', '.')),
                    ['invoice' => self::invoiceOut($invoice)],
                );
            }

            $receipt = $this->assignNumber('receipt', $legacyReceipt);
            Database::exec(
                'INSERT INTO payments (id, business_id, invoice_id, receipt_number, amount, date, method, note, created_by, rev)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $this->businessId, $invoiceId, $receipt, $cents / 100, $date, $method, $note,
                    $this->user['id'], $rev],
            );
            $this->syncPaidStatus($invoiceId, $rev);
            return $this->ok([
                'payment' => self::paymentOut(Database::one('SELECT * FROM payments WHERE id = ?', [$id])),
                'invoice' => $this->invoice($invoiceId),
            ]);
        });
    }

    private function paymentDelete(array $d): array
    {
        $id = Validate::uuid($d['id'] ?? null);
        if ($this->user['role'] !== 'admin') {
            $row = Database::one('SELECT * FROM payments WHERE id = ? AND business_id = ?', [$id, $this->businessId]);
            return $this->rejected('Solo un administrador puede eliminar pagos.', [
                'payment' => $row ? self::paymentOut($row) : null,
                'invoice' => $row ? $this->invoice($row['invoice_id']) : null,
            ]);
        }
        return Database::transaction(function () use ($id) {
            $rev = Database::nextRev($this->businessId);
            $row = Database::one(
                'SELECT * FROM payments WHERE id = ? AND business_id = ? FOR UPDATE',
                [$id, $this->businessId],
            );
            if ($row === null) {
                return $this->ok(['payment' => null]);
            }
            if ($row['deleted_at'] === null) {
                $this->lockInvoice($row['invoice_id']);
                Database::exec('UPDATE payments SET deleted_at = NOW(), rev = ? WHERE id = ?', [$rev, $id]);
                $this->syncPaidStatus($row['invoice_id'], $rev);
            }
            return $this->ok([
                'payment' => self::paymentOut(Database::one('SELECT * FROM payments WHERE id = ?', [$id])),
                'invoice' => $this->invoice($row['invoice_id']),
            ]);
        });
    }

    // -------------------------------------------------------------- anulación

    /**
     * Anula una factura y todos sus pagos. Se conservan en el historial con
     * el motivo; el número no se reutiliza.
     */
    private function invoiceVoid(array $d): array
    {
        $id = Validate::uuid($d['id'] ?? null);
        $reason = self::reason($d['reason'] ?? null);
        if ($this->user['role'] !== 'admin') {
            $row = Database::one('SELECT * FROM invoices WHERE id = ? AND business_id = ?', [$id, $this->businessId]);
            return $this->rejected('Solo un administrador puede anular facturas.',
                ['invoice' => $row ? self::invoiceOut($row) : null]);
        }
        return Database::transaction(function () use ($id, $reason) {
            $rev = Database::nextRev($this->businessId);
            $row = $this->lockInvoice($id);
            if ($row === null || $row['deleted_at'] !== null) {
                return $this->rejected('La factura no existe o fue eliminada.',
                    ['invoice' => $row ? self::invoiceOut($row) : null]);
            }
            if ($row['voided_at'] === null) {
                Database::exec(
                    'UPDATE invoices SET voided_at = NOW(), void_reason = ?, voided_by = ?, paid = 0, paid_date = NULL, rev = ?
                     WHERE id = ?',
                    [$reason, $this->user['id'], $rev, $id],
                );
                $paymentReason = mb_substr("Anulación de la factura {$row['number']}: $reason", 0, 255);
                Database::exec(
                    'UPDATE payments SET voided_at = NOW(), void_reason = ?, voided_by = ?, rev = ?
                     WHERE invoice_id = ? AND deleted_at IS NULL AND voided_at IS NULL',
                    [$paymentReason, $this->user['id'], $rev, $id],
                );
            }
            return $this->ok(['invoice' => $this->invoice($id)]);
        });
    }

    /** Anula un pago: deja de contar y el saldo de la factura vuelve a subir. */
    private function paymentVoid(array $d): array
    {
        $id = Validate::uuid($d['id'] ?? null);
        $reason = self::reason($d['reason'] ?? null);
        $row = Database::one('SELECT * FROM payments WHERE id = ? AND business_id = ?', [$id, $this->businessId]);
        if ($this->user['role'] !== 'admin') {
            return $this->rejected('Solo un administrador puede anular pagos.', [
                'payment' => $row ? self::paymentOut($row) : null,
                'invoice' => $row ? $this->invoice($row['invoice_id']) : null,
            ]);
        }
        if ($row === null || $row['deleted_at'] !== null) {
            return $this->rejected('El pago no existe o fue eliminado.', ['payment' => $row ? self::paymentOut($row) : null]);
        }
        return Database::transaction(function () use ($id, $reason, $row) {
            $rev = Database::nextRev($this->businessId);
            $this->lockInvoice($row['invoice_id']);
            $current = Database::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]);
            if ($current['voided_at'] === null) {
                Database::exec(
                    'UPDATE payments SET voided_at = NOW(), void_reason = ?, voided_by = ?, rev = ? WHERE id = ?',
                    [$reason, $this->user['id'], $rev, $id],
                );
                $this->syncPaidStatus($row['invoice_id'], $rev);
            }
            return $this->ok([
                'payment' => self::paymentOut(Database::one('SELECT * FROM payments WHERE id = ?', [$id])),
                'invoice' => $this->invoice($row['invoice_id']),
            ]);
        });
    }

    private static function reason(mixed $value): string
    {
        $reason = Validate::text($value ?? '', 'El motivo de la anulación', 255, true);
        if (mb_strlen($reason) < 3) {
            throw new ApiException(422, 'Escribe el motivo de la anulación (al menos 3 caracteres).');
        }
        return $reason;
    }

    // ------------------------------------------------------------------ emisor

    private function issuerUpdate(array $d): array
    {
        if ($this->user['role'] !== 'admin') {
            return $this->rejected('Solo un administrador puede cambiar los datos del emisor.',
                ['issuer' => $this->issuer()]);
        }
        $fields = [];
        $text = [
            'name' => ['Nombre', 150], 'tax_id' => ['Identificación fiscal', 60], 'address' => ['Dirección', 255],
            'city' => ['Ciudad', 120], 'phone' => ['Teléfono', 60], 'email' => ['Email', 120],
            'bank_account' => ['Cuenta bancaria', 120], 'invoice_prefix' => ['Prefijo', 20], 'currency' => ['Moneda', 10],
        ];
        foreach ($text as $key => [$label, $max]) {
            if (array_key_exists($key, $d)) {
                $fields[$key] = Validate::text($d[$key], $label, $max);
            }
        }
        if (array_key_exists('default_tax_rate', $d)) {
            $fields['default_tax_rate'] = Validate::number($d['default_tax_rate'], 'IVA por defecto', 0, 100);
        }
        if (array_key_exists('next_invoice_number', $d)) {
            $fields['next_invoice_number'] = (int) Validate::number($d['next_invoice_number'], 'Próximo número', 1, 99999999);
        }
        if (array_key_exists('logo', $d)) {
            $fields['logo'] = $d['logo'] === null ? null : self::decodeLogo($d['logo']);
        }
        if ($fields === []) {
            return $this->ok(['issuer' => $this->issuer()]);
        }

        return Database::transaction(function () use ($fields) {
            $rev = Database::nextRev($this->businessId);
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            Database::exec(
                "UPDATE issuer SET $sets, rev = ? WHERE business_id = ?",
                [...array_values($fields), $rev, $this->businessId],
            );
            return $this->ok(['issuer' => $this->issuer()]);
        });
    }

    private static function decodeLogo(mixed $base64): string
    {
        $bytes = is_string($base64) ? base64_decode($base64, true) : false;
        if ($bytes === false || $bytes === '') {
            throw new ApiException(422, 'El logo no es válido.');
        }
        if (strlen($bytes) > self::MAX_LOGO_BYTES) {
            throw new ApiException(422, 'El logo es demasiado grande (máximo 1 MB).');
        }
        $isPng = str_starts_with($bytes, "\x89PNG");
        $isJpeg = str_starts_with($bytes, "\xFF\xD8\xFF");
        if (!$isPng && !$isJpeg) {
            throw new ApiException(422, 'El logo debe ser PNG o JPG.');
        }
        return $bytes;
    }

    // ----------------------------------------------------------------- comunes

    private function lockInvoice(string $id): ?array
    {
        return Database::one(
            'SELECT * FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE',
            [$id, $this->businessId],
        );
    }

    private function invoice(string $id): ?array
    {
        $row = Database::one('SELECT * FROM invoices WHERE id = ?', [$id]);
        return $row ? self::invoiceOut($row) : null;
    }

    private function issuer(): ?array
    {
        $row = Database::one('SELECT * FROM issuer WHERE business_id = ?', [$this->businessId]);
        return $row ? self::issuerOut($row) : null;
    }

    private function activePaymentCount(string $invoiceId): int
    {
        return (int) Database::one(
            'SELECT COUNT(*) AS n FROM payments WHERE invoice_id = ? AND deleted_at IS NULL AND voided_at IS NULL',
            [$invoiceId],
        )['n'];
    }

    private function paidCents(string $invoiceId): int
    {
        return (int) Database::one(
            'SELECT COALESCE(SUM(ROUND(amount * 100)), 0) AS c FROM payments WHERE invoice_id = ? AND deleted_at IS NULL AND voided_at IS NULL',
            [$invoiceId],
        )['c'];
    }

    /** Total en céntimos, calculado igual que en la app. */
    public static function totalCents(array $invoice): int
    {
        $subtotal = 0.0;
        foreach (json_decode($invoice['items'], true) ?: [] as $item) {
            $subtotal += (float) $item['quantity'] * (float) $item['unit_price'];
        }
        $total = $subtotal + $subtotal * (float) $invoice['tax_rate'] / 100;
        return (int) round($total * 100);
    }

    /** Pagada cuando los pagos cubren el total; fecha = la del último pago. */
    private function syncPaidStatus(string $invoiceId, int $rev): void
    {
        $invoice = Database::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        if ($invoice['voided_at'] !== null) {
            Database::exec('UPDATE invoices SET paid = 0, paid_date = NULL, rev = ? WHERE id = ?', [$rev, $invoiceId]);
            return;
        }
        $stats = Database::one(
            'SELECT COUNT(*) AS n, MAX(date) AS last_date FROM payments WHERE invoice_id = ? AND deleted_at IS NULL AND voided_at IS NULL',
            [$invoiceId],
        );
        $paid = (int) $stats['n'] > 0 && $this->paidCents($invoiceId) >= self::totalCents($invoice);
        Database::exec(
            'UPDATE invoices SET paid = ?, paid_date = ?, rev = ? WHERE id = ?',
            [$paid ? 1 : 0, $paid ? $stats['last_date'] : null, $rev, $invoiceId],
        );
    }

    /**
     * Asigna el siguiente número de factura o de recibo sin duplicados.
     * Si el dispositivo trae un número anterior (datos migrados) y está libre,
     * se respeta y el contador avanza por encima de él.
     */
    private function assignNumber(string $kind, string $legacy): string
    {
        $issuer = Database::one('SELECT * FROM issuer WHERE business_id = ? FOR UPDATE', [$this->businessId]);
        [$table, $column, $counter, $prefix] = $kind === 'invoice'
            ? ['invoices', 'number', 'next_invoice_number', $issuer['invoice_prefix']]
            : ['payments', 'receipt_number', 'next_receipt_number', 'RC-'];
        $used = fn(string $n) => Database::one(
            "SELECT 1 FROM $table WHERE business_id = ? AND $column = ?",
            [$this->businessId, $n],
        ) !== null;

        $next = (int) $issuer[$counter];
        if ($legacy !== '' && !$used($legacy)) {
            $tail = substr($legacy, strlen($prefix));
            if (str_starts_with($legacy, $prefix) && ctype_digit($tail)) {
                $next = max($next, (int) $tail + 1);
            }
            Database::exec("UPDATE issuer SET $counter = ? WHERE business_id = ?", [$next, $this->businessId]);
            return $legacy;
        }

        $format = fn(int $n) => $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        while ($used($format($next))) {
            $next++;
        }
        Database::exec("UPDATE issuer SET $counter = ? WHERE business_id = ?", [$next + 1, $this->businessId]);
        return $format($next);
    }

    private function ok(array $extra = []): array
    {
        return ['status' => 'ok'] + $extra;
    }

    private function rejected(string $message, array $extra = []): array
    {
        return ['status' => 'rejected', 'message' => $message] + $extra;
    }

    // ------------------------------------------------------- formato de salida

    public static function invoiceOut(array $r): array
    {
        return [
            'id' => $r['id'],
            'number' => $r['number'],
            'issue_date' => $r['issue_date'],
            'due_date' => $r['due_date'],
            'client_name' => $r['client_name'],
            'client_tax_id' => $r['client_tax_id'],
            'client_address' => $r['client_address'],
            'client_email' => $r['client_email'],
            'tax_rate' => (float) $r['tax_rate'],
            'notes' => (string) $r['notes'],
            'items' => json_decode($r['items'], true) ?: [],
            'paid' => (bool) $r['paid'],
            'paid_date' => $r['paid_date'],
            'deleted' => $r['deleted_at'] !== null,
            'voided' => $r['voided_at'] !== null,
            'voided_at' => $r['voided_at'],
            'void_reason' => $r['void_reason'],
            'created_by' => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'rev' => (int) $r['rev'],
        ];
    }

    public static function paymentOut(array $r): array
    {
        return [
            'id' => $r['id'],
            'invoice_id' => $r['invoice_id'],
            'receipt_number' => $r['receipt_number'],
            'amount' => (float) $r['amount'],
            'date' => $r['date'],
            'method' => $r['method'],
            'note' => $r['note'],
            'deleted' => $r['deleted_at'] !== null,
            'voided' => $r['voided_at'] !== null,
            'voided_at' => $r['voided_at'],
            'void_reason' => $r['void_reason'],
            'created_by' => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'rev' => (int) $r['rev'],
        ];
    }

    public static function issuerOut(array $r): array
    {
        return [
            'name' => $r['name'],
            'tax_id' => $r['tax_id'],
            'address' => $r['address'],
            'city' => $r['city'],
            'phone' => $r['phone'],
            'email' => $r['email'],
            'bank_account' => $r['bank_account'],
            'invoice_prefix' => $r['invoice_prefix'],
            'next_invoice_number' => (int) $r['next_invoice_number'],
            'default_tax_rate' => (float) $r['default_tax_rate'],
            'currency' => $r['currency'],
            'logo' => $r['logo'] !== null ? base64_encode($r['logo']) : null,
            'rev' => (int) $r['rev'],
        ];
    }
}
