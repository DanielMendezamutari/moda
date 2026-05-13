import { ofetch } from 'ofetch'
import { apiBaseUrl } from '@/composables/apiBaseUrl'

/**
 * Peticiones al API sin JWT (catálogo público).
 */
export async function $publicApi(path, options = {}) {
  const rel = String(path).replace(/^\//, '')

  return ofetch(rel, {
    baseURL: apiBaseUrl,
    ...options,
    headers: {
      Accept: 'application/json',
      ...options.headers,
    },
  })
}
