import { serialize } from 'cookie-es'
import { ofetch } from 'ofetch'
import { apiBaseUrl } from '@/composables/apiBaseUrl'

const ACCESS_TOKEN_COOKIE = 'accessToken'

/** Misma ruta que `useCookie` por defecto; debe ejecutarse antes de `location.assign`. */
export function clearAccessTokenCookieSync() {
  if (typeof document === 'undefined')
    return

  document.cookie = serialize(ACCESS_TOKEN_COOKIE, '', { path: '/', maxAge: -1 })
}

/** Evita bucles: una sola re-ejecución tras refrescar el JWT. */
const JWT_RETRY = Symbol('jwt-auth-retry')

let refreshPromise = null

function normalizeApiPath(request) {
  if (typeof request === 'string') {
    const s = request.split('?')[0]

    return s.includes('://') ? new URL(s).pathname : s
  }

  if (typeof Request !== 'undefined' && request instanceof Request)
    return new URL(request.url).pathname

  return ''
}

function shouldAttemptRefresh(request) {
  const path = normalizeApiPath(request).replace(/\/$/, '') || '/'

  return !path.endsWith('/auth/login')
    && !path.endsWith('/auth/login-pin')
    && !path.endsWith('/auth/refresh')
    && !path.endsWith('/store/login')
    && !path.endsWith('/store/register')
}

function loginRoutePath() {
  const base = (import.meta.env.BASE_URL || '/').replace(/\/$/, '')

  return `${base}/login`.replace(/\/{2,}/g, '/') || '/login'
}

/**
 * Limpia JWT y envía al login. Si ya estás en login, recarga para limpiar estado SPA (cookie inválida).
 */
function redirectToLogin() {
  if (typeof window === 'undefined')
    return

  clearAccessTokenCookieSync()

  const loginPath = loginRoutePath()
  const here = window.location.pathname.replace(/\/$/, '') || '/'
  const there = loginPath.replace(/\/$/, '') || '/'

  if (here === there) {
    window.location.reload()

    return
  }

  window.location.assign(loginPath.startsWith('/') ? loginPath : `/${loginPath}`)
}

/** API pública para Pinia / guards cuando la sesión ya no es válida. */
export function invalidateSessionAndGoToLogin() {
  redirectToLogin()
}

/**
 * Obtiene un JWT nuevo usando el actual (válido o caducado dentro de JWT_REFRESH_TTL).
 * Varias llamadas en paralelo comparten la misma promesa.
 */
export async function refreshAccessToken() {
  const accessCookie = useCookie(ACCESS_TOKEN_COOKIE)
  const token = accessCookie.value

  if (!token)
    return false

  if (!refreshPromise) {
    const base = String(apiBaseUrl).replace(/\/$/, '')

    refreshPromise = (async () => {
      try {
        const data = await ofetch(`${base}/auth/refresh`, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
          },
        })

        if (data?.access_token)
          accessCookie.value = data.access_token
        else
          throw new Error('Sin access_token en /auth/refresh')

        return true
      }
      catch {
        clearAccessTokenCookieSync()
        accessCookie.value = null

        return false
      }
    })().finally(() => {
      refreshPromise = null
    })
  }

  return refreshPromise
}

const innerFetch = ofetch.create({
  baseURL: apiBaseUrl,
  async onRequest({ options }) {
    const accessToken = useCookie(ACCESS_TOKEN_COOKIE).value
    if (accessToken) {
      options.headers = {
        ...options.headers,
        Authorization: `Bearer ${accessToken}`,
      }
    }
  },
})

/**
 * Cliente HTTP API con reintento tras 401: refresca el JWT y repite la petición una vez.
 */
async function runWithRefresh(request, options, exec) {
  if (options[JWT_RETRY])
    return exec(request, options)

  try {
    return await exec(request, options)
  }
  catch (error) {
    const status = error?.statusCode ?? error?.status
    if (status !== 401)
      throw error
    if (!shouldAttemptRefresh(request))
      throw error

    const refreshed = await refreshAccessToken()
    if (!refreshed) {
      invalidateSessionAndGoToLogin()
      throw error
    }

    return exec(request, { ...options, [JWT_RETRY]: true })
  }
}

export async function $api(request, options = {}) {
  return runWithRefresh(request, options, (req, opts) => innerFetch(req, opts))
}

/**
 * Igual que `$api` pero devuelve la `Response` (para `createFetch` / VueUse).
 */
export async function $apiRaw(request, options = {}) {
  return runWithRefresh(request, options, (req, opts) => innerFetch.raw(req, opts))
}

/**
 * Con `responseType: 'blob'`, ofetch lee el cuerpo una sola vez y lo deja en `response._data`.
 * Llamar otra vez a `response.blob()` falla: "body stream already read".
 */
export function blobFromOfetchRawResponse(response) {
  const d = response?._data

  return d instanceof Blob ? d : null
}
