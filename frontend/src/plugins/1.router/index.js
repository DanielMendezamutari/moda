import { setupLayouts } from 'virtual:generated-layouts'
import { parse } from 'cookie-es'
import { destr } from 'destr'
import { createRouter, createWebHistory } from 'vue-router/auto'

/** Rutas que no requieren JWT (nombres de `unplugin-vue-router`, ver `typed-router.d.ts`). */
const PUBLIC_ROUTE_NAMES = new Set(['login', '$error'])

function readAccessTokenFromCookie() {
  if (typeof document === 'undefined')
    return null

  const raw = parse(document.cookie).accessToken
  if (raw == null || raw === '')
    return null

  try {
    const val = destr(decodeURIComponent(raw))
    const s = val == null ? '' : typeof val === 'string' ? val : String(val)

    return s || null
  }
  catch {
    return null
  }
}

function recursiveLayouts(route) {
  if (route.children) {
    for (let i = 0; i < route.children.length; i++)
      route.children[i] = recursiveLayouts(route.children[i])
    
    return route
  }
  
  return setupLayouts([route])[0]
}

/** Carpeta `pages/roles-permisos/` → nombre `roles-permisos`; URL legible con “y”. */
const ROLES_PERMISOS_ROUTE_NAME = 'roles-permisos'
const ROLES_PERMISOS_PATH = '/roles-y-permisos'

function rewriteRolesPermisosPath(routes) {
  for (const route of routes) {
    if (route.name === ROLES_PERMISOS_ROUTE_NAME || route.path === '/roles-permisos')
      route.path = ROLES_PERMISOS_PATH

    if (route.children?.length)
      rewriteRolesPermisosPath(route.children)
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to) {
    if (to.hash)
      return { el: to.hash, behavior: 'smooth', top: 60 }
    
    return { top: 0 }
  },
  extendRoutes: pages => {
    const routes = [...pages].map(route => recursiveLayouts(route))

    rewriteRolesPermisosPath(routes)

    routes.push({
      path: '/roles-permisos',
      redirect: ROLES_PERMISOS_PATH,
    })

    return routes
  },
})

router.beforeEach(to => {
  const token = readAccessTokenFromCookie()
  const name = String(to.name ?? '')
  const isPublic = PUBLIC_ROUTE_NAMES.has(name)

  if (!token && !isPublic) {
    return {
      name: 'login',
      ...(to.fullPath !== '/login' ? { query: { redirect: to.fullPath } } : {}),
    }
  }

  if (token && name === 'login') {
    const r = to.query.redirect
    if (typeof r === 'string' && r.startsWith('/') && !r.startsWith('//') && !r.includes('://'))
      return r

    return { path: '/' }
  }

  return true
})

export { router }
export default function (app) {
  app.use(router)
}
