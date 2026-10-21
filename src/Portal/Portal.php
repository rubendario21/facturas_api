<?php

declare(strict_types=1);

namespace App\Portal;

use App\ApiException;
use App\Auth;
use App\BusinessService;
use App\Controllers\AuthController;
use App\Database;
use App\SyncService;
use App\UserService;

/**
 * Portal web (/admin): versión web de la app más la administración.
 *
 * - Miembros con acceso web: resumen, facturas, pagos, recibos.
 * - Administradores del negocio: además equipo y datos del emisor.
 * - Superadministrador: además los negocios de la plataforma, y puede entrar
 *   en cualquiera.
 *
 * Sesión con cookie HttpOnly, CSRF en todos los formularios y las mismas
 * reglas que la API (los cambios llegan a la app al sincronizar).
 */
final class Portal
{
    private const IDLE_MINUTES = 120;
    private const PAGE_SIZE = 50;

    /** Cuenta del usuario (fila de users). */
    private ?array $account = null;

    /** Contexto: cuenta + negocio actual + rol en él. Null si no hay negocio. */
    private ?array $user = null;
    private ?array $business = null;
    private ?array $issuer = null;
    private array $businesses = [];

    public static function handle(string $method, string $path, string $root): void
    {
        View::$root = $root;
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        (new self())->dispatch($method, substr($path, strlen('/admin')) ?: '/');
    }

    private function dispatch(string $method, string $path): void
    {
        $this->startSession();
        if ($method === 'POST') {
            $this->checkCsrf();
        }
        if ($path === '/login') {
            $method === 'POST' ? $this->login() : $this->loginForm();
            return;
        }
        if (!$this->loadUser()) {
            $this->redirect('/login');
        }

        $uuid = '([0-9a-f-]{36})';
        // [método, ruta, acción, requisito: 'negocio' | 'admin' | 'super' | null]
        $routes = [
            ['GET', '#^/$#', fn() => $this->redirect($this->user ? '/resumen' : '/negocios'), null],
            ['POST', '#^/salir$#', fn() => $this->logout(), null],
            ['GET', '#^/cuenta$#', fn() => $this->account(), null],
            ['POST', '#^/cuenta$#', fn() => $this->changePassword(), null],
            ['POST', '#^/negocio-actual$#', fn() => $this->switchBusiness(), null],

            ['GET', '#^/resumen$#', fn() => $this->dashboard(), 'negocio'],
            ['GET', '#^/facturas$#', fn() => $this->invoices(), 'negocio'],
            ['GET', '#^/facturas/nueva$#', fn() => $this->invoiceForm(null), 'negocio'],
            ['POST', '#^/facturas/nueva$#', fn() => $this->saveInvoice(null), 'negocio'],
            ['GET', "#^/facturas/$uuid$#", fn($id) => $this->invoice($id), 'negocio'],
            ['GET', "#^/facturas/$uuid/editar$#", fn($id) => $this->invoiceForm($id), 'negocio'],
            ['POST', "#^/facturas/$uuid/editar$#", fn($id) => $this->saveInvoice($id), 'negocio'],
            ['GET', "#^/facturas/$uuid/imprimir$#", fn($id) => $this->printInvoice($id), 'negocio'],
            ['POST', "#^/facturas/$uuid/pagos$#", fn($id) => $this->addPayment($id), 'negocio'],
            ['POST', "#^/facturas/$uuid/eliminar$#", fn($id) => $this->deleteInvoice($id), 'negocio'],
            ['GET', "#^/pagos/$uuid/recibo$#", fn($id) => $this->printReceipt($id), 'negocio'],
            ['POST', "#^/pagos/$uuid/eliminar$#", fn($id) => $this->deletePayment($id), 'admin'],

            ['GET', '#^/equipo$#', fn() => $this->team(), 'admin'],
            ['GET', '#^/equipo/nuevo$#', fn() => $this->userForm(null), 'admin'],
            ['POST', '#^/equipo/nuevo$#', fn() => $this->createUser(), 'admin'],
            ['GET', '#^/equipo/(\d+)$#', fn($id) => $this->userForm((int) $id), 'admin'],
            ['POST', '#^/equipo/(\d+)$#', fn($id) => $this->updateUser((int) $id), 'admin'],
            ['GET', '#^/emisor$#', fn() => $this->issuerForm(), 'admin'],
            ['POST', '#^/emisor$#', fn() => $this->saveIssuer(), 'admin'],

            ['GET', '#^/negocios$#', fn() => $this->businessList(), 'super'],
            ['GET', '#^/negocios/nuevo$#', fn() => $this->businessForm(), 'super'],
            ['POST', '#^/negocios/nuevo$#', fn() => $this->createBusiness(), 'super'],
            ['GET', '#^/negocios/(\d+)$#', fn($id) => $this->businessEdit((int) $id), 'super'],
            ['POST', '#^/negocios/(\d+)$#', fn($id) => $this->updateBusiness((int) $id), 'super'],
            ['POST', '#^/negocios/(\d+)/miembros$#', fn($id) => $this->addMember((int) $id), 'super'],
        ];
        foreach ($routes as [$verb, $regex, $handler, $needs]) {
            if ($verb !== $method || !preg_match($regex, $path, $m)) {
                continue;
            }
            if ($needs === 'super' && !$this->account['is_superadmin']) {
                $this->forbidden();
            }
            if (($needs === 'negocio' || $needs === 'admin') && $this->user === null) {
                $this->redirect('/negocios');
            }
            if ($needs === 'admin' && !$this->isAdmin()) {
                $this->forbidden();
            }
            $handler(...array_slice($m, 1));
            return;
        }
        http_response_code(404);
        $this->page('error', 'Página no encontrada', ['message' => 'Esta página no existe.']);
    }

    private function isAdmin(): bool
    {
        return $this->user !== null && $this->user['role'] === 'admin';
    }

    private function forbidden(): never
    {
        http_response_code(403);
        $this->page('error', 'Sin permiso', ['message' => 'Tu usuario no tiene permiso para esta sección.']);
        exit;
    }

    // ---------------------------------------------------------------- sesión

    private function startSession(): void
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('FACTURAS_PORTAL');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => View::url('/'),
            'httponly' => true,
            'secure' => $https,
            'samesite' => 'Lax',
        ]);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    private function checkCsrf(): void
    {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['_csrf'] ?? ''))) {
            http_response_code(403);
            echo View::render('error', ['title' => 'Formulario caducado',
                'message' => 'El formulario caducó. Vuelve atrás, recarga la página e inténtalo de nuevo.'], 'bare');
            exit;
        }
    }

    /** Negocios en los que la cuenta puede usar el portal. */
    private static function portalBusinesses(array $account): array
    {
        if ($account['is_superadmin']) {
            return Database::all('SELECT id, name, active FROM businesses ORDER BY active DESC, name');
        }
        return Database::all(
            'SELECT b.id, b.name, b.active FROM memberships m JOIN businesses b ON b.id = m.business_id
             WHERE m.user_id = ? AND m.active = 1 AND b.active = 1 AND (m.role = \'admin\' OR m.web_access = 1)
             ORDER BY b.name',
            [$account['id']],
        );
    }

    /** Contexto en un negocio si la cuenta puede usarlo desde la web. */
    private static function portalContext(array $account, int $businessId): ?array
    {
        $ctx = Auth::context($account, $businessId);
        if ($ctx !== null && $ctx['web_access']) {
            return $ctx;
        }
        // El superadministrador gestiona cualquier negocio, aunque no sea miembro.
        if ($account['is_superadmin']) {
            $b = Database::one('SELECT id, name FROM businesses WHERE id = ?', [$businessId]);
            if ($b) {
                return $account + ['business_id' => (int) $b['id'], 'business_name' => $b['name'],
                    'role' => 'admin', 'web_access' => true];
            }
        }
        return null;
    }

    /** Carga la cuenta de la sesión y su negocio actual. */
    private function loadUser(): bool
    {
        $id = $_SESSION['uid'] ?? null;
        if ($id === null || (time() - ($_SESSION['last'] ?? 0)) > self::IDLE_MINUTES * 60) {
            return false;
        }
        $account = Database::one('SELECT * FROM users WHERE id = ?', [$id]);
        // Contraseña cambiada o cuenta desactivada: fuera.
        if ($account === null || !$account['active'] || (int) $account['token_version'] !== (int) ($_SESSION['ver'] ?? -1)) {
            $_SESSION = ['csrf' => $_SESSION['csrf']];
            return false;
        }
        $this->businesses = self::portalBusinesses($account);
        $ctx = isset($_SESSION['bid']) ? self::portalContext($account, (int) $_SESSION['bid']) : null;
        if ($ctx === null && $this->businesses !== []) {
            $ctx = self::portalContext($account, (int) $this->businesses[0]['id']);
        }
        if ($ctx === null && !$account['is_superadmin']) {
            // Le quitaron el acceso web o el negocio: se cierra su sesión.
            $_SESSION = ['csrf' => $_SESSION['csrf']];
            return false;
        }
        $_SESSION['last'] = time();
        $_SESSION['bid'] = $ctx['business_id'] ?? null;
        $this->account = $account;
        $this->user = $ctx;
        if ($ctx !== null) {
            $this->business = Database::one('SELECT * FROM businesses WHERE id = ?', [$ctx['business_id']]);
            $this->issuer = Database::one('SELECT * FROM issuer WHERE business_id = ?', [$ctx['business_id']]);
        }
        return true;
    }

    private function loginForm(?string $error = null, string $username = ''): void
    {
        if ($this->loadUser()) {
            $this->redirect('/');
        }
        echo View::render('login', ['error' => $error, 'username' => $username,
            'csrf' => $_SESSION['csrf'], 'title' => 'Iniciar sesión'], 'bare');
    }

    private function login(): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        try {
            $account = AuthController::checkCredentials(
                $_SERVER['REMOTE_ADDR'] ?? '', $username, (string) ($_POST['password'] ?? ''));
        } catch (ApiException $e) {
            $this->loginForm($e->getMessage(), $username);
            return;
        }
        $businesses = self::portalBusinesses($account);
        if ($businesses === [] && !$account['is_superadmin']) {
            $this->loginForm('Tu usuario no tiene acceso web. Usa la app o pide a tu administrador que lo active.', $username);
            return;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $account['id'];
        $_SESSION['ver'] = (int) $account['token_version'];
        $_SESSION['last'] = time();
        // Entra en el último negocio que usó, si puede.
        $last = (int) ($account['last_business_id'] ?? 0);
        $_SESSION['bid'] = in_array($last, array_map('intval', array_column($businesses, 'id')), true)
            ? $last : ($businesses[0]['id'] ?? null);
        $this->redirect('/');
    }

    private function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        $this->redirect('/login');
    }

    private function switchBusiness(): void
    {
        $id = (int) ($_POST['business_id'] ?? 0);
        if (self::portalContext($this->account, $id) === null) {
            $this->forbidden();
        }
        $_SESSION['bid'] = $id;
        Database::exec('UPDATE users SET last_business_id = ? WHERE id = ?', [$id, $this->account['id']]);
        $this->redirect('/resumen');
    }

    // --------------------------------------------------------------- resumen

    private function dashboard(): void
    {
        $invoices = $this->loadInvoices();
        $today = date('Y-m-d');
        $open = array_filter($invoices, fn($i) => !$i['paid']);
        $overdue = array_filter($open, fn($i) => $i['overdue']);
        usort($overdue, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));

        $collected = (float) Database::one(
            'SELECT COALESCE(SUM(amount), 0) AS t FROM payments
             WHERE business_id = ? AND deleted_at IS NULL AND date >= ? AND date <= ?',
            [$this->user['business_id'], date('Y-m-01'), $today],
        )['t'];
        $recentPayments = Database::all(
            'SELECT p.*, i.client_name, i.number FROM payments p JOIN invoices i ON i.id = p.invoice_id
             WHERE p.business_id = ? AND p.deleted_at IS NULL ORDER BY p.date DESC, p.created_at DESC LIMIT 8',
            [$this->user['business_id']],
        );
        $this->page('dashboard', 'Resumen', [
            'receivable' => array_sum(array_column($open, 'balance')),
            'overdueTotal' => array_sum(array_column($overdue, 'balance')),
            'openCount' => count($open),
            'overdueCount' => count($overdue),
            'collected' => $collected,
            'overdue' => array_slice($overdue, 0, 8),
            'recentPayments' => $recentPayments,
            'issuerReady' => $this->issuerReady(),
        ]);
    }

    private function issuerReady(): bool
    {
        return $this->issuer && $this->issuer['name'] !== '' && $this->issuer['tax_id'] !== '';
    }

    // -------------------------------------------------------------- facturas

    private function invoices(): void
    {
        $status = (string) ($_GET['estado'] ?? 'todas');
        $q = trim((string) ($_GET['q'] ?? ''));
        $filtered = array_values(array_filter($this->loadInvoices($q), fn($i) => match ($status) {
            'por-cobrar' => !$i['paid'],
            'vencidas' => $i['overdue'],
            'pagadas' => $i['paid'],
            default => true,
        }));
        $pages = max(1, (int) ceil(count($filtered) / self::PAGE_SIZE));
        $page = min(max(1, (int) ($_GET['pagina'] ?? 1)), $pages);
        $this->page('invoices', 'Facturas', [
            'invoices' => array_slice($filtered, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'total' => count($filtered),
            'status' => $status,
            'q' => $q,
            'page' => $page,
            'pages' => $pages,
            'issuerReady' => $this->issuerReady(),
        ]);
    }

    private function findInvoice(string $id): array
    {
        $row = Database::one(
            'SELECT * FROM invoices WHERE id = ? AND business_id = ? AND deleted_at IS NULL',
            [$id, $this->user['business_id']],
        );
        if ($row === null) {
            http_response_code(404);
            $this->page('error', 'Factura no encontrada', ['message' => 'La factura no existe o fue eliminada.']);
            exit;
        }
        return $row;
    }

    private function invoicePayments(string $id): array
    {
        return Database::all(
            'SELECT p.*, u.name AS created_by_name FROM payments p LEFT JOIN users u ON u.id = p.created_by
             WHERE p.invoice_id = ? AND p.deleted_at IS NULL ORDER BY p.date, p.created_at',
            [$id],
        );
    }

    private function invoice(string $id): void
    {
        $row = $this->findInvoice($id);
        $payments = $this->invoicePayments($id);
        $creator = $row['created_by'] ? Database::one('SELECT name FROM users WHERE id = ?', [$row['created_by']]) : null;
        $summary = self::summarize($row, $payments);
        $this->page('invoice', 'Factura ' . $row['number'], [
            'invoice' => $summary,
            'items' => json_decode($row['items'], true) ?: [],
            'payments' => $payments,
            'creator' => $creator['name'] ?? null,
            'canDeletePayments' => $this->isAdmin(),
            'paymentError' => $_SESSION['payment_error'] ?? null,
            'paymentValues' => $_SESSION['payment_values'] ?? null,
            'methods' => ['Efectivo', 'Transferencia', 'Tarjeta', 'Otro'],
        ]);
        unset($_SESSION['payment_error'], $_SESSION['payment_values']);
    }

    private function invoiceForm(?string $id, ?string $error = null, ?array $values = null): void
    {
        $row = $id ? $this->findInvoice($id) : null;
        if ($row && ($row['paid'] || $this->invoicePayments($id))) {
            $this->flash('error', 'La factura tiene pagos registrados y ya no se puede modificar.');
            $this->redirect("/facturas/$id");
        }
        if (!$row && !$this->issuerReady()) {
            $this->flash('error', $this->isAdmin()
                ? 'Completa primero los datos del emisor: nombre e identificación fiscal.'
                : 'Un administrador debe completar antes los datos del emisor.');
            $this->redirect($this->isAdmin() ? '/emisor' : '/facturas');
        }
        if ($values === null) {
            $values = $row ? $row + ['items_list' => json_decode($row['items'], true) ?: []] : [
                'issue_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+30 days')),
                'tax_rate' => (float) ($this->issuer['default_tax_rate'] ?? 19),
                'items_list' => [['description' => '', 'quantity' => 1, 'unit_price' => '']],
            ];
        }
        $this->page('invoice_form', $row ? 'Editar ' . $row['number'] : 'Nueva factura', [
            'invoiceRow' => $row, 'values' => $values, 'error' => $error,
        ]);
    }

    private function saveInvoice(?string $id): void
    {
        $num = [self::class, 'decimal'];
        $items = [];
        foreach ((array) ($_POST['items'] ?? []) as $it) {
            $desc = trim((string) ($it['description'] ?? ''));
            if ($desc === '' && trim((string) ($it['unit_price'] ?? '')) === '') {
                continue;  // fila vacía
            }
            $items[] = ['description' => $desc, 'quantity' => $num($it['quantity'] ?? ''), 'unit_price' => $num($it['unit_price'] ?? '')];
        }
        $data = [
            'id' => $id ?? self::uuid(),
            'issue_date' => (string) ($_POST['issue_date'] ?? ''),
            'due_date' => (string) ($_POST['due_date'] ?? ''),
            'client_name' => (string) ($_POST['client_name'] ?? ''),
            'client_tax_id' => (string) ($_POST['client_tax_id'] ?? ''),
            'client_address' => (string) ($_POST['client_address'] ?? ''),
            'client_email' => (string) ($_POST['client_email'] ?? ''),
            'tax_rate' => $num($_POST['tax_rate'] ?? ''),
            'notes' => (string) ($_POST['notes'] ?? ''),
            'items' => $items,
        ];
        $result = $this->push('invoice_upsert', $data);
        if ($result['status'] !== 'ok') {
            $this->invoiceForm($id, $result['message'], $data + ['items_list' => $items ?: [['description' => '', 'quantity' => 1, 'unit_price' => '']]]);
            return;
        }
        $number = $result['invoice']['number'];
        $this->flash('ok', $id ? "Factura $number guardada." : "Factura $number creada.");
        $this->redirect('/facturas/' . $data['id']);
    }

    private function addPayment(string $invoiceId): void
    {
        $data = [
            'id' => self::uuid(),
            'invoice_id' => $invoiceId,
            'amount' => self::decimal($_POST['amount'] ?? ''),
            'date' => (string) ($_POST['date'] ?? ''),
            'method' => (string) ($_POST['method'] ?? ''),
            'note' => (string) ($_POST['note'] ?? ''),
        ];
        $result = $this->push('payment_add', $data);
        if ($result['status'] !== 'ok') {
            $_SESSION['payment_error'] = $result['message'];
            $_SESSION['payment_values'] = $_POST;
        } else {
            $p = $result['payment'];
            $this->flash('ok', ($result['invoice']['paid'] ?? false)
                ? "Pago registrado con el recibo {$p['receipt_number']}. La factura queda pagada."
                : "Abono registrado con el recibo {$p['receipt_number']}.");
            $_SESSION['last_receipt'] = $p['id'];
        }
        $this->redirect("/facturas/$invoiceId");
    }

    private function deleteInvoice(string $id): void
    {
        $result = $this->push('invoice_delete', ['id' => $id]);
        if ($result['status'] === 'ok') {
            $this->flash('ok', 'Factura eliminada. Desaparecerá de la app al sincronizar.');
            $this->redirect('/facturas');
        }
        $this->flash('error', $result['message']);
        $this->redirect("/facturas/$id");
    }

    private function deletePayment(string $id): void
    {
        $result = $this->push('payment_delete', ['id' => $id]);
        $invoiceId = $result['payment']['invoice_id'] ?? null;
        $this->flash($result['status'] === 'ok' ? 'ok' : 'error', $result['status'] === 'ok'
            ? 'Pago eliminado. El saldo de la factura se actualizó.'
            : $result['message']);
        $this->redirect($invoiceId ? "/facturas/$invoiceId" : '/facturas');
    }

    /** Factura lista para imprimir o guardar como PDF desde el navegador. */
    private function printInvoice(string $id): void
    {
        $row = $this->findInvoice($id);
        echo View::render('print_invoice', [
            'title' => 'Factura ' . $row['number'],
            'invoice' => self::summarize($row, $this->invoicePayments($id)),
            'items' => json_decode($row['items'], true) ?: [],
            'issuerRow' => $this->issuer,
            'currency' => $this->issuer['currency'] ?? '$',
        ], 'print');
    }

    /** Recibo de un pago, listo para imprimir o guardar como PDF. */
    private function printReceipt(string $paymentId): void
    {
        $p = Database::one(
            'SELECT * FROM payments WHERE id = ? AND business_id = ? AND deleted_at IS NULL',
            [$paymentId, $this->user['business_id']],
        );
        if ($p === null) {
            http_response_code(404);
            $this->page('error', 'Recibo no encontrado', ['message' => 'El pago no existe o fue eliminado.']);
            return;
        }
        $row = $this->findInvoice($p['invoice_id']);
        $payments = $this->invoicePayments($p['invoice_id']);
        // Pagos anteriores a este (por fecha y orden de registro), como en la app.
        $previous = 0;
        foreach ($payments as $other) {
            if ($other['id'] === $p['id']) {
                break;
            }
            $previous += (int) round($other['amount'] * 100);
        }
        echo View::render('print_receipt', [
            'title' => 'Recibo ' . $p['receipt_number'],
            'payment' => $p,
            'invoice' => self::summarize($row, $payments),
            'previous' => $previous / 100,
            'issuerRow' => $this->issuer,
            'currency' => $this->issuer['currency'] ?? '$',
        ], 'print');
    }

    // ---------------------------------------------------------------- equipo

    private function team(): void
    {
        $this->page('team', 'Equipo', ['users' => UserService::list((int) $this->user['business_id'])]);
    }

    private function userForm(?int $id, ?string $error = null, ?array $values = null): void
    {
        $member = null;
        if ($id !== null) {
            try {
                $member = UserService::find((int) $this->user['business_id'], $id);
            } catch (ApiException) {
                http_response_code(404);
                $this->page('error', 'Usuario no encontrado', ['message' => 'Ese usuario no existe en este negocio.']);
                return;
            }
        }
        $isSelf = $member && (int) $member['id'] === (int) $this->account['id'];
        $this->page('user_form', $member ? 'Editar usuario' : 'Nuevo usuario', [
            'member' => $member,
            'error' => $error,
            'values' => $values ?? ($member ?? ['name' => '', 'username' => '', 'role' => 'member', 'active' => 1, 'web_access' => 0]),
            'isSelf' => $isSelf,
            // Cuentas compartidas con otros negocios: nombre y contraseña solo
            // los cambia el superadministrador (o la propia persona).
            'accountLocked' => $member && !$isSelf && !$this->account['is_superadmin']
                && ((int) $member['business_count'] > 1 || $member['is_superadmin']),
        ]);
    }

    private function createUser(): void
    {
        try {
            $user = UserService::create(
                (int) $this->user['business_id'],
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['role'] ?? 'member'),
                isset($_POST['web_access']),
            );
            $this->flash('ok', "Usuario {$user['username']} creado. Ya puede iniciar sesión en la app"
                . ($user['web_access'] ? ' y en la web.' : '.'));
            $this->redirect('/equipo');
        } catch (ApiException $e) {
            $this->userForm(null, $e->getMessage(), $_POST);
        }
    }

    private function updateUser(int $id): void
    {
        $isSelf = $id === (int) $this->account['id'];
        $changes = [
            'role' => (string) ($_POST['role'] ?? 'member'),
            // No te puedes desactivar a ti mismo desde aquí.
            'active' => $isSelf ? true : isset($_POST['active']),
            'web_access' => isset($_POST['web_access']),
            'password' => (string) ($_POST['password'] ?? ''),
        ];
        if (isset($_POST['name'])) {
            $changes['name'] = (string) $_POST['name'];
        }
        try {
            $user = UserService::update((int) $this->user['business_id'], $this->user, $id, $changes);
            if ($isSelf) {
                $_SESSION['ver'] = (int) $user['token_version'];
            }
            $this->flash('ok', $changes['password'] !== ''
                ? "Datos guardados. La nueva contraseña de {$user['username']} ya está activa y sus sesiones se cerraron."
                : 'Datos del usuario guardados.');
            $this->redirect('/equipo');
        } catch (ApiException $e) {
            $this->userForm($id, $e->getMessage(), ['id' => $id] + $_POST + ['username' => '', 'active' => 0, 'web_access' => 0]);
        }
    }

    // ---------------------------------------------------------------- emisor

    private function issuerForm(?string $error = null): void
    {
        $this->page('issuer', 'Datos del emisor', ['issuerRow' => $this->issuer, 'error' => $error]);
    }

    private function saveIssuer(): void
    {
        $data = [];
        foreach (['name', 'tax_id', 'address', 'city', 'phone', 'email', 'bank_account', 'invoice_prefix', 'currency'] as $f) {
            $data[$f] = trim((string) ($_POST[$f] ?? ''));
        }
        $data['default_tax_rate'] = self::decimal($_POST['default_tax_rate'] ?? '0');
        $next = trim((string) ($_POST['next_invoice_number'] ?? ''));
        if ($next !== '' && (int) $next !== (int) $this->issuer['next_invoice_number']) {
            $data['next_invoice_number'] = $next;
        }
        if (isset($_POST['remove_logo'])) {
            $data['logo'] = null;
        } elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $data['logo'] = base64_encode((string) file_get_contents($_FILES['logo']['tmp_name']));
        } elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->issuerForm('No se pudo subir el logo (máximo 1 MB, PNG o JPG).');
            return;
        }
        $result = $this->push('issuer_update', $data);
        if ($result['status'] !== 'ok') {
            $this->issuerForm($result['message']);
            return;
        }
        $this->flash('ok', 'Datos del emisor guardados. La app los recibirá al sincronizar.');
        $this->redirect('/emisor');
    }

    // -------------------------------------------------------------- negocios

    private function businessList(): void
    {
        $this->page('businesses', 'Negocios', ['list' => BusinessService::list()]);
    }

    private function businessForm(?string $error = null, array $values = []): void
    {
        $this->page('business_form', 'Nuevo negocio', ['error' => $error, 'values' => $values + ['include_me' => '1']]);
    }

    private function createBusiness(): void
    {
        $admin = null;
        if (trim((string) ($_POST['admin_username'] ?? '')) !== '') {
            $admin = ['name' => $_POST['admin_name'] ?? '', 'username' => $_POST['admin_username'] ?? '',
                'password' => $_POST['admin_password'] ?? ''];
        }
        try {
            $b = BusinessService::create((string) ($_POST['name'] ?? ''), $this->account, isset($_POST['include_me']), $admin);
        } catch (ApiException $e) {
            $this->businessForm($e->getMessage(), $_POST);
            return;
        }
        $_SESSION['bid'] = (int) $b['id'];
        $this->flash('ok', "Negocio «{$b['name']}» creado. Ahora estás trabajando en él: completa los datos del emisor.");
        $this->redirect('/emisor');
    }

    private function businessEdit(int $id, ?string $error = null): void
    {
        try {
            $b = BusinessService::find($id);
        } catch (ApiException) {
            http_response_code(404);
            $this->page('error', 'Negocio no encontrado', ['message' => 'Ese negocio no existe.']);
            return;
        }
        $this->page('business_edit', $b['name'], [
            'b' => $b, 'members' => UserService::list($id), 'error' => $error,
        ]);
    }

    private function updateBusiness(int $id): void
    {
        try {
            BusinessService::update($id, ['name' => (string) ($_POST['name'] ?? ''), 'active' => isset($_POST['active'])]);
        } catch (ApiException $e) {
            $this->businessEdit($id, $e->getMessage());
            return;
        }
        $this->flash('ok', isset($_POST['active']) ? 'Negocio guardado.' : 'Negocio guardado y desactivado: nadie de su equipo puede entrar.');
        $this->redirect("/negocios/$id");
    }

    private function addMember(int $id): void
    {
        try {
            $user = BusinessService::addMemberByUsername($id, (string) ($_POST['username'] ?? ''), (string) ($_POST['role'] ?? 'member'));
        } catch (ApiException $e) {
            $this->businessEdit($id, $e->getMessage());
            return;
        }
        $this->flash('ok', "{$user['name']} ahora pertenece a este negocio. Lo verá al cambiar de negocio en la app.");
        $this->redirect("/negocios/$id");
    }

    // ---------------------------------------------------------------- cuenta

    private function account(?string $error = null): void
    {
        $this->page('account', 'Mi cuenta', ['error' => $error]);
    }

    private function changePassword(): void
    {
        if ((string) ($_POST['new'] ?? '') !== (string) ($_POST['repeat'] ?? '')) {
            $this->account('Las contraseñas nuevas no coinciden.');
            return;
        }
        try {
            $account = UserService::changeOwnPassword((int) $this->account['id'], (string) ($_POST['current'] ?? ''), (string) ($_POST['new'] ?? ''));
        } catch (ApiException $e) {
            $this->account($e->getMessage());
            return;
        }
        $_SESSION['ver'] = (int) $account['token_version'];
        $this->flash('ok', 'Contraseña cambiada. Se cerró tu sesión en la app y en otros navegadores.');
        $this->redirect('/cuenta');
    }

    // ----------------------------------------------------------------- comunes

    /** Aplica un cambio con las mismas reglas que la sincronización de la app. */
    private function push(string $type, array $data): array
    {
        return (new SyncService($this->user))->push([['type' => $type, 'data' => $data]])[0];
    }

    /** Número escrito a mano: acepta "1.234,5", "1234,5" y "1234.5". */
    private static function decimal(mixed $value): string
    {
        $s = trim((string) $value);
        if (str_contains($s, ',')) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        }
        return $s;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Facturas activas con su total, pagado, saldo y estado calculados. */
    private function loadInvoices(string $q = ''): array
    {
        $sql = 'SELECT * FROM invoices WHERE business_id = ? AND deleted_at IS NULL';
        $params = [$this->user['business_id']];
        if ($q !== '') {
            $sql .= ' AND (client_name LIKE ? OR number LIKE ? OR client_tax_id LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $rows = Database::all($sql . ' ORDER BY issue_date DESC, number DESC', $params);
        $paid = [];
        foreach (Database::all(
            'SELECT invoice_id, COALESCE(SUM(ROUND(amount * 100)), 0) AS c, COUNT(*) AS n FROM payments
             WHERE business_id = ? AND deleted_at IS NULL GROUP BY invoice_id',
            [$this->user['business_id']],
        ) as $p) {
            $paid[$p['invoice_id']] = $p;
        }
        return array_map(fn($r) => self::summarize($r, null, $paid[$r['id']] ?? null), $rows);
    }

    private static function summarize(array $row, ?array $payments, ?array $paidRow = null): array
    {
        $totalCents = SyncService::totalCents($row);
        if ($payments !== null) {
            $paidCents = array_sum(array_map(fn($p) => (int) round($p['amount'] * 100), $payments));
            $count = count($payments);
        } else {
            $paidCents = (int) ($paidRow['c'] ?? 0);
            $count = (int) ($paidRow['n'] ?? 0);
        }
        $isPaid = (bool) $row['paid'];
        $overdue = !$isPaid && $row['due_date'] !== null && $row['due_date'] < date('Y-m-d');
        return $row + [
            'total' => $totalCents / 100,
            'paid_amount' => $paidCents / 100,
            'balance' => max(0, $totalCents - $paidCents) / 100,
            'payment_count' => $count,
            'overdue' => $overdue,
            'status' => $isPaid ? 'paid' : ($overdue ? 'overdue' : ($count > 0 ? 'partial' : 'pending')),
        ];
    }

    private function page(string $template, string $title, array $vars = []): void
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $lastReceipt = $_SESSION['last_receipt'] ?? null;
        unset($_SESSION['last_receipt']);
        echo View::render($template, $vars + [
            'title' => $title,
            'account' => $this->account,
            'user' => $this->user,
            'business' => $this->business,
            'businesses' => $this->businesses,
            'isAdmin' => $this->isAdmin(),
            'isSuper' => (bool) ($this->account['is_superadmin'] ?? false),
            'currency' => $this->issuer['currency'] ?? '$',
            'csrf' => $_SESSION['csrf'],
            'flash' => $flash,
            'lastReceipt' => $lastReceipt,
            // Sección marcada en la navegación.
            'section' => match ($template) {
                'invoices', 'invoice', 'invoice_form' => 'facturas',
                'team', 'user_form' => 'equipo',
                'issuer' => 'emisor',
                'account' => 'cuenta',
                'businesses', 'business_form', 'business_edit' => 'negocios',
                default => 'resumen',
            },
        ]);
    }

    private function flash(string $type, string $text): void
    {
        $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    }

    private function redirect(string $path): never
    {
        header('Location: ' . View::url($path));
        exit;
    }
}
