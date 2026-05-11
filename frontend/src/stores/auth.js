import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

/**
 * Sesión del usuario autenticado (`GET /api/auth/me`).
 * Sirve para ocultar rutas del menú y evitar llamadas que terminan en 403.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const loading = ref(false)

  /** Evita dos peticiones `/auth/me` en paralelo al montar layout + perfil. */
  let fetchPromise = null

  /** Flags que a veces llegan como 1 / "1" / "true" desde proxies o capas intermedias. */
  function apiTruthy(v) {
    if (v === true || v === 1 || v === '1')
      return true
    if (typeof v === 'string' && v.toLowerCase() === 'true')
      return true

    return false
  }

  function normalizeMePayload(raw) {
    if (raw == null || typeof raw !== 'object')
      return null

    const base = 'data' in raw && raw.data != null && typeof raw.data === 'object'
      ? raw.data
      : raw

    const inner = { ...base }

    if (inner.roles != null) {
      const rawRoles = Array.isArray(inner.roles)
        ? inner.roles
        : [inner.roles].flat().filter(Boolean)

      inner.roles = rawRoles
        .map((r) => {
          if (r == null)
            return null
          if (typeof r === 'string' || typeof r === 'number')
            return String(r)
          if (typeof r === 'object' && r.name != null)
            return String(r.name)

          return String(r)
        })
        .filter(Boolean)
    }

    return inner
  }

  function clearUser() {
    user.value = null
  }

  /**
   * Carga o refresca el usuario actual.
   * Si ya hay datos y no se fuerza, evita una segunda petición en la misma vista.
   */
  async function fetchMe(force = false) {
    const accessCookie = useCookie('accessToken')

    if (!accessCookie.value) {
      clearUser()

      return null
    }

    if (!force && user.value)
      return user.value

    if (fetchPromise) {
      if (!force)
        return fetchPromise
      await fetchPromise
    }

    if (!force && user.value)
      return user.value

    loading.value = true

    fetchPromise = (async () => {
      try {
        user.value = normalizeMePayload(await $api('/auth/me'))

        return user.value
      }
      catch (e) {
        const status = e?.statusCode ?? e?.status
        if (status === 401)
          clearUser()

        return null
      }
      finally {
        loading.value = false
      }
    })().finally(() => {
      fetchPromise = null
    })

    return fetchPromise
  }

  function hasRole(roleName) {
    const u = user.value
    const want = String(roleName).toLowerCase()

    if (want === 'admin' && apiTruthy(u?.is_admin))
      return true
    if (want === 'cashier' && apiTruthy(u?.is_cashier))
      return true

    const roles = u?.roles
    if (!Array.isArray(roles))
      return false

    return roles.some(r => String(r).toLowerCase() === want)
  }

  const isAdmin = computed(() => hasRole('admin'))

  /**
   * Permisos de venta (POS): preferir flags del API (`/auth/me`) por encima del array `roles`.
   */
  const canPosSaleView = computed(() => {
    const u = user.value
    if (!u)
      return false
    if (apiTruthy(u.can_pos_sale_view))
      return true
    if (apiTruthy(u.is_admin))
      return true
    if (apiTruthy(u.can_pos_sale_create))
      return true

    return hasRole('admin') || hasRole('cashier')
  })

  const canPosSaleCreate = computed(() => {
    const u = user.value
    if (!u)
      return false
    if (apiTruthy(u.can_pos_sale_create))
      return true
    if (apiTruthy(u.is_admin))
      return true
    if (apiTruthy(u.is_cashier))
      return true

    return hasRole('admin') || hasRole('cashier')
  })

  const canInventoryKardexView = computed(() => {
    const u = user.value
    if (!u)
      return false
    if (apiTruthy(u.can_inventory_kardex_view))
      return true

    return hasRole('admin')
  })

  return {
    user,
    loading,
    fetchMe,
    clearUser,
    hasRole,
    isAdmin,
    canPosSaleView,
    canPosSaleCreate,
    canInventoryKardexView,
  }
})
