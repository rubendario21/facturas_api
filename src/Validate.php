<?php

declare(strict_types=1);

namespace App;

/** Validación de los datos que envían los dispositivos (errores 422). */
final class Validate
{
    public static function uuid(mixed $v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v)) {
            throw new ApiException(422, 'Identificador no válido.');
        }
        return $v;
    }

    public static function date(mixed $v, string $label): string
    {
        $v = is_string($v) ? substr(trim($v), 0, 10) : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ApiException(422, "$label no válida (formato AAAA-MM-DD).");
        }
        return $v;
    }

    public static function optionalDate(mixed $v, string $label): ?string
    {
        return ($v === null || $v === '') ? null : self::date($v, $label);
    }

    public static function text(mixed $v, string $label, int $max, bool $required = false): string
    {
        if ($v !== null && !is_scalar($v)) {
            throw new ApiException(422, "$label no válido.");
        }
        $v = trim((string) $v);
        if ($required && $v === '') {
            throw new ApiException(422, "$label es obligatorio.");
        }
        if (mb_strlen($v) > $max) {
            throw new ApiException(422, "$label es demasiado largo (máximo $max caracteres).");
        }
        return $v;
    }

    public static function number(mixed $v, string $label, float $min, float $max): float
    {
        if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
            throw new ApiException(422, "$label no es un número válido.");
        }
        $n = (float) $v;
        if (!is_finite($n) || $n < $min || $n > $max) {
            throw new ApiException(422, "$label fuera de rango.");
        }
        return $n;
    }

    /** Importe positivo con como mucho 2 decimales, en céntimos. */
    public static function cents(mixed $v): int
    {
        $amount = self::number($v, 'Importe', 0.01, 999999999999);
        $cents = (int) round($amount * 100);
        if (abs($amount * 100 - $cents) > 0.001) {
            throw new ApiException(422, 'El importe admite como mucho 2 decimales.');
        }
        return $cents;
    }

    /** Conceptos de la factura: entre 1 y 200, cantidad > 0 y precio >= 0. */
    public static function items(mixed $items): array
    {
        if (!is_array($items) || $items === [] || count($items) > 200) {
            throw new ApiException(422, 'La factura debe tener entre 1 y 200 conceptos.');
        }
        $out = [];
        foreach (array_values($items) as $i => $item) {
            $n = $i + 1;
            if (!is_array($item)) {
                throw new ApiException(422, "Concepto $n no válido.");
            }
            $out[] = [
                'description' => self::text($item['description'] ?? '', "Descripción del concepto $n", 500, true),
                'quantity' => self::number($item['quantity'] ?? null, "Cantidad del concepto $n", 0.000001, 1e9),
                'unit_price' => self::number($item['unit_price'] ?? null, "Precio del concepto $n", 0, 1e12),
            ];
        }
        return $out;
    }
}
