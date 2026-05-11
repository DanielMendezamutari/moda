<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'transportes',
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
const transports = ref([])

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
  { value: 'list', title: 'Listado completo', caption: 'Tabla con los mismos filtros' },
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
  { value: 'revision_salida', title: 'Revisión salida' },
  { value: 'salida', title: 'Salida' },
  { value: 'llegada', title: 'Llegada' },
  { value: 'revision_llegada', title: 'Revisión llegada' },
  { value: 'entrega', title: 'Entrega' },
]

const headers = [
  { title: 'ID', key: 'id', width: '72px' },
  { title: 'Ruta / Ref.', key: 'route', sortable: false },
  { title: 'Importe', key: 'importe', sortable: false },
  { title: 'Total', key: 'total', sortable: false },
  { title: 'Estado', key: 'state', sortable: false },
  { title: 'Líneas', key: 'items_count', sortable: false },
  { title: 'Registro', key: 'created_at', sortable: false },
  { title: 'Acciones', key: 'actions', align: 'end', width: '120px' },
]

function stateLabel(v) {
  return STATE_OPTIONS.find(s => s.value === v)?.title ?? v ?? '—'
}

function stateColor(v) {
  if (v === 'entrega')
    return 'success'
  if (v === 'llegada' || v === 'revision_llegada')
    return 'warning'
  if (v === 'salida' || v === 'revision_salida')
    return 'info'

  return 'secondary'
}

function lineStateLabel(v) {
  const m = { solicitud: 'Solicitud', salida: 'Salida', entrega: 'Entrega' }

  return m[v] ?? v ?? '—'
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

async function fetchTransports() {
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

    const res = await $api(`/transports?${q.toString()}`)

    transports.value = Array.isArray(res?.data) ? res.data : []
    lastPage.value = Number(res?.last_page) || 1
    total.value = Number(res?.total) ?? transports.value.length
  }
  catch (e) {
    transports.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver transportes.',
      fallback: 'No se pudieron cargar los transportes.',
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
    fetchTransports()
  }, 380)
}

watch(page, () => {
  fetchTransports()
})

watch(stateFilter, () => {
  page.value = 1
  fetchTransports()
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

async function exportTransportList(format) {
  exportBusy.value = true
  exportBusyKind.value = format
  try {
    const params = exportQueryParams()
    params.set('format', format)

    const res = await $apiRaw(`transports/export?${params.toString()}`, {
      responseType: 'blob',
    })

    const blob = blobFromOfetchRawResponse(res)
    if (!blob) {
      snackbar.text = 'No se pudo leer el archivo de exportación.'
      snackbar.show = true

      return
    }

    const dispo = res.headers.get('Content-Disposition') || ''
    let filename = `transportes.${format}`
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

async function downloadTransportReportPdf(variant) {
  exportBusy.value = true
  exportBusyKind.value = `pdf:${variant}`
  try {
    const params = exportQueryParams()
    params.set('variant', variant)

    const res = await $apiRaw(`transports/export/pdf?${params.toString()}`, {
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
      snackbar.text = 'No se pudo abrir la pestaña. Permití ventanas emergentes.'
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

async function openTransportPdf(id) {
  const nid = Number(id)
  if (!Number.isFinite(nid) || nid <= 0)
    return

  try {
    const raw = await $apiRaw(`/transports/${nid}/pdf`, { responseType: 'blob' })
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
    snackbar.text = 'No se pudo abrir el PDF del transporte.'
    snackbar.show = true
  }
}

const detailOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detailTransport = ref(null)
const lineUpdatingId = ref(null)

async function openDetail(row) {
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = ''
  detailTransport.value = null

  try {
    const res = await $api(`/transports/${row.id}`)
    detailTransport.value = res?.data ?? null
  }
  catch (e) {
    detailError.value = messageFromApiError(e, {
      forbidden: 'No podés ver este transporte.',
      fallback: 'No se pudo cargar el detalle.',
    })
  }
  finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  detailOpen.value = false
  detailTransport.value = null
}

async function patchLineState(transportId, line, state) {
  if (!line?.id)
    return

  lineUpdatingId.value = line.id
  try {
    await $api(`/transports/${transportId}/details/${line.id}`, {
      method: 'PATCH',
      body: { state },
    })
    snackbar.text = 'Línea actualizada.'
    snackbar.show = true
    await openDetail({ id: transportId })
    await fetchTransports()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, { fallback: 'No se pudo actualizar la línea.' })
    snackbar.show = true
  }
  finally {
    lineUpdatingId.value = null
  }
}

onMounted(async () => {
  await authStore.fetchMe(true)
  if (canView.value)
    await fetchTransports()
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
        size="48"
      />
    </div>

    <VCard v-else-if="!canView">
      <VCardText class="py-12 text-center">
        <VAvatar
          color="info"
          variant="tonal"
          size="72"
          class="mb-4"
        >
          <VIcon
            icon="ri-truck-line"
            size="40"
          />
        </VAvatar>
        <h2 class="text-h5 mb-2">
          Sin acceso
        </h2>
        <p class="text-body-2 text-medium-emphasis">
          Solo administradores gestionan transportes entre almacenes.
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
              color="info"
              variant="tonal"
              size="48"
              rounded="lg"
              class="mt-1"
            >
              <VIcon
                icon="ri-truck-line"
                size="26"
              />
            </VAvatar>
            <div>
              <VCardTitle class="text-h5 pa-0 pb-1">
                Transporte / traslados
              </VCardTitle>
              <VCardSubtitle class="pa-0">
                Movimiento de stock entre almacenes. <strong>Salida</strong> descuenta origen; <strong>Entrega</strong> suma en destino.
              </VCardSubtitle>
            </div>
          </div>
          <VBtn
            v-if="canManage"
            color="primary"
            prepend-icon="ri-add-line"
            :to="{ name: 'transportes-registrar' }"
          >
            Registrar traslado
          </VBtn>
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
              style="max-width: 220px;"
            />
            <div class="text-body-2 text-medium-emphasis d-flex align-center gap-1">
              <VIcon
                icon="ri-file-list-3-line"
                size="18"
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
                @click="exportTransportList(f.value)"
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
                    color="info"
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
                    :disabled="exportBusy || loading"
                    @click="downloadTransportReportPdf(v.value)"
                  >
                    <template #append>
                      <VProgressCircular
                        v-if="isPdfVariantBusy(v.value)"
                        indeterminate
                        size="18"
                        width="2"
                        color="info"
                      />
                    </template>
                  </VListItem>
                </VList>
              </VMenu>
            </div>
          </div>

          <VDataTable
            :headers="headers"
            :items="transports"
            :loading="loading"
            hide-default-footer
            class="elevation-0"
          >
            <template #item.route="{ item }">
              <div class="font-weight-medium">
                {{ item.warehouse_start?.name || '—' }}
                <VIcon
                  icon="ri-arrow-right-line"
                  size="14"
                  class="mx-1 text-medium-emphasis"
                />
                {{ item.warehouse_end?.name || '—' }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ item.reference || `Traslado #${item.id}` }}
              </div>
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
                  color="info"
                  @click="openTransportPdf(item.id)"
                >
                  <VIcon icon="ri-file-pdf-line" />
                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    PDF
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
        max-width="920"
        scrollable
        @update:model-value="v => !v && closeDetail()"
      >
        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="d-flex align-center gap-2">
              <VIcon
                icon="ri-file-list-3-line"
                class="text-info"
              />
              Detalle de traslado
            </span>
            <div class="d-flex align-center gap-1">
              <VBtn
                v-if="detailTransport?.id"
                size="small"
                variant="tonal"
                color="info"
                prepend-icon="ri-file-pdf-line"
                @click="openTransportPdf(detailTransport.id)"
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

          <VCardText
            v-if="detailLoading"
            class="py-10 text-center"
          >
            <VProgressCircular
              indeterminate
              color="primary"
            />
          </VCardText>

          <VCardText v-else-if="detailError">
            <VAlert
              type="error"
              variant="tonal"
            >
              {{ detailError }}
            </VAlert>
          </VCardText>

          <template v-else-if="detailTransport">
            <VCardText>
              <div class="d-flex flex-wrap gap-2 mb-4">
                <VChip
                  size="small"
                  :color="stateColor(detailTransport.state)"
                  variant="tonal"
                >
                  {{ stateLabel(detailTransport.state) }}
                </VChip>
              </div>
              <VRow
                dense
                class="mb-4"
              >
                <VCol
                  cols="12"
                  sm="6"
                >
                  <div class="text-caption text-medium-emphasis">
                    Origen
                  </div>
                  <div>{{ detailTransport.warehouse_start?.name || '—' }}</div>
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <div class="text-caption text-medium-emphasis">
                    Destino
                  </div>
                  <div>{{ detailTransport.warehouse_end?.name || '—' }}</div>
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <div class="text-caption text-medium-emphasis">
                    Registró
                  </div>
                  <div>{{ detailTransport.user?.name || '—' }}</div>
                </VCol>
                <VCol
                  v-if="detailTransport.description"
                  cols="12"
                >
                  <div class="text-caption text-medium-emphasis">
                    Descripción
                  </div>
                  <div>{{ detailTransport.description }}</div>
                </VCol>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    Importe
                  </div>
                  <div>{{ formatMoney(detailTransport.importe) }}</div>
                </VCol>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    IGV
                  </div>
                  <div>{{ formatMoney(detailTransport.igv) }}</div>
                </VCol>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    Total
                  </div>
                  <div class="font-weight-medium">
                    {{ formatMoney(detailTransport.total) }}
                  </div>
                </VCol>
              </VRow>

              <div class="text-subtitle-2 mb-2">
                Líneas
              </div>
              <VTable
                density="compact"
                class="border rounded"
              >
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
                    <th
                      v-if="canManage"
                      class="text-end"
                    >
                      Acciones
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="line in (detailTransport.items || [])"
                    :key="line.id"
                  >
                    <td>
                      {{ line.product?.name || '—' }}
                      <div
                        v-if="line.description"
                        class="text-caption text-medium-emphasis"
                      >
                        {{ line.description }}
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
                        color="secondary"
                        variant="tonal"
                      >
                        {{ lineStateLabel(line.state) }}
                      </VChip>
                    </td>
                    <td
                      v-if="canManage"
                      class="text-end"
                    >
                      <VBtn
                        v-if="line.state === 'solicitud'"
                        size="x-small"
                        color="info"
                        variant="tonal"
                        :loading="lineUpdatingId === line.id"
                        @click="patchLineState(detailTransport.id, line, 'salida')"
                      >
                        Salida
                      </VBtn>
                      <template v-else-if="line.state === 'salida'">
                        <VBtn
                          size="x-small"
                          color="success"
                          variant="tonal"
                          class="me-1"
                          :loading="lineUpdatingId === line.id"
                          @click="patchLineState(detailTransport.id, line, 'entrega')"
                        >
                          Entrega
                        </VBtn>
                        <VBtn
                          size="x-small"
                          variant="text"
                          :loading="lineUpdatingId === line.id"
                          @click="patchLineState(detailTransport.id, line, 'solicitud')"
                        >
                          Revertir
                        </VBtn>
                      </template>
                      <VBtn
                        v-else-if="line.state === 'entrega'"
                        size="x-small"
                        variant="text"
                        :loading="lineUpdatingId === line.id"
                        @click="patchLineState(detailTransport.id, line, 'salida')"
                      >
                        Revertir entrega
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
