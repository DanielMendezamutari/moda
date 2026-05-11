/**
 * Rutas nombradas válidas (unplugin-vue-router): `root`, `second-page`, `roles-permisos`, `usuarios`, `sucursales`, `almacenes`, `categorias`, `proveedores`, `unidades`, `productos`, `productos-registrar`, `clientes`, `ventas`, `ventas-registrar`, `devoluciones`, `compras`, `compras-registrar`, `transportes`, `transportes-registrar`, `conversion`, `conversion-registrar`, `inventario-kardex`, `caja-control`, `caja-sesiones`, `caja-movimientos`, `caja-reportes`, `login`, `$error`.
 * Usa `{ name: 'second-page' }` como placeholder hasta crear cada página en `src/pages/`.
 * ❌ No uses nombres inventados (p. ej. `dashboards-crm`): no hay ruta → pantalla en blanco / error.
 */
export default [
  {
    title: 'Dashboard',
    to: { name: 'root' },
    icon: { icon: 'ri-pie-chart-box-line' },
  },
  { heading: 'Accesos' },
  {
    title: 'Roles y Permisos',
    to: { name: 'roles-permisos' },
    icon: { icon: 'ri-lock-password-line' },
    /** Solo administradores (coincide con el API). Ver `filterNavigation` + `useAuthStore`. */
    roles: ['admin'],
  },
  {
    title: 'Usuarios',
    to: { name: 'usuarios' },
    icon: { icon: 'ri-group-line' },
    roles: ['admin'],
  },
  {
    title: 'Configuraciones',
    icon: { icon: 'ri-tools-line' },
    children: [
      {
        title: 'Sucursales',
        to: { name: 'sucursales' },
        icon: { icon: 'ri-radio-button-line' },
        roles: ['admin'],
      },
      {
        title: 'Almacenes',
        to: { name: 'almacenes' },
        icon: { icon: 'ri-radio-button-line' },
        roles: ['admin'],
      },
      {
        title: 'Categorías',
        to: { name: 'categorias' },
        icon: { icon: 'ri-radio-button-line' },
        roles: ['admin'],
      },
      {
        title: 'Proveedores',
        to: { name: 'proveedores' },
        icon: { icon: 'ri-radio-button-line' },
        roles: ['admin'],
      },
      {
        title: 'Unidades',
        to: { name: 'unidades' },
        icon: { icon: 'ri-radio-button-line' },
        roles: ['admin'],
      },
    ],
  },
  { heading: 'Comercial' },
  {
    title: 'Productos',
    icon: { icon: 'ri-product-hunt-line' },
    children: [
      {
        title: 'Registrar',
        to: { name: 'productos-registrar' },
        icon: { icon: 'ri-radio-button-line' },
        roles: ['admin'],
      },
      {
        title: 'Listado',
        to: { name: 'productos' },
        icon: { icon: 'ri-radio-button-line' },
      },
    ],
  },
  {
    title: 'Clientes',
    icon: { icon: 'ri-p2p-line' },
    to: { name: 'clientes' },
    roles: ['admin', 'cashier'],
  },
  {
    title: 'Ventas',
    icon: { icon: 'ri-money-dollar-box-line' },
    children: [
      {
        title: 'Registrar',
        to: { name: 'ventas-registrar' },
        icon: { icon: 'ri-radio-button-line' },
        posCreate: true,
        roles: ['admin', 'cashier'],
      },
      {
        title: 'Listado',
        to: { name: 'ventas' },
        icon: { icon: 'ri-radio-button-line' },
        posView: true,
        roles: ['admin', 'cashier'],
      },
    ],
  },
  {
    title: 'Caja',
    icon: { icon: 'ri-safe-line' },
    roles: ['admin', 'cashier'],
    children: [
      {
        title: 'Control de cajas',
        to: { name: 'caja-control' },
        icon: { icon: 'ri-settings-3-line' },
        roles: ['admin'],
      },
      {
        title: 'Apertura / turnos',
        to: { name: 'caja-sesiones' },
        icon: { icon: 'ri-door-open-line' },
        roles: ['admin', 'cashier'],
      },
      {
        title: 'Movimientos',
        to: { name: 'caja-movimientos' },
        icon: { icon: 'ri-exchange-line' },
        roles: ['admin', 'cashier'],
      },
      {
        title: 'Reportes',
        to: { name: 'caja-reportes' },
        icon: { icon: 'ri-file-chart-line' },
        roles: ['admin', 'cashier'],
      },
    ],
  },
  {
    title: 'Devoluciones',
    icon: { icon: 'ri-loop-right-line' },
    to: { name: 'devoluciones' },
    roles: ['admin', 'cashier'],
  },
  { heading: 'Almacen' },
  {
    title: 'Compras',
    icon: { icon: 'ri-box-3-line' },
    roles: ['admin'],
    children: [
      {
        title: 'Registrar',
        to: { name: 'compras-registrar' },
        icon: { icon: 'ri-radio-button-line' },
        roles: ['admin'],
      },
      {
        title: 'Listado',
        to: { name: 'compras' },
        icon: { icon: 'ri-radio-button-line' },
        roles: ['admin'],
      },
    ],
  },
  {
    title: 'Transporte',
    icon: { icon: 'ri-truck-line' },
    roles: ['admin'],
    children: [
      {
        title: 'Registrar',
        to: { name: 'transportes-registrar' },
        icon: { icon: 'ri-add-line' },
        roles: ['admin'],
      },
      {
        title: 'Listado',
        to: { name: 'transportes' },
        icon: { icon: 'ri-list-check' },
        roles: ['admin'],
      },
    ],
  },
  {
    title: 'Conversión',
    icon: { icon: 'ri-exchange-funds-line' },
    roles: ['admin'],
    children: [
      {
        title: 'Historial',
        to: { name: 'conversion' },
        icon: { icon: 'ri-history-line' },
        roles: ['admin'],
      },
      {
        title: 'Registrar',
        to: { name: 'conversion-registrar' },
        icon: { icon: 'ri-add-line' },
        roles: ['admin'],
      },
      {
        title: 'Factores unidad',
        to: { name: 'unidades' },
        icon: { icon: 'ri-ruler-line' },
        roles: ['admin'],
      },
    ],
  },
  {
    title: 'Kardex',
    to: { name: 'inventario-kardex' },
    icon: { icon: 'ri-draft-line' },
    /** Visibilidad: `/auth/me` → `can_inventory_kardex_view` o permiso explícito vía `filterNavigation`. */
    kardexView: true,
  },
]
