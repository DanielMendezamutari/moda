# Manual de despliegue en hosting (Moda POS)

Este proyecto tiene **dos partes**:

| Parte | Tecnología | Rol |
|--------|-------------|-----|
| `backend/` | Laravel 12, PHP 8.2+, JWT | API REST (`/api/...`) |
| `frontend/` | Vue 3 + Vite | SPA (panel web) |

En **producción** el frontend se compila a archivos estáticos (`dist/`). El navegador llama al backend por HTTPS usando la URL configurada en `VITE_API_BASE_URL`.

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
npm ci
npm run build
```

El resultado queda en `frontend/dist/`. Esa carpeta es la que servís como sitio estático.

### 4.3 Despliegue en subcarpeta (`https://dominio.com/moda/`)

Si el SPA no está en la raíz del dominio, en `vite.config.js` hay que definir `base: '/moda/'` (u otra ruta), volver a compilar, y configurar el servidor para que todas las rutas del SPA redirijan a `index.html` (fallback SPA).

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

En el servidor:

```bash
cd /var/www/moda/backend
git pull   # o subida de archivos
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Frontend:

```bash
cd /var/www/moda/frontend
git pull
npm ci
npm run build
# Recargar Nginx o copiar dist/ al destino final
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

```bash
# Backend
cd backend
cp .env.example .env
# Editar .env
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan jwt:secret
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache

# Frontend
cd ../frontend
echo 'VITE_API_BASE_URL=https://TU-API/api' > .env.production
npm ci && npm run build
# Servir frontend/dist y apuntar API a backend/public
```

Con esto tenés un despliegue coherente con la arquitectura actual del repositorio **moda** (Laravel + Vue/Vite + JWT + MySQL).
