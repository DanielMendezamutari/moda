<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'compras',
  },
})

const authStore = useAuthStore()

const canView = computed(() => authStore.isAdmin)
const canManage = computed(() => authStore.isAdmin)

const search = ref('')
const stateFilter = ref(null)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const errorMsg = ref('')
const purchases = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const exportBusy = ref(false)
const exportBusyKind = ref(null)

const dataExportFormats = [
  { value: 'csv', title: 'Descargar CSV', icon: 'ri-file-text-line' },
  { value: 'xlsx', title: 'Descargar Excel', icon: 'ri-file-excel-line' },
  { value: 'docx', title: 'Descargar Word', icon: 'ri-file-word-line' },
]

const pdfVariants = [
  { value: 'list', title: 'Listado completo', caption: 'Tabla con los mismos filtros (búsqueda y estado)' },
  { value: 'summary', title: 'Resumen', caption: 'Conteos por estado y suma de totales' },
]

function isExportBusy(kind) {
  return exportBusy.value && exportBusyKind.value === kind
}

function isPdfVariantBusy(variant) {
  return exportBusy.value && exportBusyKind.value === `pdf:${variant}`
}

const STATE_OPTIONS = [
  { value: 'solicitud', title: 'Solicitud' },
  { value: 'revision', title: 'Revisión' },
  { value: 'parcial', title: 'Parcial' },
  { value: 'entregado', title: 'Entregado' },
]

const headers = [
  { title: 'ID', key: 'id', width: '72px' },
  { title: 'Proveedor / Ref.', key: 'ref', sortable: false },
  { title: 'Almacén', key: 'warehouse', sortable: false },
  { title: 'Importe', key: 'importe', sortable: false },
  { title: 'Total', key: 'total', sortable: false },
  { title: 'Estado', key: 'state', sortable: false },
  { title: 'Líneas', key: 'items_count', sortable: false },
  { title: 'Registro', key: 'created_at', sortable: false },
  { title: 'Acciones', key: 'actions', align: 'end', width: '100px' },
]

function stateLabel(v) {
  return STATE_OPTIONS.find(s => s.value === v)?.title ?? v ?? '—'
}

function stateColor(v) {
  if (v === 'entregado')
    return 'success'
  if (v === 'parcial')
    return 'warning'
  if (v === 'revision')
    return 'info'

  return 'secondary'
}

function formatMoney(v) {
  if (v == null || v === '')
    return '—'
  const n = Number(v)
  if (!Number.isFinite(n))
    return '—'

  return `${new Intl.NumberFormat('es', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)} Bs.`
}

function formatDate(iso) {
  if (!iso)
    return '—'
  try {
    const d = new Date(iso)

    return new Intl.DateTimeFormat('es', { dateStyle: 'short', timeStyle: 'short' }).format(d)
  }
  catch {
    return '—'
  }
}

let searchTimer = null

async function fetchPurchases() {
  errorMsg.value = ''
  loading.value = true
  try {
    const q = new URLSearchParams()
    q.set('page', String(page.value))
    const s = search.value.trim()
    if (s)
      q.set('search', s)
    if (stateFilter.value)
      q.set('state', stateFilter.value)

    const res = await $api(`/purchases?${q.toString()}`)

    purchases.value = Array.isArray(res?.data) ? res.data : []
    lastPage.value = Number(res?.last_page) || 1
    total.value = Number(res?.total) ?? purchases.value.length
  }
  catch (e) {
    purchases.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver compras.',
      fallback: 'No se pudieron cargar las compras.',
    })
  }
  finally {
    loading.value = false
  }
}

function onSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    fetchPurchases()
  }, 380)
}

watch(page, () => {
  fetchPurchases()
})

watch(stateFilter, () => {
  page.value = 1
  fetchPurchases()
})

function exportQueryParams() {
  const params = new URLSearchParams()
  const s = search.value.trim()
  if (s)
    params.set('search', s)
  if (stateFilter.value)
    params.set('state', stateFilter.value)

  return params
}

async function exportErrorMessage(e, fallback) {
  if (e?.data instanceof Blob) {
    try {
      const t = await e.data.text()
      const j = JSON.parse(t)

      return j.message || j.error || fallback
    }
    catch {
      return fallback
    }
  }

  return messageFromApiError(e, { fallback })
}

async function exportPurchaseList(format) {
  exportBusy.value = true
  exportBusyKind.value = format
  try {
    const params = exportQueryParams()
    params.set('format', format)

    const res = await $apiRaw(`purchases/export?${params.toString()}`, {
      responseType: 'blob',
    })

    const blob = blobFromOfetchRawResponse(res)
    if (!blob) {
      snackbar.text = 'No se pudo leer el archivo de exportación.'
      snackbar.show = true

      return
    }

    const dispo = res.headers.get('Content-Disposition') || ''
    let filename = `compras.${format}`
    const m = /filename="?([^";\n]+)"?/i.exec(dispo)
    if (m?.[1])
      filename = m[1].trim()

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)

    snackbar.text = 'Descarga iniciada.'
    snackbar.show = true
  }
  catch (e) {
    snackbar.text = await exportErrorMessage(e, 'No se pudo exportar el listado.')
    snackbar.show = true
  }
  finally {
    exportBusy.value = false
    exportBusyKind.value = null
  }
}

async function downloadPurchaseReportPdf(variant) {
  exportBusy.value = true
  exportBusyKind.value = `pdf:${variant}`
  try {
    const params = exportQueryParams()
    params.set('variant', variant)

    const res = await $apiRaw(`purchases/export/pdf?${params.toString()}`, {
      responseType: 'blob',
    })

    const blob = blobFromOfetchRawResponse(res)
    if (!blob) {
      snackbar.text = 'No se pudo leer el PDF.'
      snackbar.show = true

      return
    }

    const pdfBlob = blob.type === 'application/pdf'
      ? blob
      : new Blob([blob], { type: 'application/pdf' })

    const url = URL.createObjectURL(pdfBlob)
    const win = window.open(url, '_blank', 'noopener,noreferrer')
    if (!win) {
      URL.revokeObjectURL(url)
      snackbar.text = 'No se pudo abrir la pestaña. Permití ventanas emergentes para este sitio.'
      snackbar.show = true

      return
    }

    setTimeout(() => URL.revokeObjectURL(url), 120_000)

    snackbar.text = 'PDF abierto en una nueva pestaña.'
    snackbar.show = true
  }
  catch (e) {
    snackbar.text = await exportErrorMessage(e, 'No se pudo generar el informe PDF.')
    snackbar.show = true
  }
  finally {
    exportBusy.value = false
    exportBusyKind.value = null
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
      snackbar.text = 'Permití ventanas emergentes para ver el PDF.'
      snackbar.show = true
    }
    setTimeout(() => URL.revokeObjectURL(url), 90_000)
  }
  catch {
    snackbar.text = 'No se pudo abrir el PDF de la orden.'
    snackbar.show = true
  }
}

const detailOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detailPurchase = ref(null)
const lineUpdatingId = ref(null)

async function openDetail(row) {
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = ''
  detailPurchase.value = null

  try {
    const res = await $api(`/purchases/${row.id}`)
    detailPurchase.value = res?.data ?? null
  }
  catch (e) {
    detailError.value = messageFromApiError(e, {
      forbidden: 'No podés ver esta compra.',
      fallback: 'No se pudo cargar el detalle.',
    })
  }
  finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  detailOpen.value = false
  detailPurchase.value = null
}

async function markLineDelivered(purchaseId, line) {
  if (!line?.id || line.state !== 'solicitud')
    return

  lineUpdatingId.value = line.id
  try {
    await $api(`/purchases/${purchaseId}/items/${line.id}`, {
      method: 'PATCH',
      body: { state: 'entregado' },
    })
    snackbar.text = 'Línea marcada como entregada; stock actualizado.'
    snackbar.show = true
    await openDetail({ id: purchaseId })
    await fetchPurchases()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, { fallback: 'No se pudo actualizar la línea.' })
    snackbar.show = true
  }
  finally {
    lineUpdatingId.value = null
  }
}

async function revertLine(purchaseId, line) {
  if (!line?.id || line.state !== 'entregado')
    return

  lineUpdatingId.value = line.id
  try {
    await $api(`/purchases/${purchaseId}/items/${line.id}`, {
      method: 'PATCH',
      body: { state: 'solicitud' },
    })
    snackbar.text = 'Línea vuelta a solicitud (stock revertido si aplicaba).'
    snackbar.show = true
    await openDetail({ id: purchaseId })
    await fetchPurchases()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, { fallback: 'No se pudo revertir.' })
    snackbar.show = true
  }
  finally {
    lineUpdatingId.value = null
  }
}

onMounted(async () => {
  await authStore.fetchMe(true)
  if (canView.value)
    await fetchPurchases()
})
</script>

<template>
  <div>
    <div
      v-if="authStore.loading"
      class="d-flex justify-center py-16"
    >
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VCard v-else-if="!canView">
      <VCardText class="py-12 text-center">
        <h2 class="text-h5 mb-2">
          Sin acceso
        </h2>
        <p class="text-body-2 text-medium-emphasis">
          Solo administradores gestionan compras.
        </p>
      </VCardText>
    </VCard>

    <div
      v-else
      class="pa-2 pa-md-4"
    >
      <VCard>
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 pb-2">
          <div class="d-flex align-start gap-3">
            <VAvatar
              color="primary"
              variant="tonal"
              size="48"
              rounded="lg"
              class="mt-1"
            >
              <VIcon
                icon="ri-shopping-basket-2-line"
                size="26"
              />
            </VAvatar>
            <div>
              <VCardTitle class="text-h5 pa-0 pb-1 d-flex align-center gap-2">
                Compras
              </VCardTitle>
              <VCardSubtitle class="pa-0">
                Órdenes por almacén; estado de cabecera y de cada línea. <strong>Entregado</strong> en línea ingresa stock.
              </VCardSubtitle>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <VBtn
              v-if="canManage"
              color="primary"
              prepend-icon="ri-add-line"
              :to="{ name: 'compras-registrar' }"
            >
              Registrar compra
            </VBtn>
          </div>
        </VCardText>

        <VDivider />

        <VCardText>
          <VAlert
            v-if="errorMsg"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            rounded="lg"
          >
            {{ errorMsg }}
          </VAlert>

          <div class="d-flex flex-column flex-sm-row flex-wrap align-center gap-3 mb-4">
            <VTextField
              v-model="search"
              label="Buscar"
              density="comfortable"
              prepend-inner-icon="ri-search-line"
              clearable
              hide-details
              style="max-width: 320px;"
              @update:model-value="onSearchInput"
            />
            <VSelect
              v-model="stateFilter"
              label="Estado"
              prepend-inner-icon="ri-filter-3-line"
              :items="[{ title: 'Todos', value: null }, ...STATE_OPTIONS]"
              clearable
              density="comfortable"
              hide-details
              style="max-width: 200px;"
            />
            <div class="text-body-2 text-medium-emphasis d-flex align-center gap-1">
              <VIcon
                icon="ri-file-list-3-line"
                size="18"
                class="text-medium-emphasis"
              />
              {{ total }} resultado(s)
            </div>
            <div class="d-flex flex-wrap align-center gap-1 ms-sm-auto">
              <VBtn
                v-for="f in dataExportFormats"
                :key="f.value"
                icon
                variant="text"
                size="small"
                density="comfortable"
                :loading="isExportBusy(f.value)"
                :disabled="exportBusy || loading"
                @click="exportPurchaseList(f.value)"
              >
                <VIcon :icon="f.icon" />
                <VTooltip
                  activator="parent"
                  location="bottom"
                >
                  {{ f.title }}
                </VTooltip>
              </VBtn>

              <VMenu location="bottom end">
                <template #activator="{ props: menuProps }">
                  <VBtn
                    v-bind="menuProps"
                    size="small"
                    variant="tonal"
                    color="primary"
                    class="text-none px-2"
                    :disabled="exportBusy || loading"
                  >
                    <VIcon
                      icon="ri-file-pdf-line"
                      size="18"
                      class="me-1"
                    />
                    PDF
                    <VIcon
                      icon="ri-arrow-down-s-line"
                      size="16"
                      class="ms-n1"
                    />
                  </VBtn>
                </template>
                <VList
                  density="compact"
                  class="py-1"
                  min-width="280"
                >
                  <VListSubheader class="text-caption text-medium-emphasis">
                    Informes (mismos filtros)
                  </VListSubheader>
                  <VListItem
                    v-for="v in pdfVariants"
                    :key="v.value"
                    :title="v.title"
                    :subtitle="v.caption"
                    prepend-icon="ri-file-pdf-line"
                    :active="false"
                    :disabled="exportBusy || loading"
                    @click="downloadPurchaseReportPdf(v.value)"
                  >
                    <template #append>
                      <VProgressCircular
                        v-if="isPdfVariantBusy(v.value)"
                        indeterminate
                        size="18"
                        width="2"
                        color="primary"
                      />
                    </template>
                  </VListItem>
                </VList>
              </VMenu>
            </div>
          </div>

          <VDataTable
            :headers="headers"
            :items="purchases"
            :loading="loading"
            hide-default-footer
            class="elevation-0"
          >
            <template #item.ref="{ item }">
              <div class="font-weight-medium">
                {{ item.supplier?.name || '—' }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ item.n_comprobant || item.reference || `Compra #${item.id}` }}
              </div>
            </template>

            <template #item.warehouse="{ item }">
              {{ item.warehouse?.name || '—' }}
            </template>

            <template #item.importe="{ item }">
              {{ formatMoney(item.importe) }}
            </template>

            <template #item.total="{ item }">
              {{ formatMoney(item.total) }}
            </template>

            <template #item.state="{ item }">
              <VChip
                size="small"
                :color="stateColor(item.state)"
                variant="tonal"
              >
                {{ stateLabel(item.state) }}
              </VChip>
            </template>

            <template #item.created_at="{ item }">
              {{ formatDate(item.created_at) }}
            </template>

            <template #item.actions="{ item }">
              <div class="d-flex flex-wrap justify-end gap-1">
                <VBtn
                  icon
                  size="small"
                  variant="text"
                  color="primary"
                  @click="openPurchaseOrderPdf(item.id)"
                >
                  <VIcon icon="ri-file-pdf-line" />
                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    PDF orden
                  </VTooltip>
                </VBtn>
                <VBtn
                  size="small"
                  variant="tonal"
                  prepend-icon="ri-eye-line"
                  @click="openDetail(item)"
                >
                  Ver
                </VBtn>
              </div>
            </template>

            <template #bottom>
              <div class="d-flex flex-wrap align-center justify-space-between gap-3 pt-4">
                <span class="text-body-2 text-medium-emphasis">
                  Página {{ page }} de {{ lastPage }}
                </span>
                <VPagination
                  v-model="page"
                  :length="lastPage"
                  :total-visible="7"
                  rounded
                  density="comfortable"
                />
              </div>
            </template>
          </VDataTable>
        </VCardText>
      </VCard>

      <VSnackbar
        v-model="snackbar.show"
        location="bottom"
        :timeout="2800"
      >
        {{ snackbar.text }}
      </VSnackbar>

      <VDialog
        v-model="detailOpen"
        max-width="900"
        scrollable
        @update:model-value="v => !v && closeDetail()"
      >
        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="d-flex align-center gap-2">
              <VIcon
                icon="ri-file-text-line"
                class="text-primary"
              />
              Detalle de compra
            </span>
            <div class="d-flex align-center gap-1">
              <VBtn
                v-if="detailPurchase?.id"
                size="small"
                variant="tonal"
                color="primary"
                prepend-icon="ri-file-pdf-line"
                @click="openPurchaseOrderPdf(detailPurchase.id)"
              >
                PDF
              </VBtn>
              <VBtn
                icon
                variant="text"
                @click="closeDetail"
              >
                <VIcon icon="ri-close-line" />
              </VBtn>
            </div>
          </VCardTitle>
          <VDivider />

          <VCardText v-if="detailLoading" class="py-10 text-center">
            <VProgressCircular indeterminate color="primary" />
          </VCardText>

          <VCardText v-else-if="detailError">
            <VAlert type="error" variant="tonal">
              {{ detailError }}
            </VAlert>
          </VCardText>

          <template v-else-if="detailPurchase">
            <VCardText>
              <div class="d-flex flex-wrap gap-2 mb-4">
                <VChip
                  size="small"
                  :color="stateColor(detailPurchase.state)"
                  variant="tonal"
                >
                  {{ stateLabel(detailPurchase.state) }}
                </VChip>
              </div>
              <VRow dense class="mb-4">
                <VCol cols="12" sm="6">
                  <div class="text-caption text-medium-emphasis">
                    Proveedor
                  </div>
                  <div>{{ detailPurchase.supplier?.name || '—' }}</div>
                </VCol>
                <VCol cols="12" sm="6">
                  <div class="text-caption text-medium-emphasis">
                    Solicitante
                  </div>
                  <div>{{ detailPurchase.solicitante?.name || detailPurchase.user?.name || '—' }}</div>
                </VCol>
                <VCol cols="12" sm="6">
                  <div class="text-caption text-medium-emphasis">
                    Almacén
                  </div>
                  <div>{{ detailPurchase.warehouse?.name || '—' }}</div>
                </VCol>
                <VCol
                  v-if="detailPurchase.notes"
                  cols="12"
                >
                  <div class="text-caption text-medium-emphasis">
                    Notas / aclaraciones
                  </div>
                  <div class="text-body-2">
                    {{ detailPurchase.notes }}
                  </div>
                </VCol>
                <VCol cols="12" sm="4">
                  <div class="text-caption text-medium-emphasis">
                    Importe
                  </div>
                  <div>{{ formatMoney(detailPurchase.importe) }}</div>
                </VCol>
                <VCol cols="12" sm="4">
                  <div class="text-caption text-medium-emphasis">
                    IGV
                  </div>
                  <div>{{ formatMoney(detailPurchase.igv) }}</div>
                </VCol>
                <VCol cols="12" sm="4">
                  <div class="text-caption text-medium-emphasis">
                    Total
                  </div>
                  <div class="font-weight-medium">
                    {{ formatMoney(detailPurchase.total) }}
                  </div>
                </VCol>
              </VRow>

              <div class="text-subtitle-2 mb-2">
                Líneas
              </div>
              <VTable density="compact" class="border rounded">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th class="text-end">
                      Cant.
                    </th>
                    <th class="text-end">
                      P. unit.
                    </th>
                    <th class="text-end">
                      Subtotal
                    </th>
                    <th>Estado</th>
                    <th v-if="canManage" class="text-end">
                      Acciones
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="line in (detailPurchase.items || [])"
                    :key="line.id"
                  >
                    <td>
                      {{ line.product?.name || '—' }}
                      <div class="text-caption text-medium-emphasis">
                        {{ line.description || '' }}
                      </div>
                    </td>
                    <td class="text-end">
                      {{ line.quantity }}
                    </td>
                    <td class="text-end">
                      {{ formatMoney(line.price_unit) }}
                    </td>
                    <td class="text-end">
                      {{ formatMoney(line.line_total) }}
                    </td>
                    <td>
                      <VChip
                        size="x-small"
                        :color="line.state === 'entregado' ? 'success' : 'secondary'"
                        variant="tonal"
                      >
                        {{ line.state === 'entregado' ? 'Entregado' : 'Solicitud' }}
                      </VChip>
                    </td>
                    <td v-if="canManage" class="text-end">
                      <VBtn
                        v-if="line.state === 'solicitud'"
                        size="x-small"
                        color="primary"
                        variant="tonal"
                        :loading="lineUpdatingId === line.id"
                        @click="markLineDelivered(detailPurchase.id, line)"
                      >
                        Recibir
                      </VBtn>
                      <VBtn
                        v-else
                        size="x-small"
                        variant="text"
                        :loading="lineUpdatingId === line.id"
                        @click="revertLine(detailPurchase.id, line)"
                      >
                        Revertir
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </template>
        </VCard>
      </VDialog>
    </div>
  </div>
</template>
