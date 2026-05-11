<script setup>
/* eslint-disable camelcase -- payload API Laravel (snake_case). */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'
import { formatBsAmount } from '@/utils/formatNumbers'

const router = useRouter()

definePage({
  meta: {
    navActiveLink: 'transportes-registrar',
  },
})

const authStore = useAuthStore()

const canManage = computed(() => authStore.isAdmin)

const branches = ref([])
const warehouses = ref([])
const units = ref([])

const branchId = ref(null)
const warehouseStartId = ref(null)
const warehouseEndId = ref(null)
const dateEmision = ref(new Date().toISOString().slice(0, 10))
const reference = ref('')
const description = ref('')
const igv = ref('0')

const detailLines = ref([])

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

const warehouseSelectItems = computed(() =>
  warehousesFiltered.value.map(w => ({
    title: w.branch?.name ? `${w.name} — ${w.branch.name}` : w.name,
    value: w.id,
  })),
)

const branchItems = computed(() =>
  branches.value.map(b => ({
    title: b.code ? `${b.name} (${b.code})` : b.name,
    value: b.id,
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
  if (warehouseStartId.value != null && !allowed.has(warehouseStartId.value))
    warehouseStartId.value = null
  if (warehouseEndId.value != null && !allowed.has(warehouseEndId.value))
    warehouseEndId.value = null
})

watch([warehouseStartId, warehouseEndId], () => {
  if (
    warehouseStartId.value != null
    && warehouseEndId.value != null
    && Number(warehouseStartId.value) === Number(warehouseEndId.value)
  ) {
    warehouseEndId.value = null
  }
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

function addLineToDetail() {
  submitError.value = ''

  if (!warehouseStartId.value || !warehouseEndId.value) {
    submitError.value = 'Elegí almacén de origen y destino (distintos).'

    return
  }
  if (!draft.product_id) {
    submitError.value = 'Elegí un producto.'

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
    submitError.value = 'La cantidad debe ser un número entero (stock).'

    return
  }

  const pid = Number(draft.product_id)

  detailLines.value.push({
    product_id: pid,
    product_label: draft.product_label || `Producto #${pid}`,
    unit_id: Number(draft.unit_id),
    unit_name: units.value.find(u => Number(u.id) === Number(draft.unit_id))?.name || '',
    quantity: String(Math.round(q)),
    price_unit: String(pu),
    line_note: String(draft.line_note || '').trim(),
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

async function fetchWarehouses() {
  try {
    const res = await $api('/warehouses')
    warehouses.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    warehouses.value = []
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

async function openTransportPdf(transportId) {
  const id = Number(transportId)
  if (!Number.isFinite(id) || id <= 0)
    return

  try {
    const raw = await $apiRaw(`/transports/${id}/pdf`, { responseType: 'blob' })
    const blob = blobFromOfetchRawResponse(raw)
    if (!blob?.size)
      return

    const url = URL.createObjectURL(blob)
    const win = window.open(url, '_blank', 'noopener,noreferrer')
    if (!win) {
      snackbar.text = 'Permití ventanas emergentes para ver el PDF.'
      snackbar.show = true
    }
    else {
      snackbar.text = 'PDF abierto en una nueva pestaña.'
      snackbar.show = true
    }
    setTimeout(() => URL.revokeObjectURL(url), 90_000)
  }
  catch {
    snackbar.text = 'No se pudo generar el PDF.'
    snackbar.show = true
  }
}

async function submitTransport() {
  submitError.value = ''

  if (!branchId.value) {
    submitError.value = 'Elegí la sucursal.'

    return
  }
  if (!warehouseStartId.value || !warehouseEndId.value) {
    submitError.value = 'Elegí almacén de origen y destino.'

    return
  }
  if (Number(warehouseStartId.value) === Number(warehouseEndId.value)) {
    submitError.value = 'Origen y destino deben ser distintos.'

    return
  }

  const items = []
  for (const line of detailLines.value) {
    const q = Number(String(line.quantity || '').replace(',', '.'))
    const pu = Number(String(line.price_unit || '').replace(',', '.'))
    items.push({
      product_id: Number(line.product_id),
      unit_id: Number(line.unit_id),
      quantity: q,
      price_unit: pu,
      description: line.line_note ? line.line_note : null,
    })
  }

  if (!items.length) {
    submitError.value = 'Agregá al menos una línea al detalle.'

    return
  }

  submitting.value = true
  try {
    const res = await $api('/transports', {
      method: 'POST',
      body: {
        warehouse_start_id: Number(warehouseStartId.value),
        warehouse_end_id: Number(warehouseEndId.value),
        date_emision: dateEmision.value || null,
        reference: String(reference.value || '').trim() || null,
        description: String(description.value || '').trim() || null,
        igv: igvNum.value,
        items,
      },
    })
    const newId = res?.data?.id
    if (newId)
      await openTransportPdf(newId)
    else {
      snackbar.text = 'Traslado registrado.'
      snackbar.show = true
    }
    await router.push({ name: 'transportes' })
  }
  catch (e) {
    submitError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para registrar transportes.',
      fallback: 'No se pudo guardar el traslado.',
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

  await Promise.all([fetchBranches(), fetchWarehouses(), fetchUnits()])

  if (branches.value.length === 1)
    branchId.value = branches.value[0].id

  const wf = warehousesFiltered.value
  if (wf.length >= 2) {
    warehouseStartId.value = wf[0].id
    warehouseEndId.value = wf[1].id
  }
})
</script>

<template>
  <div>
    <div
      v-if="authStore.loading"
      class="d-flex justify-center py-16"
    >
      <VProgressCircular
        indeterminate
        color="primary"
      />
    </div>

    <VCard v-else-if="!canManage">
      <VCardText class="py-12 text-center text-medium-emphasis">
        Solo administradores pueden registrar traslados.
      </VCardText>
    </VCard>

    <div
      v-else
      class="pa-2 pa-md-4"
    >
      <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
        <div class="d-flex align-start gap-3">
          <VAvatar
            color="info"
            variant="tonal"
            size="52"
            rounded="lg"
            class="mt-1"
          >
            <VIcon
              icon="ri-truck-line"
              size="28"
            />
          </VAvatar>
          <div>
            <h1 class="text-h5">
              Registrar traslado
            </h1>
            <p class="text-body-2 text-medium-emphasis mb-0">
              Origen y destino en la misma sucursal. Las líneas inician en solicitud; luego marcá <strong>Salida</strong> y <strong>Entrega</strong> desde el listado.
            </p>
          </div>
        </div>
        <VBtn
          variant="tonal"
          prepend-icon="ri-list-check"
          :to="{ name: 'transportes' }"
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
            rounded="lg"
            class="mb-6"
          >
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="secondary"
                  variant="tonal"
                  size="40"
                  rounded="lg"
                >
                  <VIcon icon="ri-route-line" />
                </VAvatar>
              </template>
              <VCardTitle class="text-subtitle-1 py-2">
                Ruta y datos
              </VCardTitle>
              <VCardSubtitle class="pb-0">
                Sucursal, almacenes origen → destino
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
                    v-model="warehouseStartId"
                    label="Almacén origen *"
                    :items="warehouseSelectItems"
                    :disabled="!branchId"
                    density="comfortable"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="4"
                >
                  <VSelect
                    v-model="warehouseEndId"
                    label="Almacén destino *"
                    :items="warehouseSelectItems"
                    :disabled="!branchId"
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
                  <VTextField
                    v-model="reference"
                    label="Referencia"
                    density="comfortable"
                  />
                </VCol>
                <VCol cols="12">
                  <VTextarea
                    v-model="description"
                    label="Descripción"
                    rows="2"
                    auto-grow
                    density="comfortable"
                    prepend-inner-icon="ri-file-text-line"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VCard
            variant="outlined"
            rounded="lg"
            class="mb-6"
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
                Agregar líneas
              </VCardTitle>
              <VCardSubtitle class="pb-0">
                El producto debe existir en origen y destino (catálogo).
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
                    :disabled="!warehouseStartId || !warehouseEndId"
                    @update:model-value="onProductSearchInput"
                  />
                  <VSelect
                    v-model="draft.product_id"
                    label="Producto"
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
                    label="Cantidad"
                    type="number"
                    min="1"
                    step="1"
                    density="comfortable"
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
                <VCol cols="12">
                  <VTextField
                    v-model="draft.line_note"
                    label="Nota de línea (opcional)"
                    density="comfortable"
                    class="mb-2"
                  />
                  <VBtn
                    color="primary"
                    prepend-icon="ri-add-line"
                    :disabled="!warehouseStartId || !warehouseEndId"
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
              class="text-info"
            />
            <span class="text-subtitle-1">Detalle</span>
          </div>

          <VTable
            v-if="detailLines.length"
            density="comfortable"
            class="border rounded mb-4"
          >
            <thead>
              <tr>
                <th>#</th>
                <th>Producto</th>
                <th>Unidad</th>
                <th class="text-end">
                  Cant.
                </th>
                <th class="text-end">
                  P. unit.
                </th>
                <th class="text-end">
                  Subtotal
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
                <td class="text-end">
                  {{ formatBs(line.price_unit) }}
                </td>
                <td class="text-end">
                  {{ formatBs(lineTotal(line)) }}
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
          <VAlert
            v-else
            type="warning"
            variant="tonal"
            density="comfortable"
            class="mb-4"
            rounded="lg"
          >
            Agregá líneas con el formulario de arriba.
          </VAlert>

          <VCard
            variant="outlined"
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
            </VCardItem>
            <VCardText>
              <VRow dense>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    Importe
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
                    Total
                  </div>
                  <div class="text-h6 text-info">
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
            @click="submitTransport"
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
