<script setup>
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'clientes',
  },
})

const authStore = useAuthStore()

const canView = computed(() =>
  authStore.isAdmin || authStore.hasRole('cashier'),
)

const canManage = computed(() => authStore.isAdmin)

const search = ref('')
const loading = ref(false)
const errorMsg = ref('')
const clients = ref([])
const branches = ref([])

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
  { value: 'full', title: 'Informe ejecutivo', caption: 'KPIs, deudores por monto, top y riesgo' },
  { value: 'list', title: 'Listado completo', caption: 'Todos los clientes en tabla' },
  { value: 'debtors', title: 'Deudores por tramo', caption: 'Segmentado por saldo adeudado' },
  { value: 'credit-risk', title: 'Cartera y % límite', caption: 'Uso de línea y alertas' },
]

function isExportBusy(kind) {
  return exportBusy.value && exportBusyKind.value === kind
}

function isPdfVariantBusy(variant) {
  return exportBusy.value && exportBusyKind.value === `pdf:${variant}`
}

function emptyForm() {
  return {
    name: '',
    surname: '',
    phone: '',
    email: '',
    type_client: 'natural',
    type_document: 'CI',
    n_document: '',
    birthdate: '',
    branch_id: null,
    is_active: true,
    gender: null,
    ubigeo: '',
    address: '',
    credit_enabled: false,
    credit_limit: '',
  }
}

const createOpen = ref(false)
const createSubmitting = ref(false)
const createError = ref('')
const createForm = reactive(emptyForm())

const editOpen = ref(false)
const editSubmitting = ref(false)
const editError = ref('')
const editForm = reactive({ id: null, ...emptyForm() })

const deleteOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref(null)
const deleteStatusLoading = ref(false)
/** @type {import('vue').Ref<null | { can_delete: boolean, blockers: Array<{ code: string, message: string }>, metrics: Record<string, unknown> }>} */
const deleteStatus = ref(null)

const creditOpen = ref(false)
const creditClient = ref(null)
const creditMovements = ref([])
const creditMovementsLoading = ref(false)
const creditTab = ref('movements')
const creditChargeForm = reactive({ amount: '', description: '' })
const creditPayForm = reactive({ amount: '', description: '' })
const creditAdjForm = reactive({ amount: '', description: '' })
const creditActionLoading = ref(false)
const creditChargeError = ref('')
const creditPayError = ref('')
const creditAdjError = ref('')

const typeClientItems = [
  { title: 'Persona natural', value: 'natural' },
  { title: 'Persona jurídica', value: 'juridico' },
]

const typeDocItems = [
  { title: 'CI', value: 'CI' },
  { title: 'NIT', value: 'NIT' },
  { title: 'CE / Extranjero', value: 'CE' },
  { title: 'Pasaporte', value: 'PASAPORTE' },
  { title: 'Otro', value: 'OTRO' },
]

const genderItems = [
  { title: '—', value: null },
  { title: 'Mujer', value: 'F' },
  { title: 'Hombre', value: 'M' },
  { title: 'Otro', value: 'otro' },
]

const headers = [
  { title: 'Cliente', key: 'full_name', sortable: true },
  { title: 'Documento', key: 'n_document', sortable: true },
  { title: 'Sucursal', key: 'branch', sortable: false },
  { title: 'Teléfono', key: 'phone', sortable: false },
  { title: 'Crédito', key: 'credit', sortable: false },
  { title: 'Activo', key: 'is_active', sortable: false },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: 200 },
]

function opt(s) {
  const t = String(s || '').trim()

  return t ? t : null
}

function toPayload(form) {
  const p = {
    name: String(form.name || '').trim(),
    surname: opt(form.surname),
    phone: opt(form.phone),
    email: opt(form.email),
    type_client: form.type_client,
    type_document: form.type_document,
    n_document: String(form.n_document || '').trim(),
    birthdate: opt(form.birthdate),
    branch_id: form.branch_id != null ? Number(form.branch_id) : null,
    is_active: !!form.is_active,
    gender: form.gender || null,
    ubigeo: form.credit_enabled ? opt(form.ubigeo) : null,
    address: opt(form.address),
    credit_enabled: !!form.credit_enabled,
    credit_limit: (() => {
      const s = String(form.credit_limit ?? '').trim().replace(',', '.')
      if (s === '' || !form.credit_enabled)
        return null

      return Number(s)
    })(),
  }

  return p
}

async function fetchBranches() {
  if (!canManage.value)
    return
  try {
    const res = await $api('/branches')

    branches.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    branches.value = []
  }
}

async function fetchClients() {
  errorMsg.value = ''
  loading.value = true
  try {
    const res = await $api('/clients')

    clients.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    clients.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver clientes.',
      fallback: 'No se pudieron cargar los clientes.',
    })
  }
  finally {
    loading.value = false
  }
}

async function exportClientList(format) {
  exportBusy.value = true
  exportBusyKind.value = format
  try {
    const params = new URLSearchParams()
    params.set('format', format)
    const q = search.value.trim()
    if (q)
      params.set('search', q)

    const res = await $apiRaw(`clients/export?${params.toString()}`, {
      responseType: 'blob',
    })

    const blob = blobFromOfetchRawResponse(res)
    if (!blob) {
      snackbar.text = 'No se pudo leer el archivo de exportación.'
      snackbar.show = true

      return
    }

    const dispo = res.headers.get('Content-Disposition') || ''
    let filename = `clientes.${format}`
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

async function downloadClientReportPdf(variant) {
  exportBusy.value = true
  exportBusyKind.value = `pdf:${variant}`
  try {
    const params = new URLSearchParams()
    params.set('variant', variant)
    const q = search.value.trim()
    if (q)
      params.set('search', q)

    const res = await $apiRaw(`clients/export/pdf?${params.toString()}`, {
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

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return clients.value

  return clients.value.filter((row) => {
    const blob = [
      row.full_name,
      row.n_document,
      row.phone,
      row.email,
      row.branch?.name,
    ].filter(Boolean).join(' ').toLowerCase()

    return blob.includes(q)
  })
})

function formatMoney(v) {
  if (v == null || v === '')
    return '—'
  const n = Number(v)
  if (!Number.isFinite(n))
    return '—'

  return `${new Intl.NumberFormat('es', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)} Bs.`
}

function openCreate() {
  Object.assign(createForm, emptyForm())
  if (branches.value.length === 1)
    createForm.branch_id = branches.value[0].id
  createError.value = ''
  createOpen.value = true
}

async function submitCreate() {
  createError.value = ''
  const p = toPayload(createForm)
  if (!p.name || !p.n_document || !p.branch_id) {
    createError.value = 'Nombre, documento y sucursal son obligatorios.'

    return
  }
  createSubmitting.value = true
  try {
    await $api('/clients', { method: 'POST', body: p })
    snackbar.text = 'Cliente registrado.'
    snackbar.show = true
    createOpen.value = false
    await fetchClients()
  }
  catch (e) {
    createError.value = messageFromApiError(e, { fallback: 'No se pudo crear.' })
  }
  finally {
    createSubmitting.value = false
  }
}

function openEdit(row) {
  editForm.id = row.id
  editForm.name = row.name || ''
  editForm.surname = row.surname || ''
  editForm.phone = row.phone || ''
  editForm.email = row.email || ''
  editForm.type_client = row.type_client || 'natural'
  editForm.type_document = row.type_document || 'CI'
  editForm.n_document = row.n_document || ''
  editForm.birthdate = row.birthdate || ''
  editForm.branch_id = row.branch_id
  editForm.is_active = !!row.is_active
  editForm.gender = row.gender || null
  editForm.ubigeo = row.ubigeo || ''
  editForm.address = row.address || ''
  editForm.credit_enabled = !!row.credit_enabled
  editForm.credit_limit = row.credit_limit != null ? String(row.credit_limit) : ''
  editError.value = ''
  editOpen.value = true
}

async function submitEdit() {
  editError.value = ''
  const p = toPayload(editForm)
  if (!p.name || !p.n_document || !p.branch_id) {
    editError.value = 'Nombre, documento y sucursal son obligatorios.'

    return
  }
  editSubmitting.value = true
  try {
    await $api(`/clients/${editForm.id}`, { method: 'PATCH', body: p })
    snackbar.text = 'Cliente actualizado.'
    snackbar.show = true
    editOpen.value = false
    await fetchClients()
  }
  catch (e) {
    editError.value = messageFromApiError(e, { fallback: 'No se pudo guardar.' })
  }
  finally {
    editSubmitting.value = false
  }
}

async function openDelete(row) {
  deleteTarget.value = row
  deleteStatus.value = null
  deleteOpen.value = true
  deleteStatusLoading.value = true
  try {
    const res = await $api(`/clients/${row.id}/deletion-status`)

    deleteStatus.value = res?.data ?? null
  }
  catch {
    deleteStatus.value = {
      can_delete: false,
      blockers: [{ code: 'fetch', message: 'No se pudo cargar la validación. Reintentá o revisá tu conexión.' }],
      metrics: null,
    }
  }
  finally {
    deleteStatusLoading.value = false
  }
}

async function confirmDelete() {
  if (!deleteTarget.value)
    return
  deleteSubmitting.value = true
  try {
    await $api(`/clients/${deleteTarget.value.id}`, { method: 'DELETE' })
    snackbar.text = 'Cliente eliminado.'
    snackbar.show = true
    deleteOpen.value = false
    await fetchClients()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, { fallback: 'No se pudo eliminar.' })
    snackbar.show = true
  }
  finally {
    deleteSubmitting.value = false
  }
}

async function openCredit(row) {
  creditClient.value = row
  creditTab.value = 'movements'
  creditChargeForm.amount = ''
  creditChargeForm.description = ''
  creditPayForm.amount = ''
  creditPayForm.description = ''
  creditAdjForm.amount = ''
  creditAdjForm.description = ''
  creditChargeError.value = ''
  creditPayError.value = ''
  creditAdjError.value = ''
  creditOpen.value = true
  await loadCreditMovements()
}

async function loadCreditMovements() {
  if (!creditClient.value)
    return
  creditMovementsLoading.value = true
  try {
    const res = await $api(`/clients/${creditClient.value.id}/credit-movements?per_page=50`)

    creditMovements.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    creditMovements.value = []
  }
  finally {
    creditMovementsLoading.value = false
  }
}

async function refreshCreditClient() {
  await fetchClients()
  const id = creditClient.value?.id
  if (id)
    creditClient.value = clients.value.find(c => c.id === id) || creditClient.value
}

async function submitCreditCharge() {
  creditChargeError.value = ''
  creditActionLoading.value = true
  try {
    await $api(`/clients/${creditClient.value.id}/credit-charge`, {
      method: 'POST',
      body: {
        amount: String(creditChargeForm.amount).replace(',', '.'),
        description: opt(creditChargeForm.description),
      },
    })
    snackbar.text = 'Cargo registrado.'
    snackbar.show = true
    await loadCreditMovements()
    await refreshCreditClient()
    creditChargeForm.amount = ''
    creditChargeForm.description = ''
  }
  catch (e) {
    creditChargeError.value = messageFromApiError(e, { fallback: 'No se pudo cargar.' })
  }
  finally {
    creditActionLoading.value = false
  }
}

async function submitCreditPayment() {
  creditPayError.value = ''
  creditActionLoading.value = true
  try {
    await $api(`/clients/${creditClient.value.id}/credit-payment`, {
      method: 'POST',
      body: {
        amount: String(creditPayForm.amount).replace(',', '.'),
        description: opt(creditPayForm.description),
      },
    })
    snackbar.text = 'Abono registrado.'
    snackbar.show = true
    await loadCreditMovements()
    await refreshCreditClient()
    creditPayForm.amount = ''
    creditPayForm.description = ''
  }
  catch (e) {
    creditPayError.value = messageFromApiError(e, { fallback: 'No se pudo abonar.' })
  }
  finally {
    creditActionLoading.value = false
  }
}

async function submitCreditAdjustment() {
  creditAdjError.value = ''
  creditActionLoading.value = true
  try {
    await $api(`/clients/${creditClient.value.id}/credit-adjustment`, {
      method: 'POST',
      body: {
        amount: String(creditAdjForm.amount).replace(',', '.'),
        description: opt(creditAdjForm.description),
      },
    })
    snackbar.text = 'Ajuste registrado.'
    snackbar.show = true
    await loadCreditMovements()
    await refreshCreditClient()
    creditAdjForm.amount = ''
    creditAdjForm.description = ''
  }
  catch (e) {
    creditAdjError.value = messageFromApiError(e, { fallback: 'No se pudo ajustar.' })
  }
  finally {
    creditActionLoading.value = false
  }
}

function movementLabel(t) {
  if (t === 'charge')
    return 'Cargo'
  if (t === 'payment')
    return 'Abono'
  if (t === 'adjustment')
    return 'Ajuste'

  return t
}

onMounted(async () => {
  await authStore.fetchMe()
  if (canView.value) {
    await fetchBranches()
    await fetchClients()
  }
})

watch(creditOpen, async (open) => {
  if (!open)
    creditClient.value = null
})

watch(deleteOpen, (open) => {
  if (!open) {
    deleteTarget.value = null
    deleteStatus.value = null
  }
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

    <VCard
      v-else-if="!canView"
      class="text-center"
    >
      <VCardText class="py-12 px-6">
        <h2 class="text-h5 mb-3">
          Acceso restringido
        </h2>
        <p class="text-medium-emphasis mb-6">
          No tenés permisos para el módulo de clientes.
        </p>
        <VBtn
          color="primary"
          :to="{ name: 'root' }"
        >
          Inicio
        </VBtn>
      </VCardText>
    </VCard>

    <template v-else>
      <VCard rounded="lg">
        <VCardText class="pb-4">
          <VRow align="center">
            <VCol cols="12" lg="7">
              <VCardTitle class="text-h5 pa-0 pb-1 d-flex align-center gap-2">
                <VIcon
                  icon="ri-team-line"
                  color="primary"
                />
                Clientes
              </VCardTitle>
              <VCardSubtitle class="text-wrap pa-0 text-body-2">
                Datos según ficha (natural / jurídico), sucursal y crédito con límite y movimientos.
              </VCardSubtitle>
            </VCol>
            <VCol
              cols="12"
              lg="5"
              class="d-flex flex-wrap gap-3 align-center justify-lg-end"
            >
              <VBtn
                v-if="canManage"
                color="primary"
                prepend-icon="ri-add-line"
                class="text-none"
                @click="openCreate"
              >
                Nuevo cliente
              </VBtn>
              <VTextField
                v-model="search"
                density="compact"
                variant="solo-filled"
                flat
                placeholder="Nombre, documento, teléfono…"
                prepend-inner-icon="ri-search-line"
                hide-details
                clearable
                class="flex-grow-1"
                style="min-width: 200px;"
              />
              <VBtn
                variant="tonal"
                icon
                size="small"
                :loading="loading"
                @click="fetchClients"
              >
                <VIcon icon="ri-refresh-line" />
              </VBtn>

              <div class="d-flex flex-wrap align-center gap-1 ms-sm-1">
                <VBtn
                  v-for="f in dataExportFormats"
                  :key="f.value"
                  icon
                  variant="text"
                  size="small"
                  density="comfortable"
                  :loading="isExportBusy(f.value)"
                  :disabled="exportBusy || loading"
                  @click="exportClientList(f.value)"
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
                      Informes (mismo buscador · permisos de listado)
                    </VListSubheader>
                    <VListItem
                      v-for="v in pdfVariants"
                      :key="v.value"
                      :title="v.title"
                      :subtitle="v.caption"
                      prepend-icon="ri-file-pdf-line"
                      :active="false"
                      :disabled="exportBusy || loading"
                      @click="downloadClientReportPdf(v.value)"
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
            </VCol>
          </VRow>
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
          <VSheet
            border
            rounded="lg"
            class="overflow-x-auto"
          >
            <VDataTable
              :headers="headers"
              :items="filteredItems"
              :loading="loading"
              item-value="id"
              density="comfortable"
              hover
            >
              <template #item.branch="{ item }">
                {{ item.branch?.name || '—' }}
              </template>
              <template #item.credit="{ item }">
                <div v-if="item.credit_enabled" class="text-body-2">
                  <div>Deuda: {{ formatMoney(item.credit_balance) }}</div>
                  <div class="text-caption text-medium-emphasis">
                    Límite: {{ item.credit_limit != null ? formatMoney(item.credit_limit) : 'sin tope' }}
                  </div>
                </div>
                <span
                  v-else
                  class="text-medium-emphasis"
                >—</span>
              </template>
              <template #item.is_active="{ item }">
                <VChip
                  :color="item.is_active ? 'success' : 'error'"
                  size="small"
                  variant="tonal"
                  label
                >
                  {{ item.is_active ? 'Activo' : 'Inactivo' }}
                </VChip>
              </template>
              <template #item.actions="{ item }">
                <div class="d-flex justify-end gap-1 flex-wrap">
                  <VBtn
                    v-if="canManage && item.credit_enabled"
                    size="small"
                    variant="tonal"
                    color="secondary"
                    class="text-none"
                    @click="openCredit(item)"
                  >
                    Crédito
                  </VBtn>
                  <VBtn
                    v-if="canManage"
                    icon
                    variant="text"
                    size="small"
                    @click="openEdit(item)"
                  >
                    <VIcon icon="ri-pencil-line" />
                    <VTooltip
                      activator="parent"
                      location="top"
                    >
                      Editar
                    </VTooltip>
                  </VBtn>
                  <VBtn
                    v-if="canManage"
                    icon
                    variant="text"
                    size="small"
                    color="error"
                    @click="openDelete(item)"
                  >
                    <VIcon icon="ri-delete-bin-line" />
                    <VTooltip
                      activator="parent"
                      location="top"
                    >
                      Eliminar
                    </VTooltip>
                  </VBtn>
                </div>
              </template>
              <template #no-data>
                <div class="py-8 text-center text-medium-emphasis">
                  No hay clientes.
                </div>
              </template>
            </VDataTable>
          </VSheet>
        </VCardText>
      </VCard>

      <!-- Crear -->
      <VDialog
        v-model="createOpen"
        max-width="720"
        scrollable
      >
        <VCard>
          <VCardTitle>Nuevo cliente</VCardTitle>
          <VCardSubtitle>Documento único por sucursal.</VCardSubtitle>
          <VDivider />
          <VCardText>
            <VAlert
              v-if="createError"
              type="error"
              variant="tonal"
              class="mb-4"
              density="compact"
            >
              {{ createError }}
            </VAlert>
            <VRow>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="createForm.name"
                  label="Nombre / razón social *"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="createForm.surname"
                  label="Apellidos"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="createForm.type_client"
                  :items="typeClientItems"
                  item-title="title"
                  item-value="value"
                  label="Tipo"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="createForm.type_document"
                  :items="typeDocItems"
                  item-title="title"
                  item-value="value"
                  label="Tipo documento *"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="createForm.n_document"
                  label="Nº documento *"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="createForm.branch_id"
                  :items="branches"
                  item-title="name"
                  item-value="id"
                  label="Sucursal *"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="createForm.phone"
                  label="Teléfono"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="createForm.email"
                  label="Correo"
                  type="email"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="createForm.birthdate"
                  label="Nacimiento"
                  type="date"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="createForm.gender"
                  :items="genderItems"
                  item-title="title"
                  item-value="value"
                  label="Sexo"
                  density="compact"
                />
              </VCol>
              <VCol cols="12">
                <VTextarea
                  v-model="createForm.address"
                  label="Dirección"
                  rows="2"
                  density="compact"
                />
              </VCol>
              <VCol cols="12">
                <VSwitch
                  v-model="createForm.credit_enabled"
                  label="Habilitar venta a crédito"
                  color="primary"
                  hide-details
                />
              </VCol>
              <template v-if="createForm.credit_enabled">
                <VCol cols="12">
                  <VAlert
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="text-body-2"
                  >
                    Opcional: <strong>Ubigeo</strong> u otro <strong>código de ubicación</strong> ayuda a ubicar al cliente para <strong>cobranza</strong> cuando vende a crédito.
                  </VAlert>
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <VTextField
                    v-model="createForm.ubigeo"
                    label="Ubigeo / código de ubicación"
                    placeholder="Ej. código INE, municipal, etc."
                    hint="Opcional"
                    persistent-hint
                    density="compact"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <VTextField
                    v-model="createForm.credit_limit"
                    label="Límite de crédito (Bs.) — vacío = sin tope"
                    density="compact"
                  />
                </VCol>
              </template>
              <VCol cols="12">
                <VSwitch
                  v-model="createForm.is_active"
                  label="Activo"
                  color="success"
                  hide-details
                />
              </VCol>
            </VRow>
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="text"
              @click="createOpen = false"
            >
              Cancelar
            </VBtn>
            <VBtn
              color="primary"
              :loading="createSubmitting"
              @click="submitCreate"
            >
              Guardar
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <!-- Editar -->
      <VDialog
        v-model="editOpen"
        max-width="720"
        scrollable
      >
        <VCard>
          <VCardTitle>Editar cliente</VCardTitle>
          <VDivider />
          <VCardText>
            <VAlert
              v-if="editError"
              type="error"
              variant="tonal"
              class="mb-4"
              density="compact"
            >
              {{ editError }}
            </VAlert>
            <VRow>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="editForm.name"
                  label="Nombre *"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="editForm.surname"
                  label="Apellidos"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="editForm.type_client"
                  :items="typeClientItems"
                  item-title="title"
                  item-value="value"
                  label="Tipo"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="editForm.type_document"
                  :items="typeDocItems"
                  item-title="title"
                  item-value="value"
                  label="Tipo documento *"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="editForm.n_document"
                  label="Nº documento *"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="editForm.branch_id"
                  :items="branches"
                  item-title="name"
                  item-value="id"
                  label="Sucursal *"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="editForm.phone"
                  label="Teléfono"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="editForm.email"
                  label="Correo"
                  type="email"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="editForm.birthdate"
                  label="Nacimiento"
                  type="date"
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="editForm.gender"
                  :items="genderItems"
                  item-title="title"
                  item-value="value"
                  label="Sexo"
                  density="compact"
                />
              </VCol>
              <VCol cols="12">
                <VTextarea
                  v-model="editForm.address"
                  label="Dirección"
                  rows="2"
                  density="compact"
                />
              </VCol>
              <VCol cols="12">
                <VSwitch
                  v-model="editForm.credit_enabled"
                  label="Crédito habilitado"
                  color="primary"
                  hide-details
                />
              </VCol>
              <template v-if="editForm.credit_enabled">
                <VCol cols="12">
                  <VAlert
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="text-body-2"
                  >
                    Opcional: <strong>Ubigeo</strong> o código territorial para apoyar la <strong>cobranza</strong> del crédito.
                  </VAlert>
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <VTextField
                    v-model="editForm.ubigeo"
                    label="Ubigeo / código de ubicación"
                    placeholder="Ej. código INE, municipal, etc."
                    hint="Opcional"
                    persistent-hint
                    density="compact"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <VTextField
                    v-model="editForm.credit_limit"
                    label="Límite (Bs.)"
                    density="compact"
                  />
                </VCol>
              </template>
              <VCol cols="12">
                <VSwitch
                  v-model="editForm.is_active"
                  label="Activo"
                  color="success"
                  hide-details
                />
              </VCol>
            </VRow>
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="text"
              @click="editOpen = false"
            >
              Cerrar
            </VBtn>
            <VBtn
              color="primary"
              :loading="editSubmitting"
              @click="submitEdit"
            >
              Guardar
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <!-- Eliminar -->
      <VDialog
        v-model="deleteOpen"
        max-width="520"
        scrollable
      >
        <VCard v-if="deleteTarget">
          <VCardTitle>Eliminar cliente</VCardTitle>
          <VCardSubtitle class="text-wrap">
            Validación antes de borrar la ficha de <strong>{{ deleteTarget.full_name }}</strong>.
          </VCardSubtitle>
          <VDivider />
          <VCardText>
            <VProgressLinear
              v-if="deleteStatusLoading"
              indeterminate
              color="primary"
              class="mb-4"
            />
            <template v-else-if="deleteStatus?.metrics">
              <p class="text-body-2 text-medium-emphasis mb-3">
                Métricas actuales (según el servidor):
              </p>
              <VList
                density="compact"
                class="border rounded mb-4"
              >
                <VListItem>
                  <VListItemTitle>Saldo de crédito</VListItemTitle>
                  <VListItemSubtitle>
                    {{ formatMoney(deleteStatus.metrics.credit_balance) }}
                  </VListItemSubtitle>
                </VListItem>
                <VListItem>
                  <VListItemTitle>Crédito habilitado</VListItemTitle>
                  <VListItemSubtitle>
                    {{ deleteStatus.metrics.credit_enabled ? 'Sí' : 'No' }}
                  </VListItemSubtitle>
                </VListItem>
                <VListItem v-if="deleteStatus.metrics.credit_limit != null">
                  <VListItemTitle>Límite</VListItemTitle>
                  <VListItemSubtitle>
                    {{ formatMoney(deleteStatus.metrics.credit_limit) }}
                  </VListItemSubtitle>
                </VListItem>
                <VListItem>
                  <VListItemTitle>Movimientos de crédito registrados</VListItemTitle>
                  <VListItemSubtitle>
                    {{ deleteStatus.metrics.movements_count }}
                  </VListItemSubtitle>
                </VListItem>
                <VListItem>
                  <VListItemTitle>Vinculado a usuario del sistema</VListItemTitle>
                  <VListItemSubtitle>
                    {{ deleteStatus.metrics.linked_user ? 'Sí' : 'No' }}
                  </VListItemSubtitle>
                </VListItem>
              </VList>
              <VAlert
                v-if="deleteStatus.can_delete"
                type="success"
                variant="tonal"
                density="compact"
                class="mb-0"
              >
                Podés eliminar este cliente: no hay bloqueos activos.
              </VAlert>
              <template v-else>
                <VAlert
                  type="error"
                  variant="tonal"
                  density="compact"
                  class="mb-2"
                >
                  No se puede eliminar hasta resolver lo siguiente:
                </VAlert>
                <ul class="text-body-2 ps-4 mb-0">
                  <li
                    v-for="(b, i) in deleteStatus.blockers"
                    :key="i"
                    class="mb-1"
                  >
                    {{ b.message }}
                  </li>
                </ul>
              </template>
            </template>
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="text"
              @click="deleteOpen = false"
            >
              Cancelar
            </VBtn>
            <VBtn
              color="error"
              :loading="deleteSubmitting"
              :disabled="deleteStatusLoading || !deleteStatus?.can_delete"
              @click="confirmDelete"
            >
              Eliminar
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <!-- Crédito -->
      <VDialog
        v-model="creditOpen"
        max-width="640"
        scrollable
      >
        <VCard v-if="creditClient">
          <VCardTitle>Movimientos de crédito</VCardTitle>
          <VCardSubtitle>
            {{ creditClient.full_name }} — Saldo: {{ formatMoney(creditClient.credit_balance) }}
            <span v-if="creditClient.credit_limit != null"> · Límite: {{ formatMoney(creditClient.credit_limit) }}</span>
          </VCardSubtitle>
          <VDivider />
          <VCardText>
            <VTabs v-model="creditTab">
              <VTab value="movements">
                Historial
              </VTab>
              <VTab value="charge">
                Cargo
              </VTab>
              <VTab value="pay">
                Abono
              </VTab>
              <VTab value="adj">
                Ajuste
              </VTab>
            </VTabs>
            <VWindow v-model="creditTab" class="mt-4">
              <VWindowItem value="movements">
                <VProgressLinear
                  v-if="creditMovementsLoading"
                  indeterminate
                  class="mb-2"
                />
                <VList
                  v-else-if="creditMovements.length"
                  density="compact"
                  class="border rounded"
                >
                  <VListItem
                    v-for="m in creditMovements"
                    :key="m.id"
                  >
                    <VListItemTitle>
                      {{ movementLabel(m.type) }} · {{ formatMoney(m.amount) }}
                      <span class="text-medium-emphasis">→ saldo {{ formatMoney(m.balance_after) }}</span>
                    </VListItemTitle>
                    <VListItemSubtitle>
                      {{ m.description || '—' }} · {{ m.creator?.name || 'Sistema' }}
                      · {{ new Date(m.created_at).toLocaleString('es') }}
                    </VListItemSubtitle>
                  </VListItem>
                </VList>
                <p
                  v-else
                  class="text-medium-emphasis text-body-2"
                >
                  Sin movimientos.
                </p>
              </VWindowItem>
              <VWindowItem value="charge">
                <VAlert
                  v-if="creditChargeError"
                  type="error"
                  variant="tonal"
                  class="mb-3"
                  density="compact"
                >
                  {{ creditChargeError }}
                </VAlert>
                <VTextField
                  v-model="creditChargeForm.amount"
                  label="Monto (Bs.) *"
                  density="compact"
                  class="mb-2"
                />
                <VTextField
                  v-model="creditChargeForm.description"
                  label="Descripción"
                  density="compact"
                  class="mb-2"
                />
                <VBtn
                  color="primary"
                  :loading="creditActionLoading"
                  @click="submitCreditCharge"
                >
                  Registrar cargo
                </VBtn>
              </VWindowItem>
              <VWindowItem value="pay">
                <VAlert
                  v-if="creditPayError"
                  type="error"
                  variant="tonal"
                  class="mb-3"
                  density="compact"
                >
                  {{ creditPayError }}
                </VAlert>
                <VTextField
                  v-model="creditPayForm.amount"
                  label="Abono (Bs.) *"
                  density="compact"
                  class="mb-2"
                />
                <VTextField
                  v-model="creditPayForm.description"
                  label="Descripción"
                  density="compact"
                  class="mb-2"
                />
                <VBtn
                  color="success"
                  :loading="creditActionLoading"
                  @click="submitCreditPayment"
                >
                  Registrar abono
                </VBtn>
              </VWindowItem>
              <VWindowItem value="adj">
                <p class="text-caption text-medium-emphasis mb-2">
                  Monto con signo: positivo aumenta la deuda, negativo la reduce.
                </p>
                <VAlert
                  v-if="creditAdjError"
                  type="error"
                  variant="tonal"
                  class="mb-3"
                  density="compact"
                >
                  {{ creditAdjError }}
                </VAlert>
                <VTextField
                  v-model="creditAdjForm.amount"
                  label="Monto (+/-) *"
                  density="compact"
                  class="mb-2"
                />
                <VTextField
                  v-model="creditAdjForm.description"
                  label="Motivo"
                  density="compact"
                  class="mb-2"
                />
                <VBtn
                  color="warning"
                  :loading="creditActionLoading"
                  @click="submitCreditAdjustment"
                >
                  Aplicar ajuste
                </VBtn>
              </VWindowItem>
            </VWindow>
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="text"
              @click="creditOpen = false"
            >
              Cerrar
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <VSnackbar
        v-model="snackbar.show"
        location="bottom end"
        :timeout="2600"
      >
        {{ snackbar.text }}
      </VSnackbar>
    </template>
  </div>
</template>
