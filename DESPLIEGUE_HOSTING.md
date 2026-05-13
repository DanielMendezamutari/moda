# Manual de despliegue en hosting (Moda POS)

Este proyecto tiene **dos partes**:

| Parte | Tecnología | Rol |
|--------|-------------|-----|
| `backend/` | Laravel 12, PHP 8.2+, JWT | API REST (`/api/...`) |
| `frontend/` | Vue 3 + Vite | SPA (panel web) |

En **producción** el frontend se compila a archivos estáticos (`dist/`). El navegador llama al backend por HTTPS usando la URL configurada en `VITE_API_BASE_URL`.

### Forma más simple (recomendada en cPanel): un solo `public` de Laravel

No hace falta que el dominio apunte a una carpeta aparte solo para Vue.

1. **Document root del dominio** = carpeta **`backend/public`** de Laravel (donde está el `index.php` de Laravel).
2. En tu PC, en `frontend/`: ajustá **`frontend/.env.hosting`** (`VITE_APP_BASE` y `VITE_API_BASE_URL` deben coincidir con las URLs reales que ves en el navegador, incluido `/panel/` y `/api`).
3. Compilá: `npm run build:hosting` (o `pnpm run build:hosting`).
4. Publicá el panel dentro de Laravel: desde `backend/`, `php artisan panel:publish` (copia el build a `public/panel/`).
5. En el navegador: **`/`** redirige a **`/panel/`** (o la ruta completa si Laravel está bajo subcarpetas, p. ej. `…/moda/backend/public/panel/`).

El API sigue en **`…/api`**. No subas `.git` ni el código fuente del frontend a la raíz pública del dominio; solo el proyecto Laravel con `public/panel/` generado por el comando.

---

## cPanel: PHP 8.2 (ea-php82), Git, Artisan y panel:publish

En **cPanel** el binario `php` del SSH a veces **no** es el mismo que usa el dominio (puede ser 7.x). Para Laravel 12 y Artisan usá explícitamente el PHP que elige **Select PHP Version** (ej. 8.2):

```bash
# Ajustá la ruta si en cPanel usás otra versión (ea-php83, ea-php81, etc.)
export PHP_BIN=/opt/cpanel/ea-php82/root/usr/bin/php
$PHP_BIN -v
```

Todos los `artisan` del servidor deberían ejecutarse así (desde la carpeta `backend/` del proyecto):

```bash
cd /home/TU_USUARIO/ruta/al/repo/backend
$PHP_BIN artisan panel:publish
$PHP_BIN artisan migrate --force
$PHP_BIN artisan config:cache
# etc.
```

Opcional: agregá a `~/.bashrc` la línea `export PHP_BIN=...` para no repetirla en cada sesión SSH.

### Varios subdominios (misma app en varios sitios)

Cada subdominio suele ser **una copia del proyecto** o **un document root distinto** con su propio `.env` en `backend/`:

| Qué | Por subdominio |
|-----|----------------|
| `backend/.env` | `APP_URL`, `DB_*`, `JWT_SECRET` propios (o misma BD si comparten datos). |
| Build del panel (`compilacion-para-hosting`) | Si la URL en el navegador **cambia** (ruta distinta a `/panel/`), cada build necesita su **`frontend/.env.hosting`** (`VITE_APP_BASE`, `VITE_API_BASE_URL`) y un **commit** de `compilacion-para-hosting` **para esa URL**, o compilás por entorno y desplegás solo el ZIP a ese servidor. |

Si todos los subdominios sirven el **mismo** path público (mismo `VITE_APP_BASE` / misma API), un solo build alcanza para todos.

### Cada vez que cambiás el panel Vue (dashboard, páginas, estilos)

`public/panel/` **no** está en Git; lo que versionamos es **`frontend/compilacion-para-hosting/`**. Flujo recomendado:

**En tu PC (donde compilás sin límites de tiempo del hosting)**

1. Editá `frontend/.env.hosting` con la URL real de **ese** despliegue (`VITE_APP_BASE` y `VITE_API_BASE_URL`).
2. Generá el build:
   ```bash
   cd frontend
   pnpm install --frozen-lockfile   # o npm install
   pnpm run build:hosting           # o npm run build:hosting
   ```
3. Commiteá y subí el resultado estático:
   ```bash
   cd ..
   git add frontend/compilacion-para-hosting
   git commit -m "build(frontend): panel para producción (subdominio X)"
   git push origin main
   ```

**En el servidor (SSH), dentro del clon de ese subdominio**

1. Actualizá el código:
   ```bash
   cd /home/TU_USUARIO/ruta/al/repo
   git pull
   ```
2. Copiá el build versionado a `public/panel/`:
   ```bash
   export PHP_BIN=/opt/cpanel/ea-php82/root/usr/bin/php
   cd backend
   $PHP_BIN artisan panel:publish
   ```
3. Si hubo migraciones o cambios de `.env`:
   ```bash
   $PHP_BIN artisan migrate --force
   $PHP_BIN artisan config:cache
   $PHP_BIN artisan route:cache
   $PHP_BIN artisan view:cache
   ```
4. En el navegador: **recarga forzada** (Ctrl+F5) para no ver JS viejo en caché.

**Si solo cambió backend (PHP, rutas, migraciones)** y **no** tocaste Vue: alcanza con `git pull` + `composer install` si aplica + `migrate` / `config:cache` con el mismo `$PHP_BIN artisan ...` — **no** hace falta `panel:publish`.

---

## 1. Requisitos del hosting

### 1.1 Recomendado: VPS o hosting con SSH

Laravel 12 encaja mejor en un **VPS** (Ubuntu, Debian, AlmaLinux, etc.) con:

- **PHP 8.2 o superior** (extensiones habituales: `openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd` o equivalente para imágenes/PDF si aplica)
- **Composer 2**
- **MySQL 8** o **MariaDB 10.3+** (o compatible)
- **Nginx** o **Apache** con `mod_rewrite`
- Opcional: **Redis** (si más adelante cambiás colas/caché a Redis)

### 1.2 Hosting compartido “solo cPanel + FTP”

Es posible pero **más limitado**: necesitás subir `vendor/` ya generado en tu PC, tener PHP ≥ 8.2, y que el proveedor permita apuntar el **document root** a la carpeta `public` de Laravel o usar `.htaccess` en la raíz del dominio. Si no podés ejecutar `php artisan migrate` por SSH, tendrías que importar un SQL exportado desde tu entorno de desarrollo (menos ideal para actualizaciones).

Este manual asume **SSH + Composer + Node** (flujo estándar).

---

## 2. Estructura sugerida en el servidor

Ejemplo con dominio `https://app.tudominio.com` para el **frontend** y `https://api.tudominio.com` para el **backend** (también podés usar un solo dominio con rutas; ver sección 6).

```
/var/www/moda/
├── backend/          # Clon o subida del repo (carpeta Laravel)
└── frontend/       # Clon o subida; aquí se genera dist/
```

Document root del **API**:

- Debe apuntar a `backend/public` (no a la raíz de `backend/`).

El **frontend** compilado puede servirse:

- Desde Nginx/Apache como sitio estático apuntando a `frontend/dist`, o  
- Copiando el contenido de `frontend/dist` dentro de `backend/public/app` y una regla de rutas (opcional; lo más limpio es dos virtual hosts).

---

## 3. Backend (Laravel)

### 3.1 Subir código

Subí el contenido de `backend/` (sin `node_modules`; `vendor/` podés generarlo en el servidor).

### 3.2 Variables de entorno

1. Copiá `.env.example` a `.env`.
2. Editá al menos:

```env
APP_NAME=Moda
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.tudominio.com

# Zona horaria Bolivia (recomendado para tickets/reportes)
APP_TIMEZONE=America/La_Paz

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nombre_bd
DB_USERNAME=usuario_bd
DB_PASSWORD=contraseña_segura

# JWT (obligatorio en este proyecto)
JWT_SECRET=generar_con_artisan
JWT_TTL=60
```

3. Generá clave de aplicación y secreto JWT:

```bash
cd /var/www/moda/backend
php artisan key:generate
php artisan jwt:secret
```

4. **Nunca** subas `.env` a un repositorio público.

### 3.3 Dependencias PHP (producción)

```bash
composer install --no-dev --optimize-autoloader
```

### 3.4 Base de datos

Creá la base de datos vacía en MySQL y ejecutá:

```bash
php artisan migrate --force
```

Si es la primera vez y necesitás roles/permisos base:

```bash
php artisan db:seed --class=RolePermissionSeeder --force
```

(Usá seeders solo si corresponde a tu política de datos iniciales.)

### 3.5 Enlace de almacenamiento y permisos

```bash
php artisan storage:link
chmod -R ug+rwx storage bootstrap/cache
```

El usuario del servidor web (p. ej. `www-data`) debe poder escribir en `storage/` y `bootstrap/cache/`.

### 3.6 Optimización

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Tras cada cambio en `.env`, ejecutá de nuevo `php artisan config:cache`.

### 3.7 Cola y programador (opcional)

Si en `.env` usás `QUEUE_CONNECTION=database` y la app despacha trabajos en cola, configurá un **worker** (Supervisor) con:

```bash
php artisan queue:work
```

Para tareas programadas (`app/Console/Kernel.php` o `routes/console.php` en Laravel 11+):

```cron
* * * * * cd /var/www/moda/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 4. Frontend (Vue + Vite)

### 4.1 Variables de entorno de build

En `frontend/` creá `.env.production` (o usá CI/CD con variables de entorno) con la URL **pública** del API, **incluyendo** `/api` y **sin** barra final:

```env
VITE_API_BASE_URL=https://api.tudominio.com/api
```

Si el frontend y el API comparten el mismo dominio y el API está en `/api`:

```env
VITE_API_BASE_URL=https://app.tudominio.com/api
```

### 4.2 Compilar

Con Node 18+ o 20 LTS:

```bash
cd /var/www/moda/frontend
# Este repo usa `pnpm-lock.yaml` (no `package-lock.json`). En el servidor:
#   pnpm install --frozen-lockfile && pnpm run build:hosting
# o con npm únicamente:
npm install
npm run build:hosting
```

El resultado queda en `frontend/compilacion-para-hosting/`. Publicalo en Laravel con `cd ../backend && php artisan panel:publish` (copia a `public/panel/`).

#### Build en tu PC solo para el hosting (carpeta aparte)

Si preferís compilar en tu máquina y subir estáticos sin tocar `dist/` ni tu `.env` de desarrollo:

```bash
cd frontend
pnpm run build:hosting
# o: npm run build:hosting
```

- Lee `frontend/.env.hosting` (URL del API de producción).
- Genera `frontend/compilacion-para-hosting/` (subí **ese** contenido al hosting por FTP/SFTP). Esa carpeta también puede estar versionada en Git para desplegar sin compilar en el servidor.

Para otra URL de API, editá `frontend/.env.hosting` antes del comando y volvé a ejecutar `build:hosting` antes de subir o de hacer commit.

### 4.3 Despliegue en subcarpeta (`https://dominio.com/moda/`)

Si el SPA no está en la raíz del dominio, en `vite.config.js` hay que definir `base: '/moda/'` (u otra ruta), volver a compilar, y configurar el servidor para que todas las rutas del SPA redirijan a `index.html` (fallback SPA).

### 4.4 Ejemplo: `https://moda.ribersoft.com/` = panel (SPA en la raíz del dominio)

1. **DNS**  
   El registro **A** (o **CNAME**) de `moda.ribersoft.com` debe apuntar al servidor del hosting (si ya abrís el API en ese dominio, esto ya está bien).

2. **Build en tu PC** (API en `/moda/backend/public/api`, ver `frontend/.env.hosting`):

   ```bash
   cd frontend
   pnpm run build:hosting
   ```

3. **Subir archivos**  
   Subí **todo el contenido** de `frontend/compilacion-para-hosting/` (incluido `.htaccess`) a la carpeta que Apache use como **raíz del sitio** para `moda.ribersoft.com`. En cPanel con dominio principal suele ser `public_html/`; si usás un subdominio o “carpeta de inicio” distinta, usá esa ruta.

4. **Apache**  
   El build incluye `public/.htaccess` (reglas `mod_rewrite`) para que rutas como `/login` o `/ventas` carguen `index.html`. En cPanel debe estar activo **AllowOverride** para esa carpeta (muchas cuentas ya lo traen por defecto).

5. **Convivencia con el backend**  
   Si el API sigue en `https://moda.ribersoft.com/moda/backend/public/api`, no hace falta mezclar PHP del Laravel en la misma carpeta que el SPA: el panel en la raíz solo sirve HTML/JS/CSS; las peticiones van por HTTPS a la URL del `.env.hosting`.

---

## 5. Servidor web

### 5.1 Nginx (ejemplo API Laravel)

```nginx
server {
    listen 443 ssl http2;
    server_name api.tudominio.com;
    root /var/www/moda/backend/public;

    index index.php;
    client_max_body_size 32M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Ajustá la ruta del socket PHP-FPM según tu servidor.

### 5.2 Nginx (ejemplo SPA estático)

```nginx
server {
    listen 443 ssl http2;
    server_name app.tudominio.com;
    root /var/www/moda/frontend/dist;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

### 5.3 Apache (API)

DocumentRoot debe ser `.../backend/public`. Asegurate de que `AllowOverride All` esté habilitado para que `.htaccess` de Laravel funcione.

---

## 6. Mismo dominio: API y SPA juntos (opcional)

Podés servir el build del frontend desde `backend/public/` (por ejemplo `public/spa/`) y un único virtual host con:

- Archivos estáticos desde esa carpeta  
- Rutas `/api/*` resueltas por Laravel  

Esto implica más reglas de Nginx/Apache; el enfoque **dos subdominios** suele ser más simple y evita conflictos con `try_files`.

---

## 7. CORS, cookies y HTTPS

El frontend usa **JWT** (típicamente en cookie `accessToken` según el cliente en `frontend/src/utils/api.js`). Para que login y refresh funcionen entre **origen** del SPA y **origen** del API:

- Si son **dominios distintos** (`app.` y `api.`), revisá en el backend:
  - `config/cors.php` (orígenes permitidos),
  - cookies: `SameSite`, `Secure` en HTTPS, y si hace falta dominio de cookie compartido (mismo dominio padre con subdominios es un caso especial).
- En **producción** usá **HTTPS** en ambos extremos.

Si todo vive en el **mismo host y puerto** (misma origin), CORS suele ser mínimo.

---

## 8. Checklist de seguridad

- [ ] `APP_DEBUG=false` y `APP_ENV=production`
- [ ] `APP_KEY` y `JWT_SECRET` únicos y secretos
- [ ] Usuario MySQL con permisos solo sobre su base de datos
- [ ] Firewall: solo 80/443 (y SSH restringido)
- [ ] Copias de seguridad automáticas de la BD
- [ ] No exponer `.env`, `composer.json` con credenciales, ni listados de directorio

---

## 9. Actualizar una versión nueva

En el servidor (VPS: `export PHP_BIN=php`; **cPanel:** usá `$PHP_BIN` como en [cPanel: PHP 8.2 (ea-php82), Git, Artisan y panel:publish](#cpanel-php-82-ea-php82-git-artisan-y-panelpublish)):

```bash
cd /var/www/moda/backend   # o la ruta real del clon
git pull                   # o subida de archivos
composer install --no-dev --optimize-autoloader
$PHP_BIN artisan migrate --force
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
```

En VPS podés definir `PHP_BIN=php`. Si el panel Vue cambió y **subiste** `frontend/compilacion-para-hosting/` con Git:

```bash
cd /var/www/moda/backend
$PHP_BIN artisan panel:publish
```

Si **no** versionás el build y compilás en el servidor:

```bash
cd /var/www/moda/frontend
git pull
npm install
npm run build:hosting
cd ../backend && $PHP_BIN artisan panel:publish
```

---

## 10. Problemas frecuentes

| Síntoma | Qué revisar |
|---------|-------------|
| 500 en `/api` | `storage/logs/laravel.log`, permisos `storage/`, `APP_KEY`, extensiones PHP |
| 404 en rutas API | `try_files` / `mod_rewrite`, `APP_URL`, `php-fpm` |
| SPA en blanco tras login | `VITE_API_BASE_URL` incorrecta en el **build** (hay que recompilar), CORS, HTTPS mixto |
| JWT inválido | `JWT_SECRET` distinto entre entornos, re-login tras cambiar secreto |
| Migraciones fallan | versión MySQL, usuario con permisos `ALTER`, backups antes de migrar |

---

## 11. Resumen de comandos (orden típico)

Definí PHP una vez (cPanel: ruta `ea-php82`; local/VPS: `php`):

```bash
export PHP_BIN=/opt/cpanel/ea-php82/root/usr/bin/php   # cPanel
# export PHP_BIN=php                                     # local / VPS
```

```bash
# Backend
cd backend
cp .env.example .env
# Editar .env
composer install --no-dev --optimize-autoloader
$PHP_BIN artisan key:generate
$PHP_BIN artisan jwt:secret
$PHP_BIN artisan migrate --force
$PHP_BIN artisan storage:link
$PHP_BIN artisan config:cache && $PHP_BIN artisan route:cache && $PHP_BIN artisan view:cache

# Frontend (en tu PC; luego commit de compilacion-para-hosting — ver sección cPanel arriba)
cd ../frontend
# Ajustá `frontend/.env.hosting` (VITE_APP_BASE + VITE_API_BASE_URL) antes del build.
npm install && npm run build:hosting
cd ../backend && $PHP_BIN artisan panel:publish
```

Con esto tenés un despliegue coherente con la arquitectura actual del repositorio **moda** (Laravel + Vue/Vite + JWT + MySQL). Para **cada cambio del panel** con Git + cPanel seguí la sección [cPanel: PHP 8.2 (ea-php82), Git, Artisan y panel:publish](#cpanel-php-82-ea-php82-git-artisan-y-panelpublish).
