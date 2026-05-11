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
const errorMsg = ref('')

const products = ref([])
const warehouses = ref([])

const productId = ref(null)
const warehouseId = ref(null)
const dateFrom = ref('')
const dateTo = ref('')

const payload = ref(null)

const snackbar = reactive({
  show: false,
  text: '',
})

const productItems = computed(() =>
  (products.value ?? []).map(p => ({
    title: p.sku ? `${p.name} (${p.sku})` : p.name,
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

async function fetchProducts() {
  loadingProducts.value = true
  try {
    const res = await $api('/products', { query: { per_page: 150 } })
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

watch([productId, warehouseId], () => scheduleKardex())
watch([dateFrom, dateTo], () => scheduleKardex())

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
