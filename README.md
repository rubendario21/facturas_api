# API y portal web de Facturas

Servidor de la app de facturas. Es PHP 8, sin dependencias (no hace falta Composer), y funciona con MariaDB 10.6+ o MySQL 8+. Hace dos cosas:

1. **API** para la app móvil: inicio de sesión, sincronización y trabajo sin conexión.
2. **Portal web** en `/admin`: es la versión web de la app (facturar y cobrar desde el navegador, también desde iPhone) y además la administración de equipo, emisor y negocios.

Producción: **`https://movil.peoplecore.com.co`**

| Uso | Dirección |
|---|---|
| Servidor que se pone en la app | `https://movil.peoplecore.com.co` |
| Portal web | `https://movil.peoplecore.com.co/admin` |
| Comprobación rápida | `https://movil.peoplecore.com.co/status` |

---

## Conceptos

- **Negocio:** cada cliente tiene el suyo, con sus facturas, su emisor (datos fiscales y logo), su numeración (`F-0001`, `RC-0001`) y su equipo. Nadie ve los datos de otro negocio.
- **Cuenta:** una por persona (usuario y contraseña), única en toda la plataforma. Una misma cuenta puede pertenecer a **varios negocios**, con un rol distinto en cada uno.
- **Roles en un negocio:**
  - **Miembro:** crea y edita facturas, y registra pagos o abonos.
  - **Administrador:** además gestiona el equipo y el emisor, y puede eliminar pagos.
- **Acceso web:** el administrador decide qué miembros pueden usar también el portal web. Los administradores siempre pueden.
- **Superadministrador:** el dueño de la plataforma, que es la cuenta creada en el alta inicial. Crea negocios para sus clientes, los activa o desactiva y puede entrar en cualquiera desde la web.

## Reglas que garantiza el servidor

- La numeración de facturas y recibos **no se repite**, aunque se creen sin conexión en varios dispositivos o desde la web. Las facturas creadas sin conexión se numeran al sincronizar.
- Un pago **no puede superar el saldo**, aunque dos personas cobren a la vez.
- Una factura **con pagos no se edita ni se elimina**. Queda pagada cuando los pagos cubren el total.
- **Desactivar a una persona o un negocio**, o cambiarle la contraseña, **cierra sus sesiones al momento** en la app y en la web.
- El administrador de un cliente **no puede cambiar el nombre ni la contraseña** de alguien que también trabaja en otros negocios, como el superadministrador. Eso solo lo hace el superadministrador.
- Seguridad:
  - Contraseñas con `password_hash`.
  - Bloqueo de 15 minutos tras 5 intentos fallidos.
  - En el portal: token CSRF en cada formulario, cookie `HttpOnly`/`SameSite` (y `Secure` con HTTPS) y la sesión caduca tras 2 horas sin actividad.

---

## Instalación en el servidor (Ubuntu + Nginx + MariaDB)

Requisitos: PHP 8.1 o superior con `fpm`, `mysql`, `mbstring` y `curl`, Nginx, MariaDB y el DNS de `movil.peoplecore.com.co` apuntando a la IP del servidor.

### 1. Código

```bash
sudo apt install php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-curl   # ajusta la versión
sudo git clone <tu-repositorio> /var/www/facturas_api
```

### 2. Base de datos con un usuario propio (no root)

```bash
sudo mariadb < /var/www/facturas_api/database/schema.sql
sudo mariadb -e "CREATE USER 'facturas'@'localhost' IDENTIFIED BY 'UNA-CONTRASEÑA-FUERTE';
                 GRANT SELECT, INSERT, UPDATE, DELETE ON facturas.* TO 'facturas'@'localhost';"
```

### 3. Configuración

```bash
cd /var/www/facturas_api
sudo cp config/config.example.php config/config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"      # copia esta clave
sudo nano config/config.php
```

En `config/config.php`:
- `dsn`: `mysql:host=127.0.0.1;port=3306;dbname=facturas;charset=utf8mb4`
- `user` y `password`: el usuario `facturas` del paso 2.
- `jwt_secret`: la clave generada. **Debe ser distinta en cada servidor y no compartirse.** Si se cambia, se cierran todas las sesiones.

Protege el archivo:

```bash
sudo chown root:www-data config/config.php && sudo chmod 640 config/config.php
```

### 4. Nginx y HTTPS

```bash
sudo cp deploy/nginx.conf.example /etc/nginx/sites-available/facturas_api
sudo ln -s /etc/nginx/sites-available/facturas_api /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d movil.peoplecore.com.co
```

Con HTTPS, las contraseñas y los datos viajan cifrados. Certbot renueva el certificado solo; compruébalo con `sudo certbot renew --dry-run`.

### 5. Comprobación

```bash
curl https://movil.peoplecore.com.co/status
# {"ok":true,"needs_setup":true}
```

### 6. Alta inicial (una sola vez)

Abre la app, pon como servidor `https://movil.peoplecore.com.co` y crea tu negocio con tu usuario. **Esa primera cuenta es la de superadministrador.** Después entra en `https://movil.peoplecore.com.co/admin` para crear los negocios de tus clientes.

### 7. Copias de seguridad diarias

Con `sudo crontab -e`, añade:

```
0 3 * * * mysqldump --single-transaction facturas | gzip > /var/backups/facturas-$(date +\%F).sql.gz
0 4 * * * find /var/backups -name 'facturas-*.sql.gz' -mtime +30 -delete
```

---

## Actualizar el servidor a una versión nueva

**Haz siempre una copia de seguridad antes de actualizar** (el mismo `mysqldump` del paso 7).

```bash
cd /var/www/facturas_api
sudo git pull
sudo FACTURAS_CONFIG=config/config.migrate.php php database/migrate.php
sudo systemctl reload php8.3-fpm
```

`database/migrate.php` aplica solo lo que falte y se puede ejecutar varias veces sin riesgo.

Las migraciones cambian tablas y el usuario `facturas` solo tiene permisos sobre los datos, que es lo más seguro para el día a día. Por eso se ejecutan con el administrador de MariaDB, con una configuración aparte que se crea una sola vez:

```bash
sudo cp config/config.php config/config.migrate.php
sudo nano config/config.migrate.php
#   'dsn'      => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=facturas;charset=utf8mb4',
#   'user'     => 'root',
#   'password' => '',
sudo chmod 600 config/config.migrate.php
```

En Ubuntu, `root` de MariaDB entra sin contraseña por el socket cuando se ejecuta con `sudo`. Este archivo no se sube al repositorio.

Migraciones incluidas:

| Migración | Qué hace |
|---|---|
| `002_multi_negocio` | Pasa de un negocio a varios. Cada usuario se convierte en miembro de su negocio con el mismo rol, el primer administrador pasa a ser superadministrador y se añade el acceso web por persona. No se pierden facturas, pagos ni usuarios. |

---

## Uso del portal web (`/admin`)

| Sección | Quién | Para qué |
|---|---|---|
| **Resumen** | todos | Lo que falta por cobrar, lo vencido, lo cobrado en el mes, las facturas vencidas a reclamar y los últimos pagos |
| **Facturas** | todos | Crear y editar facturas, registrar pagos o abonos e imprimir o guardar en PDF la factura y cada recibo |
| **Equipo** | administradores | Crear usuarios, cambiar el rol, dar acceso web, desactivar y restablecer contraseñas |
| **Datos del emisor** | administradores | Nombre fiscal, identificación, logo, numeración, IVA y moneda |
| **Negocios** | superadministrador | Crear negocios para clientes con su primer administrador, activarlos o desactivarlos y añadir cuentas existentes |
| **Mi cuenta** | todos | Cambiar la propia contraseña |

Si una persona trabaja en varios negocios, los cambia con el selector de arriba a la izquierda.

**PDF desde la web:** el botón *Imprimir o guardar PDF* abre la factura o el recibo con el mismo diseño que la app. En el diálogo de impresión, elige **Guardar como PDF**. Funciona igual en Safari para iPhone.

---

## Desarrollo local (WAMP)

1. Crea la base: `mysql -u root -P 3307 < database/schema.sql` (en WAMP, MariaDB usa el puerto 3307).
2. Copia `config/config.example.php` a `config/config.php`. El ejemplo ya apunta a `127.0.0.1:3307`.
3. Usa una de estas dos opciones:
   - **Con el Apache de WAMP:** `http://localhost/facturas_api/public` (la web en `.../public/admin`).
   - **Con el servidor de PHP:** `php -S 0.0.0.0:8080 -t public public/index.php`, en `http://IP-DEL-PC:8080`. Así también lo alcanza un móvil de la misma red Wi-Fi.

Para otra configuración, por ejemplo una base de pruebas: `FACTURAS_CONFIG=/ruta/config_pruebas.php php -S ...`

### Pruebas automáticas

**Siempre contra una base de pruebas vacía, nunca contra la de producción:**

```bash
php tests/api_test.php http://127.0.0.1:8080             # API: login, sincronización, pagos, roles (33 casos)
php tests/portal_test.php http://127.0.0.1:8080          # portal: acceso, equipo, emisor, pagos (21)
php tests/multi_business_test.php http://127.0.0.1:8080  # varios negocios, acceso web, facturar en la web (31)
php tests/concurrency_test.php http://localhost/facturas_api/public  # pagos simultáneos (tras api_test)
```

La de concurrencia necesita un servidor que atienda peticiones en paralelo, como Apache o Nginx. El servidor integrado de PHP en Windows las atiende de una en una.

---

## Endpoints de la API

| Método | Ruta | Quién | Descripción |
|---|---|---|---|
| GET | `/status` | todos | Estado; `needs_setup` si el servidor está vacío |
| POST | `/auth/setup` | — | Alta inicial: primer negocio y superadministrador (solo la primera vez) |
| POST | `/auth/login` | — | `{username, password, business_id?}` → sesión en el último negocio usado (o el indicado) |
| POST | `/auth/refresh` | — | `{refresh_token}` → nuevos tokens |
| GET | `/auth/me` | usuario | Usuario, negocio actual y lista de negocios |
| GET | `/auth/businesses` | usuario | Negocios en los que trabaja |
| POST | `/auth/switch` | usuario | `{business_id}` → sesión en otro de sus negocios |
| POST | `/auth/password` | usuario | `{current_password, new_password}` |
| GET / POST | `/users` | admin | Equipo del negocio actual (normalmente se usa el portal) |
| PATCH | `/users/{id}` | admin | `name`, `role`, `active`, `web_access`, `password` |
| GET / POST | `/businesses` | superadmin | Listar / crear negocios (`{name, include_me, admin?}`) |
| PATCH | `/businesses/{id}` | superadmin | `{name?, active?}` |
| POST | `/businesses/{id}/members` | superadmin | `{username, role}`: añade una cuenta existente |
| POST | `/sync` | usuario | `{cursor, changes:[{type, data}]}` → resultados y cambios desde `cursor` |

Tipos de cambio en `/sync`:
- `invoice_upsert`, `invoice_delete` y `payment_add`, para cualquier miembro.
- `payment_delete` e `issuer_update`, solo para administradores.

## Estructura

```
public/            index.php (entrada única), assets/ del portal (CSS, JS, impresión)
src/               API: Auth, SyncService, UserService, BusinessService, controladores
src/Portal/        portal web (Portal.php) y utilidades de vista
templates/portal/  páginas del portal, factura y recibo para imprimir
database/          schema.sql (instalación nueva) y migrate.php (actualizaciones)
deploy/            nginx.conf.example para movil.peoplecore.com.co
tests/             pruebas automáticas
```
