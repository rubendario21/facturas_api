<?php

declare(strict_types=1);

namespace App\Portal;

/** Plantillas del portal y utilidades de formato (todo el HTML se escapa). */
final class View
{
    /** Ruta pública de la API (p. ej. "" o "/facturas_api/public"). */
    public static string $root = '';

    public static function url(string $path = ''): string
    {
        return self::$root . '/admin' . $path;
    }

    public static function asset(string $file): string
    {
        $version = @filemtime(dirname(__DIR__, 2) . '/public/assets/' . $file) ?: 1;
        return self::$root . '/assets/' . $file . '?v=' . $version;
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** "$ 1.234,50": símbolo delante, como en la app. */
    public static function money(float $value, string $currency): string
    {
        $amount = number_format(abs($value), 2, ',', '.');
        return ($value < 0 ? '-' : '') . ($currency === '' ? $amount : "$currency $amount");
    }

    /** Mensaje de error dentro de una página. */
    public static function alert(string $text): string
    {
        return '<div class="alert alert-danger" role="alert">' . self::icon('aviso') . '<span>' . self::e($text) . '</span></div>';
    }

    /** Valor de un informe según su tipo. Sin dato: raya. */
    public static function reportValue(mixed $value, string $type, string $currency): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $number = fn(float $n) => rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',');
        return match ($type) {
            'money' => self::money((float) $value, $currency),
            'count' => number_format((float) $value, 0, ',', '.'),
            'percent' => $number((float) $value) . ' %',
            'days' => $number((float) $value) . ((float) $value === 1.0 ? ' día' : ' días'),
            'date' => self::date((string) $value),
            default => (string) $value,
        };
    }

    public static function date(?string $ymd): string
    {
        if (!$ymd) {
            return '—';
        }
        $d = \DateTime::createFromFormat('Y-m-d', substr($ymd, 0, 10));
        return $d ? $d->format('d/m/Y') : $ymd;
    }

    /** "Martes, 21 de octubre de 2026" (sin depender de la extensión intl). */
    public static function longDate(\DateTimeInterface $d): string
    {
        $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
            'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $text = $days[(int) $d->format('w')] . ', ' . $d->format('j') . ' de '
            . $months[(int) $d->format('n') - 1] . ' de ' . $d->format('Y');
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** Etiqueta de estado de una factura (mismos nombres y colores que la app). */
    public static function status(string $status): string
    {
        [$label, $class] = match ($status) {
            'paid' => ['Pagada', 'paid'],
            'overdue' => ['Vencida', 'overdue'],
            'partial' => ['Con abonos', 'partial'],
            'voided' => ['Anulada', 'voided'],
            default => ['Pendiente', 'pending'],
        };
        return "<span class=\"badge badge--$class\">$label</span>";
    }

    /** Iconos de trazo (24×24) incrustados, sin dependencias externas. */
    public static function icon(string $name): string
    {
        $paths = [
            'resumen' => '<path d="M4 19V10m6 9V5m6 14v-6m4 6H2"/>',
            'facturas' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h6M9 16h6"/>',
            'equipo' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
            'informes' => '<path d="M4 4v16h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
            'emisor' => '<path d="M3 9l1.5-5h15L21 9M3 9v11h18V9M3 9h18M9 20v-6h6v6"/>',
            'cuenta' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'salir' => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/>',
            'tema' => '<path d="M12 3a9 9 0 1 0 9 9A7 7 0 0 1 12 3z"/>',
            'aviso' => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/>',
            'hecho' => '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
            'abajo' => '<path d="M6 9l6 6 6-6"/>',
            'marca' => '<path d="M5 12l5 5 9-10"/>',
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'buscar' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
            'mas' => '<path d="M12 5v14M5 12h14"/>',
            'volver' => '<path d="M15 6l-6 6 6 6"/>',
            'seguir' => '<path d="M9 6l6 6-6 6"/>',
            'negocios' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>',
            'imprimir' => '<path d="M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7z"/>',
            'editar' => '<path d="M4 20h4L19 9l-4-4L4 16zM14 6l4 4"/>',
        ];
        return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
    }

    public static function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = self::capture($template, $vars);
        return $layout === null ? $content : self::capture($layout, $vars + ['content' => $content]);
    }

    private static function capture(string $template, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        $e = [self::class, 'e'];
        ob_start();
        require dirname(__DIR__, 2) . "/templates/portal/$template.php";
        return (string) ob_get_clean();
    }
}
