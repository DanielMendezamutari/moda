/**
 * Filtra ítems del menú vertical según `roles` opcional en cada ítem.
 * - Sin `roles`: visible para todos los autenticados.
 * - Con `roles: ['admin']`: solo si `hasRole` devuelve true para alguno.
 * - `posView` / `posCreate`: flags `/me` (`canPosSale*`) o `roles` del ítem como respaldo; sin una segunda exigencia de rol si ya pasó por POS.
 * - `kardexView`: `/me` → `can_inventory_kardex_view` o `roles` del ítem como respaldo.
 * Respeta encabezados de sección solo si hay al menos un ítem visible después.
 */
export function filterNavigation(items, hasRoleOrOpts) {
  const opts = typeof hasRoleOrOpts === 'function'
    ? { hasRole: hasRoleOrOpts }
    : hasRoleOrOpts

  const hasRole = opts.hasRole
  const canPosSaleView = opts.canPosSaleView ?? (() => false)
  const canPosSaleCreate = opts.canPosSaleCreate ?? (() => false)
  const canInventoryKardexView = opts.canInventoryKardexView ?? (() => false)

  const out = []
  let queuedHeading = null

  for (const item of items) {
    if ('heading' in item) {
      queuedHeading = item

      continue
    }

    if ('children' in item) {
      const children = filterNavigation(item.children, opts)
      if (!children.length)
        continue

      if (queuedHeading) {
        out.push(queuedHeading)
        queuedHeading = null
      }
      out.push({ ...item, children })

      continue
    }

    // POS: flags de `/me` o respaldo por `roles` en el ítem (p. ej. admin/cajero).
    if (item.posView) {
      const ok = canPosSaleView() || (item.roles?.some(r => hasRole(r)) ?? false)
      if (!ok)
        continue
    }
    if (item.posCreate) {
      const ok = canPosSaleCreate() || (item.roles?.some(r => hasRole(r)) ?? false)
      if (!ok)
        continue
    }

    const usesPosFlags = !!(item.posView || item.posCreate || item.kardexView)
    if (!usesPosFlags && item.roles?.length && !item.roles.some(r => hasRole(r)))
      continue

    if (queuedHeading) {
      out.push(queuedHeading)
      queuedHeading = null
    }
    out.push(item)
  }

  return out
}
