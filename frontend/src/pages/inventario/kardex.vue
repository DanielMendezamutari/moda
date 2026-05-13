<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'inventario-kardex',
  },
})

const authStore = useAuthStore()

const canView = computed(() => authStore.canInventoryKardexView)

const loadingProducts = ref(false)
const loadingWarehouses = ref(false)
const loadingKardex = ref(false)
const loadingProductLedger = ref(false)
const errorMsg = ref('')

const products = ref([])
const warehouses = ref([])

const productId = ref(null)
const warehouseId = ref(null)
const dateFrom = ref('')
const dateTo = ref('')

const payload = ref(null)

/** Kardex multi-almacén (código de barras / producto). */
const productLedger = ref(null)
const lastLedgerParams = ref(null)
const ledgerViewTab = ref('valorizado')
const barcodeInput = ref('')

const snackbar = reactive({
  show: false,
  text: '',
})

const productItems = computed(() =>
  (products.value ?? []).map(p => ({
    title: [p.name, p.sku ? `(${p.sku})` : '', p.barcode ? `· ${p.barcode}` : ''].filter(Boolean).join(' '),
    value: p.id,
  })),
)

const warehouseItems = computed(() =>
  (warehouses.value ?? []).map(w => ({
    title: w.branch?.name ? `${w.name} — ${w.branch.name}` : w.name,
    value: w.id,
  })),
)

function formatMoney(v) {
  if (v == null || v === '')
    return '—'
  const n = Number(v)
  if (!Number.isFinite(n))
    return '—'

  return new Intl.NumberFormat('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(n)
}

function formatQty(v) {
  if (v == null || v === '')
    return '—'
  const n = Number(v)
  if (!Number.isFinite(n))
    return '—'

  return new Intl.NumberFormat('es-BO', { maximumFractionDigits: 4 }).format(n)
}

function formatDateShort(iso) {
  if (!iso)
    return '—'
  try {
    const d = new Date(iso)

    return new Intl.DateTimeFormat('es-BO', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d)
  }
  catch {
    return '—'
  }
}

function rowMatchesLedgerView(row, viewId) {
  if (!viewId || viewId === 'valorizado')
    return true
  const t = row.movement_type
  if (viewId === 'ventas')
    return t === 'sale_out'
  if (viewId === 'compras')
    return t === 'purchase_in' || t === 'purchase_revert'
  if (viewId === 'logistica') {
    return t === 'initial'
      || t === 'return_in' || t === 'return_revert'
      || t === 'transport_out' || t === 'transport_in'
      || t === 'transport_out_revert' || t === 'transport_in_revert'
      || t === 'conversion'
  }

  return true
}

const displayLedgerWarehouses = computed(() => {
  const list = productLedger.value?.warehouses ?? []
  const tab = ledgerViewTab.value || 'valorizado'

  return list.map(wh => ({
    ...wh,
    sections: (wh.sections ?? []).map(sec => ({
      ...sec,
      rows: (sec.rows ?? []).filter(r => rowMatchesLedgerView(r, tab)),
    })),
  }))
})

const currentLedgerViewDescription = computed(() => {
  const types = productLedger.value?.view_types ?? []
  const hit = types.find(v => v.id === ledgerViewTab.value)

  return hit?.description ?? ''
})

function buildLedgerQueryParams(params) {
  const q = new URLSearchParams()
  if (params.barcode)
    q.set('barcode', params.barcode)
  if (params.product_id != null)
    q.set('product_id', String(params.product_id))
  if (dateFrom.value)
    q.set('from', dateFrom.value)
  if (dateTo.value)
    q.set('to', dateTo.value)

  return q
}

function ledgerParamsFromForm() {
  const bc = barcodeInput.value.trim()
  if (bc)
    return { barcode: bc }
  if (productId.value)
    return { product_id: productId.value }

  return null
}

async function fetchProducts() {
  loadingProducts.value = true
  try {
    const res = await $api('/products', { query: { per_page: 300 } })
    products.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    errorMsg.value = messageFromApiError(e, 'No se pudieron cargar los productos.')
  }
  finally {
    loadingProducts.value = false
  }
}

async function fetchWarehouses() {
  loadingWarehouses.value = true
  try {
    const res = await $api('/warehouses')
    const raw = res?.data ?? res
    warehouses.value = Array.isArray(raw) ? raw : []
  }
  catch (e) {
    errorMsg.value = messageFromApiError(e, 'No se pudieron cargar los almacenes.')
  }
  finally {
    loadingWarehouses.value = false
  }
}

let kardexTimer = null
let ledgerTimer = null

async function fetchProductLedger(params, { preserveTab = false } = {}) {
  errorMsg.value = ''
  loadingProductLedger.value = true
  try {
    const q = buildLedgerQueryParams(params)
    const res = await $api(`/inventory/kardex/product-ledger?${q.toString()}`)
    const data = res?.data ?? res
    productLedger.value = data
    lastLedgerParams.value = { ...params }
    if (!preserveTab)
      ledgerViewTab.value = 'valorizado'
    const pid = data?.product?.id
    if (pid && productId.value !== pid)
      productId.value = pid
  }
  catch (e) {
    productLedger.value = null
    lastLedgerParams.value = null
    errorMsg.value = messageFromApiError(e, 'No se pudo cargar el kardex del producto.')
    snackbar.text = errorMsg.value
    snackbar.show = true
  }
  finally {
    loadingProductLedger.value = false
  }
}

async function searchProductLedgerFromForm() {
  const p = ledgerParamsFromForm()
  if (!p) {
    snackbar.text = 'Ingresá un código de barras, SKU o elegí un producto y tocá «Buscar».'
    snackbar.show = true

    return
  }
  await fetchProductLedger(p)
}

function clearProductLedger() {
  productLedger.value = null
  lastLedgerParams.value = null
}

function scheduleLedgerRefetch() {
  if (!lastLedgerParams.value)
    return
  if (ledgerTimer)
    clearTimeout(ledgerTimer)
  ledgerTimer = setTimeout(() => {
    ledgerTimer = null
    fetchProductLedger(lastLedgerParams.value, { preserveTab: true })
  }, 300)
}

async function fetchKardex() {
  if (!productId.value || !warehouseId.value) {
    payload.value = null

    return
  }

  errorMsg.value = ''
  loadingKardex.value = true
  try {
    const q = new URLSearchParams()
    q.set('product_id', String(productId.value))
    q.set('warehouse_id', String(warehouseId.value))
    if (dateFrom.value)
      q.set('from', dateFrom.value)
    if (dateTo.value)
      q.set('to', dateTo.value)

    const res = await $api(`/inventory/kardex?${q.toString()}`)
    payload.value = res?.data ?? res
  }
  catch (e) {
    payload.value = null
    errorMsg.value = messageFromApiError(e, 'No se pudo cargar el kardex.')
    snackbar.text = errorMsg.value
    snackbar.show = true
  }
  finally {
    loadingKardex.value = false
  }
}

function scheduleKardex() {
  if (kardexTimer)
    clearTimeout(kardexTimer)
  kardexTimer = setTimeout(() => {
    kardexTimer = null
    fetchKardex()
  }, 300)
}

watch([productId, warehouseId], () => {
  scheduleKardex()
  if (productLedger.value && productId.value !== productLedger.value.product?.id)
    clearProductLedger()
})

watch([dateFrom, dateTo], () => {
  scheduleKardex()
  scheduleLedgerRefetch()
})

onMounted(async () => {
  if (!canView.value)
    return
  await Promise.all([fetchProducts(), fetchWarehouses()])
})
</script>

<template>
  <div class="kardex-page pa-4 pa-md-6">
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h1 class="text-h4 font-weight-bold mb-1">
          Kardex de inventario
        </h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Libro por <strong>producto</strong> y <strong>almacén</strong>: compras entregadas, ventas, devoluciones, traslados y conversiones de unidad.
          Las <strong>existencias</strong> muestran cantidad y <strong>costo promedio ponderado</strong> (valor total del inventario).
          Con el <strong>lector</strong> podés cargar el mismo libro en <strong>todos los almacenes</strong> y filtrar por tipo de movimiento.
        </p>
      </div>
    </div>

    <VAlert
      v-if="!canView"
      type="warning"
      variant="tonal"
      border="start"
    >
      No tiene permiso para ver el kardex.
    </VAlert>

    <template v-else>
      <VAlert
        type="info"
        variant="tonal"
        border="start"
        class="mb-4"
      >
        <div class="text-body-2">
          <strong>Compras en el kardex:</strong> aquí solo aparece el ingreso cuando en
          <RouterLink :to="{ name: 'compras' }" class="text-primary font-weight-medium">
            Compras → Listado
          </RouterLink>
          cada línea está en <strong>Entregado</strong> (así se suma al stock del almacén).
          Una orden recién creada en «Solicitud» o «Revisión» no genera fila todavía.
        </div>
        <div class="text-body-2 mt-2 text-medium-emphasis">
          Si marcaste entregas antes de tener la tabla de kardex o hubo un error al guardar,
          en el servidor podés ejecutar:
          <code class="d-block mt-1 user-select-all">php artisan inventory:backfill-purchase-kardex</code>
          (opcional: <code>--dry-run</code> para ver cuántas líneas faltan sin insertar).
        </div>
      </VAlert>

      <VCard class="mb-6" elevation="1" border>
        <VCardTitle class="text-subtitle-1 py-3">
          Inventario con lector (todos los almacenes)
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            Escaneá el código de barras o escribí el SKU, confirmá con <strong>Buscar</strong> y revisá el libro en cada sucursal/almacén.
            Las pestañas filtran el mismo kardex: <em>valorizado</em> (completo), <em>ventas</em>, <em>compras</em> y <em>traslados, devoluciones y conversiones</em>.
            Las columnas de existencias siguen siendo el valor registrado en cada movimiento (no se recalculan al filtrar filas).
          </p>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField
                v-model="barcodeInput"
                label="Código de barras o SKU"
                density="comfortable"
                hide-details="auto"
                autocomplete="off"
                @keydown.enter.prevent="searchProductLedgerFromForm"
              />
            </VCol>
            <VCol cols="12" md="6" class="d-flex flex-wrap align-center gap-2">
              <VBtn
                color="primary"
                :loading="loadingProductLedger"
                @click="searchProductLedgerFromForm"
              >
                Buscar kardex global
              </VBtn>
              <VBtn
                v-if="productId"
                variant="tonal"
                :loading="loadingProductLedger"
                @click="fetchProductLedger({ product_id: productId })"
              >
                Por producto elegido
              </VBtn>
              <VBtn
                v-if="productLedger"
                variant="text"
                @click="clearProductLedger"
              >
                Cerrar vista
              </VBtn>
            </VCol>
          </VRow>

          <template v-if="productLedger">
            <VTabs
              v-model="ledgerViewTab"
              class="mt-4"
              density="comfortable"
              color="primary"
            >
              <VTab
                v-for="vt in (productLedger.view_types ?? [])"
                :key="vt.id"
                :value="vt.id"
                class="text-none"
              >
                {{ vt.title }}
              </VTab>
            </VTabs>
            <p
              v-if="currentLedgerViewDescription"
              class="text-caption text-medium-emphasis mt-2 mb-0"
            >
              {{ currentLedgerViewDescription }}
            </p>

            <VProgressLinear
              v-if="loadingProductLedger"
              indeterminate
              color="primary"
              class="mt-4 rounded"
            />

            <VAlert
              v-if="!(displayLedgerWarehouses?.length)"
              type="warning"
              variant="tonal"
              class="mt-4"
              density="compact"
            >
              Este producto no figura en ningún almacén con stock o movimientos en el período.
            </VAlert>

            <template
              v-for="wh in displayLedgerWarehouses"
              :key="wh.warehouse_id"
            >
              <VCard
                class="mt-4 kardex-sheet"
                elevation="1"
              >
                <VCardTitle class="text-subtitle-2 py-2 bg-grey-lighten-4">
                  {{ wh.warehouse?.name ?? 'Almacén' }}
                  <span
                    v-if="wh.warehouse?.branch?.name"
                    class="text-body-2 text-medium-emphasis"
                  >
                    — {{ wh.warehouse.branch.name }}
                  </span>
                </VCardTitle>
                <VCardText v-if="wh.summary" class="pb-0">
                  <VRow dense>
                    <VCol cols="12" sm="4">
                      <div class="text-caption text-medium-emphasis">
                        Stock actual
                      </div>
                      <div class="text-h6 font-weight-medium">
                        {{ formatQty(wh.summary.stock_quantity) }}
                      </div>
                    </VCol>
                    <VCol cols="12" sm="4">
                      <div class="text-caption text-medium-emphasis">
                        Costo prom. ponderado
                      </div>
                      <div class="text-h6 font-weight-medium">
                        {{ formatMoney(wh.summary.weighted_avg_cost) }}
                      </div>
                    </VCol>
                    <VCol cols="12" sm="4">
                      <div class="text-caption text-medium-emphasis">
                        Valor inventario
                      </div>
                      <div class="text-h6 font-weight-medium">
                        {{ formatMoney(wh.summary.inventory_total_value) }}
                      </div>
                    </VCol>
                  </VRow>
                </VCardText>

                <VCard
                  v-for="(section, si) in (wh.sections ?? [])"
                  :key="`${wh.warehouse_id}-${si}`"
                  class="ma-4 mt-2"
                  elevation="0"
                  variant="outlined"
                >
                  <VCardTitle class="text-subtitle-2 py-2">
                    {{ section.unit?.name ?? 'UNIDAD' }}
                    <span
                      v-if="productLedger?.product"
                      class="text-body-2 text-medium-emphasis d-block mt-1"
                    >
                      {{ productLedger.product.name }}
                      <template v-if="productLedger.product.sku"> · {{ productLedger.product.sku }}</template>
                      <template v-if="productLedger.product.barcode"> · {{ productLedger.product.barcode }}</template>
                    </span>
                  </VCardTitle>

                  <div class="kardex-table-wrap">
                    <table class="kardex-table" cellspacing="0">
                      <thead>
                        <tr class="kardex-head-top">
                          <th rowspan="2" class="k-fixed">
                            Fecha
                          </th>
                          <th rowspan="2" class="k-fixed k-det">
                            Detalle
                          </th>
                          <th colspan="3" class="k-in">
                            Entrada
                          </th>
                          <th colspan="3" class="k-out">
                            Salida
                          </th>
                          <th colspan="3" class="k-bal">
                            Existencias
                          </th>
                        </tr>
                        <tr class="kardex-head-sub">
                          <th class="k-in">
                            Cantidad
                          </th>
                          <th class="k-in">
                            V/Unitario
                          </th>
                          <th class="k-in">
                            V/Total
                          </th>
                          <th class="k-out">
                            Cantidad
                          </th>
                          <th class="k-out">
                            V/Unitario
                          </th>
                          <th class="k-out">
                            V/Total
                          </th>
                          <th class="k-bal">
                            Cantidad
                          </th>
                          <th class="k-bal" title="Costo unitario promedio ponderado">
                            V/Unit. (prom.)
                          </th>
                          <th class="k-bal" title="Cantidad × costo promedio ponderado">
                            V/Total
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="(row, ri) in (section.rows ?? [])"
                          :key="row.id ?? `gl-${wh.warehouse_id}-${si}-${ri}`"
                        >
                          <td class="k-fixed">
                            {{ formatDateShort(row.occurred_at) }}
                          </td>
                          <td class="k-fixed k-det">
                            {{ row.detail_label ?? '—' }}
                          </td>
                          <td class="k-in num">
                            {{ formatQty(row.in_qty) }}
                          </td>
                          <td class="k-in num">
                            {{ formatMoney(row.in_unit_value) }}
                          </td>
                          <td class="k-in num">
                            {{ formatMoney(row.in_total_value) }}
                          </td>
                          <td class="k-out num">
                            {{ formatQty(row.out_qty) }}
                          </td>
                          <td class="k-out num">
                            {{ formatMoney(row.out_unit_value) }}
                          </td>
                          <td class="k-out num">
                            {{ formatMoney(row.out_total_value) }}
                          </td>
                          <td class="k-bal num">
                            {{ formatQty(row.balance_quantity) }}
                          </td>
                          <td class="k-bal num">
                            {{ formatMoney(row.balance_avg_cost) }}
                          </td>
                          <td class="k-bal num">
                            {{ formatMoney(row.balance_total_value) }}
                          </td>
                        </tr>
                        <tr v-if="!(section.rows ?? []).length">
                          <td colspan="11" class="text-center text-medium-emphasis pa-6">
                            Sin movimientos en esta vista.
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </VCard>
              </VCard>
            </template>
          </template>
        </VCardText>
      </VCard>

      <VCard class="mb-6" elevation="1">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4">
              <VAutocomplete
                v-model="productId"
                :items="productItems"
                item-title="title"
                item-value="value"
                label="Producto"
                clearable
                hide-details="auto"
                :loading="loadingProducts"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VAutocomplete
                v-model="warehouseId"
                :items="warehouseItems"
                item-title="title"
                item-value="value"
                label="Almacén"
                clearable
                hide-details="auto"
                :loading="loadingWarehouses"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="2">
              <VTextField
                v-model="dateFrom"
                type="date"
                label="Desde"
                hide-details="auto"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="2">
              <VTextField
                v-model="dateTo"
                type="date"
                label="Hasta"
                hide-details="auto"
                density="comfortable"
              />
            </VCol>
          </VRow>
          <VAlert
            v-if="errorMsg"
            type="error"
            variant="tonal"
            class="mt-4"
            density="compact"
          >
            {{ errorMsg }}
          </VAlert>
        </VCardText>
      </VCard>

      <VCard v-if="!productId || !warehouseId" variant="outlined">
        <VCardText class="text-medium-emphasis">
          Elija producto y almacén para ver el libro kardex.
        </VCardText>
      </VCard>

      <template v-else>
        <VProgressLinear
          v-if="loadingKardex"
          indeterminate
          color="primary"
          class="mb-2 rounded"
        />

        <VCard
          v-if="payload?.summary && !loadingKardex"
          class="mb-4"
          variant="outlined"
        >
          <VCardTitle class="text-subtitle-2 py-2">
            Resumen del producto en este almacén
          </VCardTitle>
          <VCardText>
            <VRow dense>
              <VCol cols="12" sm="4">
                <div class="text-caption text-medium-emphasis">
                  Stock actual
                </div>
                <div class="text-h6 font-weight-medium">
                  {{ formatQty(payload.summary.stock_quantity) }}
                </div>
              </VCol>
              <VCol cols="12" sm="4">
                <div class="text-caption text-medium-emphasis">
                  Costo prom. ponderado (V/Unitario existencias)
                </div>
                <div class="text-h6 font-weight-medium">
                  {{ formatMoney(payload.summary.weighted_avg_cost) }}
                </div>
              </VCol>
              <VCol cols="12" sm="4">
                <div class="text-caption text-medium-emphasis">
                  Valor inventario (V/Total existencias)
                </div>
                <div class="text-h6 font-weight-medium">
                  {{ formatMoney(payload.summary.inventory_total_value) }}
                </div>
              </VCol>
            </VRow>
            <p class="text-caption text-medium-emphasis mb-0 mt-2">
              Método: {{ payload.summary.valuation_method === 'weighted_average' ? 'Promedio ponderado' : payload.summary.valuation_method }}.
            </p>
          </VCardText>
        </VCard>

        <VCard
          v-for="(section, si) in (payload?.sections ?? [])"
          :key="si"
          class="kardex-sheet mb-6"
          elevation="1"
        >
          <VCardTitle class="text-subtitle-1 py-3 bg-grey-lighten-4">
            {{ section.unit?.name ?? 'UNIDAD' }}
            <span
              v-if="payload?.product"
              class="text-body-2 text-medium-emphasis d-block mt-1"
            >
              {{ payload.product.name }}
              <template v-if="payload.product.sku"> · {{ payload.product.sku }}</template>
            </span>
          </VCardTitle>

          <div class="kardex-table-wrap">
            <table class="kardex-table" cellspacing="0">
              <thead>
                <tr class="kardex-head-top">
                  <th rowspan="2" class="k-fixed">Fecha</th>
                  <th rowspan="2" class="k-fixed k-det">Detalle</th>
                  <th colspan="3" class="k-in">
                    Entrada
                  </th>
                  <th colspan="3" class="k-out">
                    Salida
                  </th>
                  <th colspan="3" class="k-bal">
                    Existencias
                  </th>
                </tr>
                <tr class="kardex-head-sub">
                  <th class="k-in">
                    Cantidad
                  </th>
                  <th class="k-in">
                    V/Unitario
                  </th>
                  <th class="k-in">
                    V/Total
                  </th>
                  <th class="k-out">
                    Cantidad
                  </th>
                  <th class="k-out">
                    V/Unitario
                  </th>
                  <th class="k-out">
                    V/Total
                  </th>
                  <th class="k-bal">
                    Cantidad
                  </th>
                  <th class="k-bal" title="Costo unitario promedio ponderado">
                    V/Unit. (prom.)
                  </th>
                  <th class="k-bal" title="Cantidad × costo promedio ponderado">
                    V/Total
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, ri) in (section.rows ?? [])" :key="row.id ?? `i-${ri}`">
                  <td class="k-fixed">
                    {{ formatDateShort(row.occurred_at) }}
                  </td>
                  <td class="k-fixed k-det">
                    {{ row.detail_label ?? '—' }}
                  </td>
                  <td class="k-in num">
                    {{ formatQty(row.in_qty) }}
                  </td>
                  <td class="k-in num">
                    {{ formatMoney(row.in_unit_value) }}
                  </td>
                  <td class="k-in num">
                    {{ formatMoney(row.in_total_value) }}
                  </td>
                  <td class="k-out num">
                    {{ formatQty(row.out_qty) }}
                  </td>
                  <td class="k-out num">
                    {{ formatMoney(row.out_unit_value) }}
                  </td>
                  <td class="k-out num">
                    {{ formatMoney(row.out_total_value) }}
                  </td>
                  <td class="k-bal num">
                    {{ formatQty(row.balance_quantity) }}
                  </td>
                  <td class="k-bal num">
                    {{ formatMoney(row.balance_avg_cost) }}
                  </td>
                  <td class="k-bal num">
                    {{ formatMoney(row.balance_total_value) }}
                  </td>
                </tr>
                <tr v-if="!(section.rows ?? []).length">
                  <td colspan="11" class="text-center text-medium-emphasis pa-6">
                    Sin movimientos registrados.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </VCard>
      </template>
    </template>

    <VSnackbar v-model="snackbar.show" color="error" location="top">
      {{ snackbar.text }}
    </VSnackbar>
  </div>
</template>

<style scoped>
.kardex-table-wrap {
  overflow-x: auto;
}

.kardex-table {
  width: 100%;
  min-width: 880px;
  border-collapse: collapse;
  font-size: 0.8125rem;
}

.kardex-table th,
.kardex-table td {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding: 6px 8px;
  vertical-align: middle;
}

.kardex-head-top th {
  text-align: center;
  font-weight: 700;
  font-size: 0.75rem;
  letter-spacing: 0.02em;
}

.kardex-head-sub th {
  text-align: center;
  font-weight: 600;
  font-size: 0.7rem;
}

.k-in {
  background: #e8f5e9;
}

.k-out {
  background: #fce4ec;
}

.k-bal {
  background: #fff9c4;
}

.k-fixed {
  background: rgb(var(--v-theme-surface));
  white-space: nowrap;
}

.k-det {
  min-width: 140px;
  max-width: 220px;
  white-space: normal;
}

.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

tbody tr:nth-child(even) .k-in,
tbody tr:nth-child(even) .k-out,
tbody tr:nth-child(even) .k-bal {
  filter: brightness(0.97);
}
</style>
