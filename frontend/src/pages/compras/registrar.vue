<script setup>
/* eslint-disable camelcase -- payload API Laravel (snake_case). */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'
import { formatBsAmount, formatQuantityMax2 } from '@/utils/formatNumbers'

const router = useRouter()

definePage({
  meta: {
    navActiveLink: 'compras-registrar',
  },
})

const authStore = useAuthStore()

const canManage = computed(() => authStore.isAdmin)

const branches = ref([])
const users = ref([])
const warehouses = ref([])
const suppliers = ref([])
const units = ref([])

const branchId = ref(null)
const requesterId = ref(null)
const warehouseId = ref(null)
const supplierId = ref(null)
const dateEmision = ref(new Date().toISOString().slice(0, 10))
const stateHeader = ref('solicitud')
const typeComprobant = ref('')
const nComprobant = ref('')
const reference = ref('')
const notes = ref('')
const igv = ref('0')

const STATE_HEADERS = [
  { value: 'solicitud', title: 'Solicitud' },
  { value: 'revision', title: 'Revisión' },
]

/** Líneas ya agregadas al detalle */
const detailLines = ref([])

/** Borrador para una nueva línea (producto + unidad + cantidad + precio) */
const draft = reactive({
  product_id: null,
  product_label: '',
  unit_id: null,
  quantity: '1',
  price_unit: '',
  line_note: '',
})

const productSearch = ref('')
const productSearchLoading = ref(false)
const productHits = ref([])

const submitError = ref('')
const submitting = ref(false)
const addingLine = ref(false)

const snackbar = reactive({
  show: false,
  text: '',
})

const warehousesFiltered = computed(() => {
  const bid = branchId.value
  if (bid == null || bid === '')
    return warehouses.value

  return warehouses.value.filter(w => Number(w.branch_id) === Number(bid))
})

const branchItems = computed(() =>
  branches.value.map(b => ({
    title: b.code ? `${b.name} (${b.code})` : b.name,
    value: b.id,
  })),
)

const userItems = computed(() =>
  users.value.map(u => ({
    title: u.branch?.name ? `${u.name} — ${u.branch.name}` : u.name,
    value: u.id,
  })),
)

const unitItems = computed(() =>
  units.value.map(u => ({ title: u.name, value: u.id })),
)

const productSelectItems = computed(() =>
  productHits.value.map(p => ({
    title: p.sku ? `${p.name} (${p.sku})` : p.name,
    value: p.id,
    raw: p,
  })),
)

const formatBs = formatBsAmount
const formatStockQty = formatQuantityMax2

/** Precio de venta de referencia: pivot del almacén si tiene `sale_price`, si no `price` del producto. */
function warehouseSalePrice(productRaw, wid) {
  if (!productRaw || wid == null)
    return ''
  const lines = productRaw.warehouse_lines
  if (Array.isArray(lines)) {
    const hit = lines.find(l => Number(l.warehouse_id) === Number(wid))
    if (hit?.sale_price != null && String(hit.sale_price).trim() !== '')
      return String(hit.sale_price)
  }
  if (productRaw.price != null && String(productRaw.price).trim() !== '')
    return String(productRaw.price)

  return ''
}

/** Unidad de inventario del producto en el almacén (pivot `product_warehouses.unit_id`). */
function warehouseStockUnitId(productRaw, wid) {
  if (!productRaw || wid == null)
    return null
  const lines = productRaw.warehouse_lines
  if (!Array.isArray(lines))
    return null
  const hit = lines.find(l => Number(l.warehouse_id) === Number(wid))
  if (hit?.unit_id == null || String(hit.unit_id).trim() === '')
    return null

  return Number(hit.unit_id)
}

function roundMoney(value) {
  const n = Number(value)

  return Number.isFinite(n) ? Math.round(n * 100) / 100 : null
}

function lineTotal(line) {
  const q = Number(String(line.quantity || '').replace(',', '.')) || 0
  const p = Number(String(line.price_unit || '').replace(',', '.')) || 0

  return Math.round(q * p * 100) / 100
}

const importeComputed = computed(() =>
  Math.round(detailLines.value.reduce((a, l) => a + lineTotal(l), 0) * 100) / 100,
)

const igvNum = computed(() => {
  const v = Number(String(igv.value || '').replace(',', '.'))

  return Number.isFinite(v) ? Math.round(v * 100) / 100 : 0
})

const totalComputed = computed(() =>
  Math.round((importeComputed.value + igvNum.value) * 100) / 100,
)

watch(branchId, () => {
  const allowed = new Set(warehousesFiltered.value.map(w => w.id))
  if (warehouseId.value != null && !allowed.has(warehouseId.value))
    warehouseId.value = null
})

watch(() => draft.product_id, (id) => {
  if (!id) {
    draft.product_label = ''

    return
  }
  const it = productSelectItems.value.find(x => Number(x.value) === Number(id))
  const p = it?.raw
  draft.product_label = p?.name || `Producto #${id}`
})

let searchTimer = null

async function runProductSearch() {
  const q = productSearch.value.trim()
  if (q.length < 2) {
    productHits.value = []

    return
  }
  productSearchLoading.value = true
  try {
    const res = await $api(`/products?search=${encodeURIComponent(q)}&per_page=30`)
    productHits.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    productHits.value = []
  }
  finally {
    productSearchLoading.value = false
  }
}

function onProductSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(runProductSearch, 320)
}

async function addLineToDetail() {
  submitError.value = ''

  if (!warehouseId.value) {
    submitError.value = 'Elegí el almacén de ingreso.'

    return
  }
  if (!draft.product_id) {
    submitError.value = 'Elegí un producto (buscá y seleccioná en el desplegable).'

    return
  }
  if (!draft.unit_id) {
    submitError.value = 'Elegí la unidad de medida.'

    return
  }

  const q = Number(String(draft.quantity || '').replace(',', '.'))
  const pu = Number(String(draft.price_unit || '').replace(',', '.'))
  if (!Number.isFinite(q) || q <= 0 || !Number.isFinite(pu) || pu < 0) {
    submitError.value = 'Indicá cantidad y precio unitario válidos.'

    return
  }
  if (Math.abs(q - Math.round(q)) > 1e-6) {
    submitError.value = 'La cantidad debe ser un número entero (unidades).'

    return
  }

  const pid = Number(draft.product_id)
  const it = productSelectItems.value.find(x => Number(x.value) === Number(pid))
  const raw = it?.raw
  const saleRef = warehouseSalePrice(raw, warehouseId.value)
  const catalogSale = saleRef !== '' ? saleRef : (raw?.price != null ? String(raw.price) : '')

  const stockUnitId = warehouseStockUnitId(raw, warehouseId.value)
  const stockUnitName = stockUnitId != null
    ? (units.value.find(u => Number(u.id) === Number(stockUnitId))?.name || 'unidad de stock')
    : ''

  let costDerivedSale = ''
  /** Cuántas unidades de inventario entran en 1 unidad de compra (1 docena → 12, 1 caja → N…). */
  let stockUnitsPerPurchaseUnit = ''
  /** Total en unidad de inventario para esta línea (p. ej. 2 docenas → 24 unidades). */
  let stockQtyEquivalent = ''
  let sameUnitAsStock = false

  addingLine.value = true
  try {
    const qInt = Math.round(q)

    if (stockUnitId == null) {
      snackbar.text = 'Este producto no tiene unidad de stock en este almacén; no se puede prorratear el precio de venta por unidad ni mostrar el equivalente en inventario.'
      snackbar.show = true
    }
    else if (Number(draft.unit_id) === Number(stockUnitId)) {
      sameUnitAsStock = true
      stockUnitsPerPurchaseUnit = '1'
      stockQtyEquivalent = String(qInt)
      const r = roundMoney(pu)
      if (r != null)
        costDerivedSale = String(r)
    }
    else {
      try {
        const bodyBase = {
          from_unit_id: Number(draft.unit_id),
          to_unit_id: stockUnitId,
        }
        const [resOne, resLine] = await Promise.all([
          $api('/unit-conversions/convert', {
            method: 'POST',
            body: { ...bodyBase, quantity: 1 },
          }),
          $api('/unit-conversions/convert', {
            method: 'POST',
            body: { ...bodyBase, quantity: qInt },
          }),
        ])
        const perStr = resOne?.data?.quantity_to
        stockUnitsPerPurchaseUnit = perStr != null && String(perStr).trim() !== '' ? String(perStr) : ''
        const perNum = Number(perStr)
        const qtyTo = resLine?.data?.quantity_to
        stockQtyEquivalent = qtyTo != null && String(qtyTo).trim() !== '' ? String(qtyTo) : ''
        const qtyLineNum = Number(qtyTo)
        if (Number.isFinite(qtyLineNum) && qtyLineNum > 0) {
          const lineTotalMoney = roundMoney(qInt * pu)
          const per = lineTotalMoney != null ? roundMoney(lineTotalMoney / qtyLineNum) : null
          if (per != null)
            costDerivedSale = String(per)
        }
        else if (Number.isFinite(perNum) && perNum > 0) {
          const perCost = roundMoney(pu / perNum)
          if (perCost != null)
            costDerivedSale = String(perCost)
        }
      }
      catch (e) {
        snackbar.text = messageFromApiError(e) || 'No hay conversión entre la unidad de compra y la de inventario; definila en Unidades o usá la misma unidad.'
        snackbar.show = true
      }
    }

    const defaultNewSale = costDerivedSale !== '' ? costDerivedSale : catalogSale

    detailLines.value.push({
      product_id: pid,
      product_label: draft.product_label || `Producto #${pid}`,
      unit_id: Number(draft.unit_id),
      unit_name: units.value.find(u => Number(u.id) === Number(draft.unit_id))?.name || '',
      quantity: String(qInt),
      price_unit: String(pu),
      line_note: String(draft.line_note || '').trim(),
      sale_price_ref: catalogSale,
      cost_derived_sale_suggestion: costDerivedSale,
      stock_unit_name: stockUnitName,
      stock_units_per_purchase_unit: stockUnitsPerPurchaseUnit,
      stock_qty_equivalent: stockQtyEquivalent,
      same_unit_as_stock: sameUnitAsStock,
      update_sale_price: false,
      new_sale_price: defaultNewSale,
    })

    draft.product_id = null
    draft.product_label = ''
    draft.unit_id = null
    draft.quantity = '1'
    draft.price_unit = ''
    draft.line_note = ''
    productSearch.value = ''
    productHits.value = []
  }
  finally {
    addingLine.value = false
  }
}

function removeDetailLine(i) {
  detailLines.value.splice(i, 1)
}

async function fetchBranches() {
  try {
    const res = await $api('/branches')

    branches.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    branches.value = []
  }
}

async function fetchUsers() {
  try {
    const res = await $api('/users')

    users.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    users.value = []
  }
}

async function fetchWarehouses() {
  try {
    const res = await $api('/warehouses')

    warehouses.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    warehouses.value = []
  }
}

async function fetchSuppliers() {
  try {
    const res = await $api('/suppliers')

    suppliers.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    suppliers.value = []
  }
}

async function fetchUnits() {
  try {
    const res = await $api('/units')

    units.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    units.value = []
  }
}

async function openPurchaseOrderPdf(purchaseId) {
  const id = Number(purchaseId)
  if (!Number.isFinite(id) || id <= 0)
    return

  try {
    const raw = await $apiRaw(`/purchases/${id}/pdf`, { responseType: 'blob' })
    const blob = blobFromOfetchRawResponse(raw)
    if (!blob?.size)
      return

    const url = URL.createObjectURL(blob)
    const win = window.open(url, '_blank', 'noopener,noreferrer')
    if (!win) {
      snackbar.text = 'Permití ventanas emergentes para ver el PDF de la orden.'
      snackbar.show = true
    }
    else {
      snackbar.text = 'PDF de la orden abierto en una nueva pestaña.'
      snackbar.show = true
    }
    setTimeout(() => URL.revokeObjectURL(url), 90_000)
  }
  catch {
    snackbar.text = 'No se pudo generar el PDF de la orden.'
    snackbar.show = true
  }
}

async function submitPurchase() {
  submitError.value = ''

  if (!requesterId.value) {
    submitError.value = 'Elegí el solicitante.'

    return
  }
  if (!branchId.value) {
    submitError.value = 'Elegí la sucursal.'

    return
  }
  if (!warehouseId.value) {
    submitError.value = 'Elegí el almacén de ingreso.'

    return
  }

  const items = []
  for (const line of detailLines.value) {
    const q = Number(String(line.quantity || '').replace(',', '.'))
    const pu = Number(String(line.price_unit || '').replace(',', '.'))
    const row = {
      product_id: Number(line.product_id),
      unit_id: Number(line.unit_id),
      quantity: q,
      price_unit: pu,
      description: line.line_note ? line.line_note : null,
    }
    if (line.update_sale_price) {
      const nsp = Number(String(line.new_sale_price ?? '').replace(',', '.'))
      if (Number.isFinite(nsp) && nsp >= 0) {
        row.update_sale_price = true
        row.new_sale_price = Math.round(nsp * 100) / 100
      }
    }
    items.push(row)
  }

  if (!items.length) {
    submitError.value = 'Agregá al menos una línea al detalle con el botón Añadir.'

    return
  }

  submitting.value = true
  try {
    const res = await $api('/purchases', {
      method: 'POST',
      body: {
        warehouse_id: Number(warehouseId.value),
        requester_id: Number(requesterId.value),
        supplier_id: supplierId.value != null ? Number(supplierId.value) : null,
        date_emision: dateEmision.value || null,
        state: stateHeader.value,
        type_comprobant: String(typeComprobant.value || '').trim() || null,
        n_comprobant: String(nComprobant.value || '').trim() || null,
        reference: String(reference.value || '').trim() || null,
        notes: String(notes.value || '').trim() || null,
        igv: igvNum.value,
        items,
      },
    })
    const newId = res?.data?.id
    if (newId)
      await openPurchaseOrderPdf(newId)
    else {
      snackbar.text = 'Compra registrada.'
      snackbar.show = true
    }
    await router.push({ name: 'compras' })
  }
  catch (e) {
    submitError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para registrar compras.',
      fallback: 'No se pudo guardar la orden de compra.',
    })
  }
  finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await authStore.fetchMe(true)
  if (!canManage.value)
    return

  await Promise.all([
    fetchBranches(),
    fetchUsers(),
    fetchWarehouses(),
    fetchSuppliers(),
    fetchUnits(),
  ])

  const u = authStore.user
  if (u?.id)
    requesterId.value = u.id

  if (branches.value.length === 1)
    branchId.value = branches.value[0].id

  const wf = warehousesFiltered.value
  if (wf.length === 1)
    warehouseId.value = wf[0].id
})
</script>

<template>
  <div>
    <div
      v-if="authStore.loading"
      class="d-flex justify-center py-16"
    >
      <VProgressCircular indeterminate color="primary" />
    </div>

    <VCard v-else-if="!canManage">
      <VCardText class="py-12 text-center text-medium-emphasis">
        Solo administradores pueden registrar compras.
      </VCardText>
    </VCard>

    <div
      v-else
      class="pa-2 pa-md-4"
    >
      <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
        <div class="d-flex align-start gap-3">
          <VAvatar
            color="primary"
            variant="tonal"
            size="52"
            rounded="lg"
            class="mt-1"
          >
            <VIcon
              icon="ri-shopping-cart-2-line"
              size="28"
            />
          </VAvatar>
          <div>
            <h1 class="text-h5 d-flex align-center gap-2 flex-wrap">
              Registrar compra
            </h1>
            <p class="text-body-2 text-medium-emphasis mb-0">
              Elegí sucursal y almacén, solicitante y proveedor. Si comprás por caja o docena, en el detalle verás cuántas unidades de stock equivalen a una caja o una docena, y el total de la línea. El precio de venta sugerido usa esa relación; podés actualizarlo al guardar si lo activás en cada línea.
            </p>
          </div>
        </div>
        <VBtn
          variant="tonal"
          prepend-icon="ri-list-check"
          :to="{ name: 'compras' }"
        >
          Ir al listado
        </VBtn>
      </div>

      <VCard>
        <VCardText>
          <VAlert
            v-if="submitError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            rounded="lg"
          >
            {{ submitError }}
          </VAlert>

          <VCard
            variant="outlined"
            class="mb-6"
            rounded="lg"
          >
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="secondary"
                  variant="tonal"
                  size="40"
                  rounded="lg"
                >
                  <VIcon icon="ri-building-4-line" />
                </VAvatar>
              </template>
              <VCardTitle class="text-subtitle-1 py-2">
                Datos generales
              </VCardTitle>
              <VCardSubtitle class="pb-0">
                Sucursal, almacén, solicitante y documento
              </VCardSubtitle>
            </VCardItem>
            <VCardText class="pt-0">
          <VRow dense>
            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="branchId"
                label="Sucursal *"
                :items="branchItems"
                clearable
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="requesterId"
                label="Solicitante *"
                :items="userItems"
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="warehouseId"
                label="Almacén de ingreso *"
                :items="warehousesFiltered.map(w => ({ title: w.branch?.name ? `${w.name} — ${w.branch.name}` : w.name, value: w.id }))"
                :disabled="!branchId"
                :hint="branchId ? 'Solo almacenes de la sucursal elegida.' : 'Primero elegí sucursal.'"
                persistent-hint
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="supplierId"
                label="Proveedor"
                clearable
                :items="suppliers.map(s => ({ title: s.name, value: s.id }))"
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="dateEmision"
                label="Fecha emisión"
                type="date"
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="stateHeader"
                label="Estado inicial"
                :items="STATE_HEADERS"
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="typeComprobant"
                label="Tipo comprobante"
                density="comfortable"
                placeholder="Factura, recibo…"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="nComprobant"
                label="Nº comprobante"
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="reference"
                label="Referencia interna"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="notes"
                label="Notas o aclaraciones"
                prepend-inner-icon="ri-sticky-note-line"
                placeholder="Cualquier detalle adicional para el área de compras o almacén…"
                rows="3"
                auto-grow
                density="comfortable"
              />
            </VCol>
          </VRow>
            </VCardText>
          </VCard>

          <VCard
            variant="outlined"
            class="mb-6"
            rounded="lg"
          >
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="info"
                  variant="tonal"
                  size="40"
                  rounded="lg"
                >
                  <VIcon icon="ri-add-box-line" />
                </VAvatar>
              </template>
              <VCardTitle class="text-subtitle-1 py-2">
                Agregar al detalle
              </VCardTitle>
              <VCardSubtitle class="pb-0">
                Buscá el producto, unidad, cantidad y precio; luego <strong>Añadir al detalle</strong>.
              </VCardSubtitle>
            </VCardItem>
            <VCardText class="pt-0">

          <VRow
            dense
            class="align-end"
          >
            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="productSearch"
                label="Buscar producto"
                density="comfortable"
                prepend-inner-icon="ri-search-line"
                clearable
                :loading="productSearchLoading"
                :disabled="!warehouseId"
                @update:model-value="onProductSearchInput"
              />
              <VSelect
                v-model="draft.product_id"
                label="Producto seleccionado"
                :items="productSelectItems"
                item-title="title"
                item-value="value"
                clearable
                density="comfortable"
                class="mt-2"
                :disabled="!productSelectItems.length && !draft.product_id"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <VSelect
                v-model="draft.unit_id"
                label="Unidad *"
                :items="unitItems"
                clearable
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="6"
              sm="3"
              md="2"
            >
              <VTextField
                v-model="draft.quantity"
                label="Cantidad (en la unidad elegida)"
                type="number"
                min="1"
                step="1"
                density="comfortable"
                hint="Ej.: 1 docena o 2 cajas. En el detalle se muestra cuántas unidades de stock hay por 1 docena o por 1 caja, y el total de la línea; al recibir la compra el stock sube por ese total."
                persistent-hint
              />
            </VCol>
            <VCol
              cols="6"
              sm="3"
              md="2"
            >
              <VTextField
                v-model="draft.price_unit"
                label="Precio unit."
                type="number"
                min="0"
                step="0.01"
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              md="12"
            >
              <VTextField
                v-model="draft.line_note"
                label="Nota de línea (opcional)"
                density="comfortable"
                class="mb-2"
              />
              <VBtn
                color="primary"
                prepend-icon="ri-add-line"
                :disabled="!warehouseId"
                :loading="addingLine"
                @click="addLineToDetail"
              >
                Añadir al detalle
              </VBtn>
            </VCol>
          </VRow>
            </VCardText>
          </VCard>

          <div class="d-flex align-center gap-2 mb-3">
            <VIcon
              icon="ri-list-ordered-2"
              class="text-primary"
            />
            <span class="text-subtitle-1">Detalle de la compra</span>
          </div>
          <div
            v-if="detailLines.length"
            class="overflow-x-auto border rounded mb-4"
          >
            <VTable
              density="comfortable"
              class="text-no-wrap"
              style="min-width: 1080px;"
            >
              <thead>
                <tr>
                  <th>#</th>
                  <th>Producto</th>
                  <th>Unidad compra</th>
                  <th class="text-end">
                    Cant. compra
                  </th>
                  <th class="text-end">
                    <span class="d-inline-block text-end">Stock por compra</span>
                    <div class="text-caption font-weight-regular text-medium-emphasis text-wrap" style="max-width: 11rem;">
                      Por 1 docena/caja… y total de la línea
                    </div>
                  </th>
                  <th class="text-end">
                    P. unit. compra
                  </th>
                  <th class="text-end">
                    Subtotal
                  </th>
                  <th class="text-end">
                    P. venta actual
                  </th>
                  <th class="text-end">
                    Sugerido venta
                  </th>
                  <th class="text-center">
                    Actualizar venta
                  </th>
                  <th style="min-width: 120px;">
                    Nuevo precio venta
                  </th>
                  <th style="width: 48px;" />
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(line, idx) in detailLines"
                  :key="idx"
                >
                  <td>{{ idx + 1 }}</td>
                  <td>
                    <div class="font-weight-medium">
                      {{ line.product_label }}
                    </div>
                    <div
                      v-if="line.line_note"
                      class="text-caption text-medium-emphasis"
                    >
                      {{ line.line_note }}
                    </div>
                  </td>
                  <td>{{ line.unit_name || '—' }}</td>
                  <td class="text-end">
                    {{ line.quantity }}
                  </td>
                  <td class="text-end text-wrap" style="max-width: 14rem;">
                    <template v-if="line.stock_unit_name && (line.same_unit_as_stock || line.stock_units_per_purchase_unit)">
                      <div
                        v-if="!line.same_unit_as_stock"
                        class="text-body-2"
                      >
                        1 {{ line.unit_name }} = {{ formatStockQty(line.stock_units_per_purchase_unit) }} {{ line.stock_unit_name }}
                      </div>
                      <div
                        v-else
                        class="text-caption text-medium-emphasis"
                      >
                        Compra e inventario en la misma unidad.
                      </div>
                      <div
                        v-if="line.stock_qty_equivalent"
                        class="text-caption mt-1"
                        :class="line.same_unit_as_stock ? 'text-body-2' : 'text-medium-emphasis'"
                      >
                        Esta línea (×{{ line.quantity }}): {{ formatStockQty(line.stock_qty_equivalent) }} {{ line.stock_unit_name }}
                      </div>
                    </template>
                    <span
                      v-else
                      class="text-medium-emphasis"
                    >—</span>
                  </td>
                  <td class="text-end">
                    {{ formatBs(line.price_unit) }}
                  </td>
                  <td class="text-end">
                    {{ formatBs(lineTotal(line)) }}
                  </td>
                  <td class="text-end">
                    {{ formatBs(line.sale_price_ref) }}
                  </td>
                  <td class="text-end">
                    <div>{{ line.cost_derived_sale_suggestion ? formatBs(line.cost_derived_sale_suggestion) : '—' }}</div>
                    <div
                      v-if="line.stock_unit_name && line.cost_derived_sale_suggestion"
                      class="text-caption text-medium-emphasis"
                    >
                      por {{ line.stock_unit_name }}
                    </div>
                  </td>
                  <td class="text-center">
                    <VSwitch
                      :model-value="line.update_sale_price"
                      density="compact"
                      hide-details
                      inset
                      color="primary"
                      @update:model-value="(v) => {
                        line.update_sale_price = v
                        if (v) {
                          if (line.cost_derived_sale_suggestion)
                            line.new_sale_price = line.cost_derived_sale_suggestion
                          else if (line.sale_price_ref)
                            line.new_sale_price = line.sale_price_ref
                        }
                      }"
                    />
                  </td>
                  <td>
                    <VTextField
                      v-model="line.new_sale_price"
                      type="number"
                      min="0"
                      step="0.01"
                      density="compact"
                      hide-details
                      variant="outlined"
                      :disabled="!line.update_sale_price"
                    />
                  </td>
                  <td>
                    <VBtn
                      icon
                      size="small"
                      variant="text"
                      @click="removeDetailLine(idx)"
                    >
                      <VIcon icon="ri-delete-bin-line" />
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
          <VAlert
            v-else
            type="warning"
            variant="tonal"
            density="comfortable"
            class="mb-4"
            rounded="lg"
            prominent
          >
            <template #prepend>
              <VIcon icon="ri-information-line" />
            </template>
            Todavía no hay líneas. Usá <strong>Añadir al detalle</strong> arriba.
          </VAlert>

          <VCard
            variant="outlined"
            class="mb-2"
            rounded="lg"
          >
            <VCardItem class="pb-0">
              <template #prepend>
                <VAvatar
                  color="success"
                  variant="tonal"
                  size="36"
                  rounded="lg"
                >
                  <VIcon icon="ri-calculator-line" />
                </VAvatar>
              </template>
              <VCardTitle class="text-subtitle-1 py-2">
                Totales
              </VCardTitle>
              <VCardSubtitle>
                Revisá antes de registrar
              </VCardSubtitle>
            </VCardItem>
            <VCardText>
              <VRow dense>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    Importe (suma de líneas)
                  </div>
                  <div class="text-h6">
                    {{ formatBs(importeComputed) }} Bs.
                  </div>
                </VCol>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <VTextField
                    v-model="igv"
                    label="IGV"
                    type="number"
                    min="0"
                    step="0.01"
                    density="comfortable"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    Total compra
                  </div>
                  <div class="text-h6 text-primary">
                    {{ formatBs(totalComputed) }} Bs.
                  </div>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            color="primary"
            size="large"
            prepend-icon="ri-file-pdf-2-line"
            append-icon="ri-arrow-right-line"
            :loading="submitting"
            :disabled="!detailLines.length"
            @click="submitPurchase"
          >
            Registrar y ver PDF
          </VBtn>
        </VCardActions>
      </VCard>

      <VSnackbar
        v-model="snackbar.show"
        :timeout="2500"
      >
        {{ snackbar.text }}
      </VSnackbar>
    </div>
  </div>
</template>
