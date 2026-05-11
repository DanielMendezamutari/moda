/**
 * Mensajes de error de API unificados (403, validación Laravel, etc.).
 */
export function messageFromApiError(e, opts = {}) {
  const {
    forbidden = 'No tienes permiso para esta acción.',
    fallback = 'Ha ocurrido un error.',
  } = opts

  const code = e?.statusCode ?? e?.status
  if (code === 403)
    return forbidden

  const errs = e?.data?.errors
  const flat = errs ? Object.values(errs).flat().filter(Boolean) : []
  if (flat.length)
    return flat.join(' ')

  return e?.data?.message || e?.message || fallback
}
