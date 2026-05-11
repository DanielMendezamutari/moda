/**
 * Etiquetas en español para permisos del guard `api` (Spatie).
 * Si falta entrada, se muestra el nombre técnico.
 */
const LABELS = {
  'pos.sale.create': 'Ventas (POS): registrar ventas',
  'pos.sale.view': 'Ventas (POS): ver listado y detalle',
  'products.manage': 'Catálogo: gestionar productos',
  'reports.view': 'Reportes: ver',
  'clients.view': 'Clientes: ver',
  'clients.manage': 'Clientes: crear y editar',
}

export function formatPermissionLabel(technicalName) {
  const key = String(technicalName ?? '').trim()

  return LABELS[key] ?? key
}
