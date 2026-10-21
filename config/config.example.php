<?php
// Copia este archivo como config.php y ajusta los valores.
// config.php no se sube al repositorio (contiene contraseñas).
return [
    'db' => [
        // En WAMP, MariaDB escucha en el puerto 3307; en Ubuntu suele ser 3306.
        'dsn'      => 'mysql:host=127.0.0.1;port=3307;dbname=facturas;charset=utf8mb4',
        'user'     => 'root',
        'password' => '',
    ],
    // Clave para firmar las sesiones. Genera una con:
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'jwt_secret' => 'F4ctur4sS3cr3tC0ntr4s3ñ4L4rg4YR3c0m3nd4d4',
    // Duración de la sesión corta (segundos) y de la renovable (días).
    'access_ttl'       => 3600,
    'refresh_ttl_days' => 90,
];
