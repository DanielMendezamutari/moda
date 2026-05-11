/**
 * Montos: siempre 2 decimales (es-BO).
 */
export function formatBsAmount(value) {
  if (value === null || value === undefined || value === '')
    return '—'

  const n = Number(value)
  if (!Number.isFinite(n))
    return '—'

  return new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n)
}

/**
 * Cantidades, equivalentes de stock, factores: como máximo 2 decimales (sin forzar ,00 en enteros).
 */
export function formatQuantityMax2(value) {
  if (value === null || value === undefined || value === '')
    return '—'

  const n = Number(value)
  if (!Number.isFinite(n))
    return '—'

  return new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(n)
}
