/**
 * URL base del backend Laravel.
 *
 * Las rutas del API están bajo el prefijo `/api` (routes/api.php).
 *
 * Configura en la raíz del frontend un archivo `.env` o `.env.development`:
 *
 *   VITE_API_BASE_URL=http://moda.test/api
 *
 * Si usas HTTPS en Apache:
 *
 *   VITE_API_BASE_URL=https://moda.test/api
 *
 * Durante `pnpm dev`, si no defines la variable, el valor por defecto es `/api`
 * y Vite reenvía esas peticiones a `moda.test` (ver `vite.config.js` → server.proxy).
 *
 * Tras cambiar `.env`, reinicia el servidor de Vite.
 */
function normalizeApiBaseUrl(raw) {
  const fallback = '/api'

  if (raw === undefined || raw === null || String(raw).trim() === '')
    return fallback

  return String(raw).replace(/\/$/, '')
}

export const apiBaseUrl = normalizeApiBaseUrl(import.meta.env.VITE_API_BASE_URL)
