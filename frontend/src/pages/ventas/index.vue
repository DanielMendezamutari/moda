<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'ventas',
  },
})

const authStore = useAuthStore()

const canView = computed(() => authStore.canPosSaleView)
const canCreate = computed(() => authStore.canPosSaleCreate)
const canManage = computed(() => authStore.isAdmin)

const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const errorMsg = ref('')
const sales = ref([])

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
  { value: 'list', title: 'Listado completo', caption: 'Tabla de ventas con el mismo buscador' },
  { value: 'summary', title: 'Resumen', caption: 'Totales y conteos por estado' },
]

const cancelOpen = ref(false)
const cancelSubmitting = ref(false)
const cancelTarget = ref(null)

function isExportBusy(kind) {
  return exportBusy.value && exportBusyKind.value === kind
}

function isPdfVariantBusy(variant) {
  return exportBusy.value && exportBusyKind.value === `pdf:${variant}`
}

const detailOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detailSale = ref(null)

const paymentSubmitting = ref(false)
const paymentError = ref('')
const paymentForm = reactive({
  method_payment: 'efectivo',
  amount: '',
  n_transaction: '',
})

const headers = [
  { title: 'Referencia', key: 'reference', sortable: false, minWidth: '120px' },
  { title: 'Cliente', key: 'client', sortable: false, minWidth: '160px' },
  { title: 'Total', key: 'total', sortable: false },
  { title: 'Estado venta', key: 'state_sale', sortable: false },
  { title: 'Estado pago', key: 'state_payment', sortable: false },
  { title: 'Deuda', key: 'debt', sortable: false },
  { title: 'Registro', key: 'created_at', sortable: false },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: '120px' },
]

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

function stateSaleLabel(v) {
  const m = { validated: 'Validada', draft: 'Borrador', cancelled: 'Anulada' }

  return m[v] ?? v ?? '—'
}

function stateSaleColor(v) {
  if (v === 'draft')
    return 'secondary'
  if (v === 'cancelled')
    return 'error'

  return 'success'
}

function statePaymentLabel(v) {
  const m = { paid: 'Pagado', partial: 'Parcial', pending: 'Pendiente' }

  return m[v] ?? v ?? '—'
}

function statePaymentColor(v) {
  if (v === 'paid')
    return 'success'
  if (v === 'partial')
    return 'warning'

  return 'secondary'
}

let searchTimer = null

async function fetchSales() {
  errorMsg.value = ''
  loading.value = true

  try {
    const q = new URLSearchParams()
    q.set('page', String(page.value))
    const s = search.value.trim()
    if (s)
      q.set('search', s)

    const res = await $api(`/sales?${q.toString()}`)

    sales.value = Array.isArray(res?.data) ? res.data : []
    lastPage.value = Number(res?.last_page) || 1
    total.value = Number(res?.total) ?? sales.value.length
  }
  catch (e) {
    sales.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver ventas.',
      fallback: 'No se pudieron cargar las ventas.',
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
    fetchSales()
  }, 380)
}

watch(page, () => {
  fetchSales()
})

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

async function exportSaleList(format) {
  exportBusy.value = true
  exportBusyKind.value = format
  try {
    const params = new URLSearchParams()
    params.set('format', format)
    const q = search.value.trim()
    if (q)
      params.set('search', q)

    const res = await $apiRaw(`sales/export?${params.toString()}`, {
      responseType: 'blob',
    })

    const blob = blobFromOfetchRawResponse(res)
    if (!blob) {
      snackbar.text = 'No se pudo leer el archivo de exportación.'
      snackbar.show = true

      return
    }

    const dispo = res.headers.get('Content-Disposition') || ''
    let filename = `ventas.${format}`
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

async function downloadSaleReportPdf(variant) {
  exportBusy.value = true
  exportBusyKind.value = `pdf:${variant}`
  try {
    const params = new URLSearchParams()
    params.set('variant', variant)
    const q = search.value.trim()
    if (q)
      params.set('search', q)
    const res = await $apiRaw(`sales/export/pdf?${params.toString()}`, {
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

async function openSaleTicketPdf(saleId) {
  const id = Number(saleId)
  if (!Number.isFinite(id) || id <= 0)
    return

  try {
    const raw = await $apiRaw(`/sales/${id}/ticket`, { responseType: 'blob' })
    const blob = blobFromOfetchRawResponse(raw)
    if (!blob?.size)
      return

    const url = URL.createObjectURL(blob)
    const win = window.open(url, '_blank', 'noopener,noreferrer')
    if (!win) {
      snackbar.text = 'Permití ventanas emergentes para ver el ticket.'
      snackbar.show = true
    }
    setTimeout(() => URL.revokeObjectURL(url), 90_000)
  }
  catch {
    snackbar.text = 'No se pudo abrir el ticket de venta.'
    snackbar.show = true
  }
}

function openCancelDialog(row) {
  cancelTarget.value = row
  cancelOpen.value = true
}

function closeCancelDialog() {
  cancelOpen.value = false
  cancelTarget.value = null
}

async function confirmCancelSale() {
  const row = cancelTarget.value
  if (!row?.id)
    return

  cancelSubmitting.value = true
  try {
    await $api(`/sales/${row.id}`, { method: 'DELETE' })
    snackbar.text = row.state_sale === 'validated'
      ? 'Venta anulada; stock y movimientos actualizados.'
      : 'Registro eliminado.'
    snackbar.show = true
    closeCancelDialog()
    if (detailOpen.value && detailSale.value?.id === row.id)
      closeDetail()
    await fetchSales()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      forbidden: 'No tenés permiso para anular o eliminar ventas.',
      fallback: 'No se pudo completar la operación.',
    })
    snackbar.show = true
  }
  finally {
    cancelSubmitting.value = false
  }
}

function closeDetail() {
  detailOpen.value = false
  detailSale.value = null
  detailError.value = ''
  paymentError.value = ''
  paymentForm.method_payment = 'efectivo'
  paymentForm.amount = ''
  paymentForm.n_transaction = ''
}

async function openDetail(row) {
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = ''
  detailSale.value = null
  paymentError.value = ''

  try {
    const res = await $api(`/sales/${row.id}`)
    detailSale.value = res?.data ?? null
    const debt = Number(detailSale.value?.debt)
    if (Number.isFinite(debt) && debt > 0)
      paymentForm.amount = String(debt.toFixed(2))
    else
      paymentForm.amount = ''
  }
  catch (e) {
    detailSale.value = null
    detailError.value = messageFromApiError(e, {
      forbidden: 'No podés ver esta venta.',
      fallback: 'No se pudo cargar el detalle.',
    })
  }
  finally {
    detailLoading.value = false
  }
}

async function submitPayment() {
  if (!detailSale.value?.id)
    return

  paymentError.value = ''
  const amt = Number(String(paymentForm.amount || '').replace(',', '.'))
  if (!Number.isFinite(amt) || amt <= 0) {
    paymentError.value = 'Indicá un monto válido.'

    return
  }

  paymentSubmitting.value = true
  try {
    await $api(`/sales/${detailSale.value.id}/payments`, {
      method: 'POST',
      body: {
        method_payment: String(paymentForm.method_payment || '').trim() || 'efectivo',
        amount: amt,
        n_transaction: String(paymentForm.n_transaction || '').trim() || null,
      },
    })
    snackbar.text = 'Pago registrado.'
    snackbar.show = true
    await openDetail({ id: detailSale.value.id })
    await fetchSales()
  }
  catch (e) {
    paymentError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para registrar pagos.',
      fallback: 'No se pudo registrar el pago.',
    })
  }
  finally {
    paymentSubmitting.value = false
  }
}

const detailDebt = computed(() => {
  const d = Number(detailSale.value?.debt)
  return Number.isFinite(d) ? d : 0
})

onMounted(async () => {
  await authStore.fetchMe(true)
  if (canView.value)
    await fetchSales()
})
</script>

<template>
  <div>
    <div
      v-if="authStore.loading"
      class="d-flex justify-center align-center py-16"
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
          color="warning"
          variant="tonal"
          size="72"
          class="mb-4"
        >
          <VIcon
            icon="ri-shield-cross-line"
            size="40"
          />
        </VAvatar>
        <h2 class="text-h5 mb-2">
          Sin acceso al listado de ventas
        </h2>
        <p class="text-body-2 text-medium-emphasis mx-auto mb-6" style="max-width: 420px;">
          Tu cuenta no tiene permiso para ver ventas (POS). Si deberías verlas, cerrá sesión y volvé a entrar o pedí acceso a un administrador.
        </p>
      </VCardText>
    </VCard>

    <div
      v-else
      class="pa-2 pa-md-4"
    >
      <VCard>
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 pb-2">
          <div>
            <VCardTitle class="text-h5 pa-0 pb-1">
              Ventas
            </VCardTitle>
            <VCardSubtitle class="pa-0">
              Documentos en <code>sales</code>; líneas en <code>sale_details</code>; pagos en <code>sale_payments</code>.
            </VCardSubtitle>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <VBtn
              v-if="canCreate"
              color="primary"
              prepend-icon="ri-add-line"
              :to="{ name: 'ventas-registrar' }"
            >
              Registrar venta
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
            <div class="text-body-2 text-medium-emphasis d-flex align-center">
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
                @click="exportSaleList(f.value)"
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
                    color="teal"
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
                    Informes (mismo buscador)
                  </VListSubheader>
                  <VListItem
                    v-for="v in pdfVariants"
                    :key="v.value"
                    :title="v.title"
                    :subtitle="v.caption"
                    prepend-icon="ri-file-pdf-line"
                    :active="false"
                    :disabled="exportBusy || loading"
                    @click="downloadSaleReportPdf(v.value)"
                  >
                    <template #append>
                      <VProgressCircular
                        v-if="isPdfVariantBusy(v.value)"
                        indeterminate
                        size="18"
                        width="2"
                        color="teal"
                      />
                    </template>
                  </VListItem>
                </VList>
              </VMenu>
            </div>
          </div>

          <VDataTable
            :headers="headers"
            :items="sales"
            :loading="loading"
            hide-default-footer
            class="elevation-0"
          >
            <template #item.reference="{ item }">
              <span class="font-weight-medium">{{ item.reference || `#${item.id}` }}</span>
            </template>

            <template #item.client="{ item }">
              <span v-if="item.client">{{ item.client.full_name }}</span>
              <span
                v-else
                class="text-medium-emphasis"
              >Mostrador</span>
            </template>

            <template #item.total="{ item }">
              {{ formatMoney(item.total) }}
            </template>

            <template #item.state_sale="{ item }">
              <VChip
                size="small"
                :color="stateSaleColor(item.state_sale)"
                variant="tonal"
              >
                {{ stateSaleLabel(item.state_sale) }}
              </VChip>
            </template>

            <template #item.state_payment="{ item }">
              <VChip
                size="small"
                :color="statePaymentColor(item.state_payment)"
                variant="tonal"
              >
                {{ statePaymentLabel(item.state_payment) }}
              </VChip>
            </template>

            <template #item.debt="{ item }">
              {{ formatMoney(item.debt) }}
            </template>

            <template #item.created_at="{ item }">
              {{ formatDate(item.created_at) }}
            </template>

            <template #item.actions="{ item }">
              <div class="d-inline-flex flex-wrap justify-end gap-1">
                <VBtn
                  size="small"
                  variant="tonal"
                  icon
                  @click="openDetail(item)"
                >
                  <VIcon icon="ri-eye-line" />
                  <VTooltip
                    activator="parent"
                    location="bottom"
                  >
                    Ver detalle
                  </VTooltip>
                </VBtn>
                <VBtn
                  size="small"
                  variant="tonal"
                  color="teal"
                  icon
                  @click="openSaleTicketPdf(item.id)"
                >
                  <VIcon icon="ri-printer-line" />
                  <VTooltip
                    activator="parent"
                    location="bottom"
                  >
                    Ticket / PDF
                  </VTooltip>
                </VBtn>
                <VBtn
                  v-if="canManage && item.state_sale === 'validated'"
                  size="small"
                  variant="tonal"
                  color="error"
                  icon
                  @click="openCancelDialog(item)"
                >
                  <VIcon icon="ri-forbid-line" />
                  <VTooltip
                    activator="parent"
                    location="bottom"
                  >
                    Anular venta
                  </VTooltip>
                </VBtn>
                <VBtn
                  v-if="canManage && item.state_sale === 'draft'"
                  size="small"
                  variant="tonal"
                  color="error"
                  icon
                  @click="openCancelDialog(item)"
                >
                  <VIcon icon="ri-delete-bin-line" />
                  <VTooltip
                    activator="parent"
                    location="bottom"
                  >
                    Eliminar borrador
                  </VTooltip>
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
        v-model="cancelOpen"
        max-width="480"
        @update:model-value="v => !v && closeCancelDialog()"
      >
        <VCard>
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon
              :icon="cancelTarget?.state_sale === 'draft' ? 'ri-delete-bin-line' : 'ri-forbid-line'"
              color="error"
            />
            {{ cancelTarget?.state_sale === 'draft' ? 'Eliminar borrador' : 'Anular venta' }}
          </VCardTitle>
          <VDivider />
          <VCardText class="text-body-2">
            <template v-if="cancelTarget?.state_sale === 'validated'">
              Se devolverá el stock a los almacenes, se quitarán los cobros registrados en caja de este turno (si aplica) y se revertirá el crédito en cuenta del cliente. Los informes dejarán de contar esta venta.
              No podés anular si el turno de caja ya fue cerrado.
            </template>
            <template v-else-if="cancelTarget?.state_sale === 'draft'">
              Se eliminará este borrador de forma permanente.
            </template>
          </VCardText>
          <VCardActions class="justify-end">
            <VBtn
              variant="text"
              @click="closeCancelDialog"
            >
              Cancelar
            </VBtn>
            <VBtn
              color="error"
              variant="flat"
              :loading="cancelSubmitting"
              @click="confirmCancelSale"
            >
              Confirmar
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <VDialog
        v-model="detailOpen"
        max-width="720"
        scrollable
        @update:model-value="v => !v && closeDetail()"
      >
        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span>Detalle de venta</span>
            <div class="d-flex align-center gap-1">
              <VBtn
                v-if="detailSale?.id"
                size="small"
                variant="tonal"
                color="teal"
                prepend-icon="ri-printer-line"
                @click="openSaleTicketPdf(detailSale.id)"
              >
                Ticket
              </VBtn>
              <VBtn
                v-if="canManage && detailSale?.state_sale === 'validated'"
                size="small"
                variant="tonal"
                color="error"
                prepend-icon="ri-forbid-line"
                @click="openCancelDialog(detailSale)"
              >
                Anular
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
            <VAlert
              type="error"
              variant="tonal"
              density="comfortable"
            >
              {{ detailError }}
            </VAlert>
          </VCardText>

          <template v-else-if="detailSale">
            <VCardText>
              <div class="d-flex flex-wrap gap-2 mb-4">
                <VChip
                  size="small"
                  :color="stateSaleColor(detailSale.state_sale)"
                  variant="tonal"
                >
                  {{ stateSaleLabel(detailSale.state_sale) }}
                </VChip>
                <VChip
                  size="small"
                  :color="statePaymentColor(detailSale.state_payment)"
                  variant="tonal"
                >
                  {{ statePaymentLabel(detailSale.state_payment) }}
                </VChip>
              </div>

              <VRow dense class="mb-4">
                <VCol cols="12" sm="6">
                  <div class="text-caption text-medium-emphasis">
                    Referencia
                  </div>
                  <div>{{ detailSale.reference || `— (#${detailSale.id})` }}</div>
                </VCol>
                <VCol cols="12" sm="6">
                  <div class="text-caption text-medium-emphasis">
                    Cliente
                  </div>
                  <div>{{ detailSale.client?.full_name || 'Mostrador' }}</div>
                </VCol>
                <VCol cols="12" sm="4">
                  <div class="text-caption text-medium-emphasis">
                    Subtotal
                  </div>
                  <div>{{ formatMoney(detailSale.subtotal) }}</div>
                </VCol>
                <VCol cols="12" sm="4">
                  <div class="text-caption text-medium-emphasis">
                    IGV
                  </div>
                  <div>{{ formatMoney(detailSale.igv) }}</div>
                </VCol>
                <VCol cols="12" sm="4">
                  <div class="text-caption text-medium-emphasis">
                    Total
                  </div>
                  <div class="font-weight-medium">{{ formatMoney(detailSale.total) }}</div>
                </VCol>
                <VCol cols="12">
                  <div class="text-caption text-medium-emphasis">
                    Notas
                  </div>
                  <div>{{ detailSale.description || '—' }}</div>
                </VCol>
              </VRow>

              <div class="text-subtitle-2 mb-2">
                Líneas (sale_details)
              </div>
              <VTable density="compact" class="border rounded mb-6">
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
                      Total línea
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(line, idx) in (detailSale.items || [])"
                    :key="line.id || idx"
                  >
                    <td>
                      <div>{{ line.product?.name || '—' }}</div>
                      <div class="text-caption text-medium-emphasis">
                        {{ line.warehouse?.name || '' }}
                      </div>
                    </td>
                    <td class="text-end">
                      {{ line.quantity }}
                    </td>
                    <td class="text-end">
                      {{ formatMoney(line.unit_price) }}
                    </td>
                    <td class="text-end">
                      {{ formatMoney(line.line_total) }}
                    </td>
                  </tr>
                </tbody>
              </VTable>

              <div class="text-subtitle-2 mb-2">
                Pagos
              </div>
              <div
                v-if="!(detailSale.payments || []).length"
                class="text-body-2 text-medium-emphasis mb-4"
              >
                Sin pagos registrados.
              </div>
              <VTable
                v-else
                density="compact"
                class="border rounded mb-4"
              >
                <thead>
                  <tr>
                    <th>Medio</th>
                    <th class="text-end">
                      Monto
                    </th>
                    <th>Ref.</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="p in detailSale.payments"
                    :key="p.id"
                  >
                    <td>{{ p.method_payment }}</td>
                    <td class="text-end">
                      {{ formatMoney(p.amount) }}
                    </td>
                    <td>{{ p.n_transaction || '—' }}</td>
                  </tr>
                </tbody>
              </VTable>

              <VAlert
                v-if="detailSale?.state_sale === 'cancelled'"
                type="warning"
                variant="tonal"
                density="comfortable"
                class="mb-4"
              >
                Esta venta está anulada; no se pueden registrar pagos adicionales.
              </VAlert>

              <VAlert
                v-else-if="canCreate && detailDebt > 0.01"
                type="info"
                variant="tonal"
                density="comfortable"
                class="mb-4"
              >
                Saldo pendiente: <strong>{{ formatMoney(detailDebt) }}</strong>
              </VAlert>

              <VForm
                v-if="canCreate && detailDebt > 0.01 && detailSale?.state_sale !== 'cancelled'"
                @submit.prevent="submitPayment"
              >
                <div class="text-subtitle-2 mb-2">
                  Registrar pago
                </div>
                <VAlert
                  v-if="paymentError"
                  type="error"
                  variant="tonal"
                  density="compact"
                  class="mb-3"
                >
                  {{ paymentError }}
                </VAlert>
                <VRow dense>
                  <VCol cols="12" sm="4">
                    <VTextField
                      v-model="paymentForm.method_payment"
                      label="Medio"
                      density="comfortable"
                      :disabled="paymentSubmitting"
                    />
                  </VCol>
                  <VCol cols="12" sm="4">
                    <VTextField
                      v-model="paymentForm.amount"
                      label="Monto"
                      density="comfortable"
                      :disabled="paymentSubmitting"
                    />
                  </VCol>
                  <VCol cols="12" sm="4">
                    <VTextField
                      v-model="paymentForm.n_transaction"
                      label="Nº ref."
                      density="comfortable"
                      :disabled="paymentSubmitting"
                    />
                  </VCol>
                  <VCol cols="12">
                    <VBtn
                      type="submit"
                      color="primary"
                      :loading="paymentSubmitting"
                      prepend-icon="ri-bank-card-line"
                    >
                      Aplicar pago
                    </VBtn>
                  </VCol>
                </VRow>
              </VForm>
            </VCardText>
          </template>
        </VCard>
      </VDialog>
    </div>
  </div>
</template>
