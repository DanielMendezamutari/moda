<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'
import { useAuthStore } from '@/stores/auth'
import { $api } from '@/utils/api'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $publicApi } from '@/utils/publicApi'
import { isStoreCustomerOnlySession } from '@/utils/storeCustomerGate'

definePage({
  meta: {
    layout: 'blank',
  },
})

const GUEST_CART_KEY = 'moda_tienda_guest_cart'

const authStore = useAuthStore()
const accessToken = useCookie('accessToken')
const route = useRoute()

const staffLoginQuery = computed(() => {
  const r = route.query.redirect
  if (typeof r !== 'string' || !r.startsWith('/') || r.startsWith('//') || r.includes('://'))
    return {}
  return { redirect: r }
})

const staffLoginPinQuery = computed(() => ({ ...staffLoginQuery.value, prefer: 'pin' }))

const pendingStaffRedirect = computed(() => {
  const r = route.query.redirect
  if (typeof r !== 'string' || !r.startsWith('/') || r.startsWith('//') || r.includes('://'))
    return ''
  if (r === '/' || r.startsWith('/tienda'))
    return ''

  return r
})

const loadingCatalog = ref(false)
const loadingCart = ref(false)
const catalogError = ref('')
const categories = ref([])
const products = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const search = ref('')
const categoryId = ref(null)
const cartDrawer = ref(false)
const accountDialog = ref(false)
const accountTab = ref('login')

const registerForm = reactive({
  name: '',
  surname: '',
  email: '',
  password: '',
  password_confirmation: '',
  phone: '',
})

const loginForm = reactive({
  email: '',
  password: '',
})

const submitLoading = ref(false)
const submitError = ref('')

const serverCart = ref({ lines: [], totals: { items_count: 0, subtotal: 0, lines_count: 0 } })

const guestCart = ref([])

const isLoggedIn = computed(() => Boolean(accessToken.value))

const isStoreClient = computed(() => {
  if (!isLoggedIn.value)
    return false
  if (authStore.user)
    return authStore.hasRole('store_customer') && !authStore.isAdmin && !authStore.hasRole('cashier')

  return isStoreCustomerOnlySession()
})

const displayCartLines = computed(() => {
  if (isStoreClient.value && serverCart.value.lines?.length)
    return serverCart.value.lines
  return guestCart.value.map((row, idx) => ({
    id: `g-${idx}`,
    product_id: row.product_id,
    quantity: row.quantity,
    unit_price: row.unit_price,
    line_total: roundMoney(row.unit_price * row.quantity),
    product: {
      id: row.product_id,
      name: row.name,
      sku: row.sku,
      barcode: row.barcode,
      image_url: row.image_url,
      category: row.category,
    },
  }))
})

const displayTotals = computed(() => {
  if (isStoreClient.value && serverCart.value.totals)
    return serverCart.value.totals
  let sub = 0
  let n = 0
  for (const row of guestCart.value) {
    n += row.quantity
    sub += row.unit_price * row.quantity
  }

  return {
    items_count: n,
    lines_count: guestCart.value.length,
    subtotal: roundMoney(sub),
  }
})

function roundMoney(v) {
  return Math.round(v * 100) / 100
}

function formatMoney(v) {
  const n = Number(v)
  if (!Number.isFinite(n))
    return '—'

  return `${new Intl.NumberFormat('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)} Bs`
}

function readGuestCart() {
  try {
    const raw = localStorage.getItem(GUEST_CART_KEY)
    if (!raw)
      return []
    const parsed = JSON.parse(raw)

    return Array.isArray(parsed) ? parsed : []
  }
  catch {
    return []
  }
}

function saveGuestCart() {
  localStorage.setItem(GUEST_CART_KEY, JSON.stringify(guestCart.value))
}

async function loadCategories() {
  try {
    const res = await $publicApi('public/catalog/categories')
    categories.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    categories.value = []
  }
}

async function loadProducts(page = 1) {
  loadingCatalog.value = true
  catalogError.value = ''
  const p = typeof page === 'number' && page > 0 ? page : 1
  try {
    const q = { page: p, per_page: 24 }
    if (search.value.trim())
      q.search = search.value.trim()
    if (categoryId.value)
      q.category_id = categoryId.value
    const res = await $publicApi('public/catalog/products', { query: q })
    products.value = Array.isArray(res?.data) ? res.data : []
    meta.value = {
      current_page: res.current_page ?? p,
      last_page: res.last_page ?? 1,
      total: res.total ?? products.value.length,
    }
  }
  catch (e) {
    catalogError.value = messageFromApiError(e, 'No se pudo cargar el catálogo.')
    products.value = []
  }
  finally {
    loadingCatalog.value = false
  }
}

async function loadServerCart() {
  if (!isStoreClient.value)
    return
  loadingCart.value = true
  try {
    const res = await $api('/store/cart')
    serverCart.value = {
      lines: Array.isArray(res?.data) ? res.data : [],
      totals: res?.totals ?? { items_count: 0, subtotal: 0, lines_count: 0 },
    }
  }
  catch {
    serverCart.value = { lines: [], totals: { items_count: 0, subtotal: 0, lines_count: 0 } }
  }
  finally {
    loadingCart.value = false
  }
}

function upsertGuestLine(snapshot) {
  const pid = snapshot.id
  const existing = guestCart.value.find(r => r.product_id === pid)
  const max = Math.max(0, Number(snapshot.stock_total) || 0)
  if (max <= 0)
    return false
  const unit = Number(snapshot.final_price ?? snapshot.price) || 0
  const base = {
    product_id: pid,
    name: snapshot.name,
    sku: snapshot.sku,
    barcode: snapshot.barcode,
    image_url: snapshot.image_url,
    category: snapshot.category,
    unit_price: unit,
    stock_total: max,
  }
  if (existing) {
    existing.quantity = Math.min(max, existing.quantity + 1)
  }
  else {
    guestCart.value.push({ ...base, quantity: 1 })
  }
  saveGuestCart()

  return true
}

async function addToCart(snapshot) {
  if (!snapshot?.in_stock) {
    return
  }
  if (isStoreClient.value) {
    loadingCart.value = true
    try {
      await $api('/store/cart/items', {
        method: 'POST',
        body: { product_id: snapshot.id, quantity: 1 },
      })
      await loadServerCart()
    }
    catch (e) {
      catalogError.value = messageFromApiError(e, 'No se pudo agregar al carrito.')
    }
    finally {
      loadingCart.value = false
    }

    return
  }
  upsertGuestLine(snapshot)
}

async function setLineQty(line, qty) {
  const q = Math.max(0, parseInt(String(qty), 10) || 0)
  if (isStoreClient.value) {
    loadingCart.value = true
    try {
      await $api(`/store/cart/items/${line.product_id}`, {
        method: 'PATCH',
        body: { quantity: q },
      })
      await loadServerCart()
    }
    catch (e) {
      catalogError.value = messageFromApiError(e, 'No se pudo actualizar.')
    }
    finally {
      loadingCart.value = false
    }

    return
  }
  const row = guestCart.value.find(r => r.product_id === line.product_id)
  if (!row)
    return
  if (q === 0) {
    guestCart.value = guestCart.value.filter(r => r.product_id !== line.product_id)
  }
  else {
    row.quantity = Math.min(row.stock_total, q)
  }
  saveGuestCart()
}

async function removeLine(line) {
  if (isStoreClient.value) {
    loadingCart.value = true
    try {
      await $api(`/store/cart/items/${line.product_id}`, { method: 'DELETE' })
      await loadServerCart()
    }
    catch (e) {
      catalogError.value = messageFromApiError(e, 'No se pudo eliminar.')
    }
    finally {
      loadingCart.value = false
    }

    return
  }
  guestCart.value = guestCart.value.filter(r => r.product_id !== line.product_id)
  saveGuestCart()
}

async function mergeGuestThenLoad() {
  const raw = readGuestCart()
  if (!raw.length)
    return
  const items = raw.map(r => ({ product_id: r.product_id, quantity: r.quantity }))
  try {
    await $api('/store/cart/merge', { method: 'POST', body: { items } })
    guestCart.value = []
    localStorage.removeItem(GUEST_CART_KEY)
    await loadServerCart()
  }
  catch {
    /* ignorar merge si falla; el usuario sigue con carrito local */
  }
}

async function submitRegister() {
  submitError.value = ''
  submitLoading.value = true
  try {
    const data = await $publicApi('store/register', {
      method: 'POST',
      body: { ...registerForm },
    })
    accessToken.value = data.access_token
    await authStore.fetchMe(true)
    accountDialog.value = false
    await mergeGuestThenLoad()
  }
  catch (e) {
    submitError.value = messageFromApiError(e, 'No se pudo registrar.')
  }
  finally {
    submitLoading.value = false
  }
}

async function submitStoreLogin() {
  submitError.value = ''
  submitLoading.value = true
  try {
    const data = await $publicApi('store/login', {
      method: 'POST',
      body: { ...loginForm },
    })
    accessToken.value = data.access_token
    await authStore.fetchMe(true)
    accountDialog.value = false
    await mergeGuestThenLoad()
  }
  catch (e) {
    submitError.value = messageFromApiError(e, 'No se pudo iniciar sesión.')
  }
  finally {
    submitLoading.value = false
  }
}

async function logoutStore() {
  try {
    await $api('/auth/logout', { method: 'POST' })
  }
  catch {
    /* */
  }
  accessToken.value = null
  authStore.clearUser()
  serverCart.value = { lines: [], totals: { items_count: 0, subtotal: 0, lines_count: 0 } }
}

let searchTimer = null
watch(search, () => {
  if (searchTimer)
    clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    searchTimer = null
    loadProducts(1)
  }, 400)
})

watch(categoryId, () => loadProducts(1))

onMounted(async () => {
  guestCart.value = readGuestCart()
  await Promise.all([loadCategories(), loadProducts(1)])
  if (accessToken.value)
    await authStore.fetchMe(true).catch(() => {})
  if (isStoreClient.value)
    await loadServerCart()
})
</script>

<template>
  <div class="tienda-page">
    <VAppBar
      elevation="0"
      class="tienda-toolbar px-2 px-sm-6"
      height="72"
    >
      <VContainer fluid class="d-flex align-center py-0 ga-2 ga-sm-4 flex-wrap">
        <RouterLink
          :to="{ name: 'tienda' }"
          class="tienda-brand d-flex align-center text-decoration-none"
        >
          <div class="app-logo d-flex align-center gap-2 me-2">
            <VNodeRenderer :nodes="themeConfig.app.logo" />
            <div class="d-flex flex-column">
              <span class="tienda-brand-title text-high-emphasis">{{ themeConfig.app.title }}</span>
              <span class="tienda-brand-tag text-caption text-medium-emphasis">Tienda en línea</span>
            </div>
          </div>
        </RouterLink>

        <VTextField
          v-model="search"
          density="comfortable"
          variant="solo-filled"
          flat
          hide-details
          placeholder="Buscar prendas, SKU…"
          prepend-inner-icon="ri-search-2-line"
          class="tienda-search flex-grow-1"
          bg-color="rgba(var(--v-theme-surface), 0.92)"
        />

        <VSpacer class="d-none d-md-flex" />

        <div class="d-flex align-center flex-wrap ga-2 ms-auto">
          <VBtn
            color="primary"
            variant="flat"
            size="large"
            rounded="lg"
            class="text-none"
            prepend-icon="ri-shopping-bag-3-line"
            @click="cartDrawer = true"
          >
            Carrito
            <VChip
              v-if="displayTotals.items_count > 0"
              size="small"
              class="ms-2"
              color="surface"
              variant="flat"
            >
              {{ displayTotals.items_count }}
            </VChip>
          </VBtn>

          <template v-if="!isLoggedIn || !isStoreClient">
            <VBtn
              variant="tonal"
              color="primary"
              size="large"
              rounded="lg"
              class="text-none"
              prepend-icon="ri-user-heart-line"
              @click="accountDialog = true; accountTab = 'login'"
            >
              Mi cuenta
            </VBtn>
          </template>
          <template v-else>
            <VChip
              variant="tonal"
              color="primary"
              size="large"
              class="font-weight-medium"
            >
              {{ authStore.user?.name }}
            </VChip>
            <VBtn
              variant="text"
              rounded="lg"
              @click="logoutStore"
            >
              Salir
            </VBtn>
          </template>

          <VDivider vertical inset class="d-none d-sm-flex mx-1" />

          <VBtn
            variant="tonal"
            color="primary"
            size="large"
            rounded="lg"
            class="text-none d-none d-sm-inline-flex"
            prepend-icon="ri-mail-line"
            :to="{ name: 'login', query: staffLoginQuery }"
          >
            Personal · correo
          </VBtn>
          <VBtn
            variant="outlined"
            color="primary"
            size="large"
            rounded="lg"
            class="text-none d-none d-sm-inline-flex"
            prepend-icon="ri-keypad-line"
            :to="{ name: 'login', query: staffLoginPinQuery }"
          >
            Personal · PIN
          </VBtn>
          <VBtn
            icon="ri-login-box-line"
            class="d-sm-none"
            variant="tonal"
            color="primary"
            :to="{ name: 'login', query: staffLoginQuery }"
          />
        </div>
      </VContainer>
    </VAppBar>

    <section class="tienda-hero">
      <div class="tienda-hero-inner">
        <p class="tienda-hero-eyebrow text-uppercase text-caption letter-spacing-1">
          Nueva colección
        </p>
        <h1 class="tienda-hero-title text-h3 text-sm-h2 font-weight-bold mb-3">
          Descubrí lo que tenemos para vos
        </h1>
        <p class="tienda-hero-lead text-body-1 text-medium-emphasis mb-0" style="max-width: 36rem;">
          Precios al público, ofertas con descuento aplicado y carrito para clientes registrados.
          El equipo del local usa el acceso <strong>personal</strong> arriba.
        </p>
      </div>
    </section>

    <VMain class="tienda-main">
      <VContainer fluid class="py-8 px-4 px-sm-6">
        <VAlert
          v-if="pendingStaffRedirect"
          type="info"
          variant="tonal"
          border="start"
          rounded="lg"
          class="mb-6"
          prominent
        >
          Para abrir <strong>{{ pendingStaffRedirect }}</strong> usá
          <strong>Personal · correo</strong> o <strong>Personal · PIN</strong>.
        </VAlert>

        <VAlert
          v-if="catalogError"
          type="error"
          variant="tonal"
          rounded="lg"
          class="mb-6"
          closable
          @click:close="catalogError = ''"
        >
          {{ catalogError }}
        </VAlert>

        <div class="d-flex align-center justify-space-between flex-wrap gap-4 mb-6">
          <h2 class="text-h5 font-weight-bold mb-0">
            Catálogo
          </h2>
          <span class="text-caption text-medium-emphasis">{{ meta.total }} artículos</span>
        </div>

        <div class="tienda-chips mb-8">
          <VChip
            class="tienda-chip"
            :variant="categoryId == null ? 'flat' : 'tonal'"
            color="primary"
            size="large"
            @click="categoryId = null"
          >
            Todas
          </VChip>
          <VChip
            v-for="c in categories"
            :key="c.id"
            class="tienda-chip"
            :variant="categoryId === c.id ? 'flat' : 'tonal'"
            color="primary"
            size="large"
            @click="categoryId = c.id"
          >
            {{ c.title }}
          </VChip>
        </div>

        <VProgressLinear
          v-if="loadingCatalog"
          indeterminate
          color="primary"
          height="3"
          rounded
          class="mb-8"
        />

        <VRow v-if="!loadingCatalog" class="tienda-grid">
          <VCol
            v-for="p in products"
            :key="p.id"
            cols="12"
            sm="6"
            md="4"
            lg="3"
          >
            <VCard
              class="tienda-card h-100 d-flex flex-column"
              rounded="xl"
              elevation="0"
            >
              <div class="tienda-card-media">
                <VChip
                  v-if="p.discount_percent > 0"
                  class="tienda-card-badge"
                  color="error"
                  size="small"
                  variant="flat"
                >
                  −{{ Number(p.discount_percent).toFixed(0) }}%
                </VChip>
                <VImg
                  v-if="p.image_url"
                  :src="p.image_url"
                  height="240"
                  cover
                  class="tienda-card-img"
                />
                <div
                  v-else
                  class="tienda-card-placeholder d-flex flex-column align-center justify-center text-medium-emphasis"
                >
                  <VIcon icon="ri-shirt-line" size="48" class="mb-2 opacity-40" />
                  <span class="text-caption">Sin imagen</span>
                </div>
              </div>
              <VCardText class="flex-grow-1 d-flex flex-column pt-4">
                <div class="text-subtitle-1 font-weight-bold mb-1 text-truncate" :title="p.name">
                  {{ p.name }}
                </div>
                <div class="text-caption text-medium-emphasis mb-4 text-truncate">
                  <template v-if="p.category">{{ p.category.title }} · </template>{{ p.sku }}
                </div>
                <div class="mt-auto d-flex align-end justify-space-between gap-2 flex-wrap">
                  <div>
                    <div
                      v-if="p.discount_percent > 0"
                      class="text-decoration-line-through text-caption text-medium-emphasis"
                    >
                      {{ formatMoney(p.price) }}
                    </div>
                    <div class="text-h6 font-weight-bold text-primary">
                      {{ formatMoney(p.final_price) }}
                    </div>
                  </div>
                  <VBtn
                    color="primary"
                    variant="flat"
                    rounded="lg"
                    class="text-none"
                    size="default"
                    :disabled="!p.in_stock"
                    prepend-icon="ri-shopping-cart-2-line"
                    @click="addToCart(p)"
                  >
                    {{ p.in_stock ? 'Agregar' : 'Agotado' }}
                  </VBtn>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <div
          v-if="!loadingCatalog && !products.length"
          class="tienda-empty text-center py-16 rounded-xl"
        >
          <VIcon icon="ri-inbox-line" size="56" class="text-medium-emphasis mb-4" />
          <p class="text-h6 text-medium-emphasis mb-0">
            No hay productos con estos filtros.
          </p>
        </div>

        <div
          v-if="meta.last_page > 1"
          class="d-flex justify-center mt-10"
        >
          <VPagination
            :model-value="meta.current_page"
            :length="meta.last_page"
            rounded="circle"
            active-color="primary"
            @update:model-value="loadProducts"
          />
        </div>
      </VContainer>
    </VMain>

    <VNavigationDrawer
      v-model="cartDrawer"
      location="end"
      temporary
      width="400"
      class="tienda-drawer"
    >
      <div class="pa-5">
        <div class="d-flex align-center justify-space-between mb-6">
          <h3 class="text-h6 font-weight-bold mb-0">
            Tu carrito
          </h3>
          <VBtn icon variant="text" @click="cartDrawer = false">
            <VIcon icon="ri-close-line" />
          </VBtn>
        </div>
        <VProgressLinear
          v-if="loadingCart"
          indeterminate
          color="primary"
          rounded
          class="mb-4"
        />
        <template v-if="displayCartLines.length">
          <div
            v-for="line in displayCartLines"
            :key="line.id"
            class="tienda-drawer-line mb-5 pb-5"
          >
            <div class="d-flex gap-4">
              <div class="tienda-drawer-thumb rounded-lg overflow-hidden flex-shrink-0">
                <VImg
                  v-if="line.product?.image_url"
                  :src="line.product.image_url"
                  width="72"
                  height="72"
                  cover
                />
                <div
                  v-else
                  class="d-flex align-center justify-center bg-grey-lighten-3"
                  style="width: 72px; height: 72px;"
                >
                  <VIcon icon="ri-image-line" class="text-medium-emphasis" />
                </div>
              </div>
              <div class="flex-grow-1 min-w-0">
                <div class="text-body-1 font-weight-medium text-truncate">
                  {{ line.product?.name }}
                </div>
                <div class="text-caption text-medium-emphasis mt-1">
                  {{ formatMoney(line.unit_price) }} c/u
                </div>
                <div class="d-flex align-center gap-2 mt-3">
                  <VTextField
                    :model-value="line.quantity"
                    type="number"
                    min="0"
                    density="compact"
                    variant="outlined"
                    hide-details
                    style="max-width: 96px;"
                    @update:model-value="v => setLineQty(line, v)"
                  />
                  <VBtn
                    icon="ri-delete-bin-line"
                    size="small"
                    variant="text"
                    color="error"
                    @click="removeLine(line)"
                  />
                </div>
                <div class="text-caption font-weight-medium mt-2 text-primary">
                  Subtotal {{ formatMoney(line.line_total) }}
                </div>
              </div>
            </div>
          </div>
          <VDivider class="mb-5" />
          <div class="d-flex justify-space-between align-center text-h6 font-weight-bold mb-4">
            <span>Total</span>
            <span class="text-primary">{{ formatMoney(displayTotals.subtotal) }}</span>
          </div>
          <p class="text-caption text-medium-emphasis mb-0">
            Pagos y entregas se confirman con el local. Este carrito es una lista de interés.
          </p>
        </template>
        <div
          v-else
          class="text-medium-emphasis text-body-1 py-8 text-center"
        >
          Todavía no agregaste productos.
        </div>
      </div>
    </VNavigationDrawer>

    <VDialog
      v-model="accountDialog"
      max-width="500"
      scrollable
      transition="dialog-bottom-transition"
    >
      <VCard rounded="xl">
        <VCardTitle class="d-flex align-center justify-space-between pa-5 pb-2">
          <span class="text-h6 font-weight-bold">Tu cuenta</span>
          <VBtn
            icon="ri-close-line"
            variant="text"
            @click="accountDialog = false"
          />
        </VCardTitle>
        <VTabs v-model="accountTab" grow class="px-2">
          <VTab value="login" class="text-none">
            Ingresar
          </VTab>
          <VTab value="register" class="text-none">
            Crear cuenta
          </VTab>
        </VTabs>
        <VCardText class="pa-5 pt-4">
          <VAlert
            v-if="submitError"
            type="error"
            variant="tonal"
            density="compact"
            rounded="lg"
            class="mb-4"
          >
            {{ submitError }}
          </VAlert>
          <VWindow v-model="accountTab">
            <VWindowItem value="login">
              <VForm @submit.prevent="submitStoreLogin">
                <VTextField
                  v-model="loginForm.email"
                  label="Correo"
                  type="email"
                  class="mb-3"
                  rounded="lg"
                  density="comfortable"
                />
                <VTextField
                  v-model="loginForm.password"
                  label="Contraseña"
                  type="password"
                  class="mb-4"
                  rounded="lg"
                  density="comfortable"
                />
                <VBtn
                  block
                  color="primary"
                  size="large"
                  rounded="lg"
                  type="submit"
                  class="text-none"
                  :loading="submitLoading"
                >
                  Entrar
                </VBtn>
              </VForm>
            </VWindowItem>
            <VWindowItem value="register">
              <VForm @submit.prevent="submitRegister">
                <VTextField
                  v-model="registerForm.name"
                  label="Nombre"
                  class="mb-3"
                  rounded="lg"
                  density="comfortable"
                />
                <VTextField
                  v-model="registerForm.surname"
                  label="Apellidos"
                  class="mb-3"
                  rounded="lg"
                  density="comfortable"
                />
                <VTextField
                  v-model="registerForm.email"
                  label="Correo"
                  type="email"
                  class="mb-3"
                  rounded="lg"
                  density="comfortable"
                />
                <VTextField
                  v-model="registerForm.phone"
                  label="Teléfono (opcional)"
                  class="mb-3"
                  rounded="lg"
                  density="comfortable"
                />
                <VTextField
                  v-model="registerForm.password"
                  label="Contraseña"
                  type="password"
                  class="mb-3"
                  rounded="lg"
                  density="comfortable"
                />
                <VTextField
                  v-model="registerForm.password_confirmation"
                  label="Confirmar contraseña"
                  type="password"
                  class="mb-4"
                  rounded="lg"
                  density="comfortable"
                />
                <VBtn
                  block
                  color="primary"
                  size="large"
                  rounded="lg"
                  type="submit"
                  class="text-none"
                  :loading="submitLoading"
                >
                  Crear cuenta
                </VBtn>
              </VForm>
            </VWindowItem>
          </VWindow>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.tienda-page {
  min-height: 100vh;
  background: linear-gradient(
    180deg,
    rgba(var(--v-theme-surface), 1) 0%,
    rgba(var(--v-theme-on-surface), 0.04) 32%,
    rgb(var(--v-theme-background)) 48%
  );
}

.tienda-toolbar {
  position: sticky;
  top: 0;
  z-index: 1004;
  backdrop-filter: blur(12px);
  background: rgba(var(--v-theme-surface), 0.88) !important;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.tienda-brand-title {
  font-size: 1.05rem;
  font-weight: 700;
  line-height: 1.2;
}

.tienda-brand-tag {
  line-height: 1;
  opacity: 0.85;
}

.tienda-search {
  max-width: 420px;
  min-width: 0;
}

.tienda-hero {
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.12) 0%,
    rgba(var(--v-theme-primary), 0.02) 45%,
    transparent 100%
  );
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.tienda-hero-inner {
  max-width: 1200px;
  margin: 0 auto;
  padding: 2.5rem 1.25rem 2.75rem;
}

@media (min-width: 600px) {
  .tienda-hero-inner {
    padding-inline: 2rem;
  }
}

.tienda-hero-eyebrow {
  opacity: 0.75;
  font-weight: 600;
  letter-spacing: 0.12em;
}

.tienda-hero-title {
  letter-spacing: -0.02em;
  line-height: 1.15;
}

.tienda-main {
  min-height: 40vh;
}

.tienda-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

.tienda-chip {
  font-weight: 600;
}

.tienda-grid {
  margin-inline: -0.25rem;
}

.tienda-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  transition:
    transform 0.22s ease,
    box-shadow 0.22s ease,
    border-color 0.22s ease;
  overflow: hidden;
}

.tienda-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
  border-color: rgba(var(--v-theme-primary), 0.35);
}

.tienda-card-media {
  position: relative;
  background: rgba(var(--v-theme-on-surface), 0.04);
  overflow: hidden;
}

.tienda-card-badge {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 2;
  font-weight: 700;
}

.tienda-card-img {
  transition: transform 0.35s ease;
}

.tienda-card:hover .tienda-card-img {
  transform: scale(1.04);
}

.tienda-card-placeholder {
  min-height: 240px;
}

.tienda-empty {
  border: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-surface), 0.6);
}

.tienda-drawer-line {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.tienda-drawer-line:last-of-type {
  border-bottom: none;
}

.tienda-drawer-thumb {
  border: 1px solid rgba(var(--v-border-color), 0.2);
}
</style>
