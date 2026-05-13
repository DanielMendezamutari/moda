import { parse } from 'cookie-es'
import { destr } from 'destr'
import { jwtDecode } from 'jwt-decode'

/**
 * Roles embebidos en el JWT (`User::getJWTCustomClaims`).
 * Sirve en el guard del router sin llamar a `/auth/me`.
 */
export function rolesFromAccessTokenCookie() {
  if (typeof document === 'undefined')
    return []

  const raw = parse(document.cookie).accessToken
  if (raw == null || raw === '')
    return []

  try {
    const val = destr(decodeURIComponent(raw))
    const s = val == null ? '' : typeof val === 'string' ? val : String(val)
    if (!s)
      return []
    const payload = jwtDecode(s)

    return Array.isArray(payload.roles) ? payload.roles.map(String) : []
  }
  catch {
    return []
  }
}

/** Cliente solo de tienda en línea (sin acceso al panel POS). */
export function isStoreCustomerOnlySession() {
  const roles = rolesFromAccessTokenCookie().map(r => String(r).toLowerCase())
  if (!roles.includes('store_customer'))
    return false
  if (roles.includes('admin') || roles.includes('cashier'))
    return false

  return true
}
