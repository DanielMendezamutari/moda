<script setup>
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'
import { formatBsAmount } from '@/utils/formatNumbers'

definePage({
  meta: {
    navActiveLink: 'caja-movimientos',
  },
})

const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const movements = ref([])
const activeSessions = ref([])
const errorMsg = ref('')

const snackbar = reactive({ show: false, text: '' })

const manualOpen = ref(false)
const manualSubmitting = ref(false)
const manualError = ref('')
const manualForm = reactive({
  cash_register_session_id: null,
  type: 'expense',
  amount: '',
  method_payment: 'efectivo',
  description: '',
})

const filterFrom = ref('')
const filterTo = ref('')
/** Filtrar por turno (desde menú Caja o URL ?session=). */
const filterSessionId = ref(null)

const movementSummary = ref(null)
const summaryLoading = ref(false)

const sessionItems = computed(() =>
  activeSessions.value.map(s => ({
    title: `#${s.id} · ${s.cash_register?.name ?? 'Caja'}`,
    value: s.id,
  })),
)

const methodItems = [
  { title: 'Efectivo', value: 'efectivo' },
  { title: 'QR', value: 'qr' },
  { title: 'Transferencia', value: 'transferencia' },
]

const sessionFilterItems = computed(() => [
  { title: 'Todos los turnos', value: null },
  ...activeSessions.value.map(s => ({
    title: `#${s.id} · ${s.cash_register?.name ?? 'Caja'}`,
    value: s.id,
  })),
])

async function fetchMovementSummary(sessionId) {
  movementSummary.value = null
  if (sessionId == null || sessionId === '')
    return

  summaryLoading.value = true
  try {
    const res = await $api(`/cash-register-sessions/${sessionId}/summary`)
    movementSummary.value = res?.data ?? null
  }
  catch {
    movementSummary.value = null
  }
  finally {
    summaryLoading.value = false
  }
}

async function openSessionReportPdf(sessionId) {
  const id = Number(sessionId)
  if (!Number.isFinite(id) || id <= 0)
    return

  try {
    const raw = await $apiRaw(`/cash-register-sessions/${id}/close-report`, { responseType: 'blob' })
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
    snackbar.text = 'No se pudo generar el PDF del turno.'
    snackbar.show = true
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

async function openMovementTicketPdf(movementId) {
  const id = Number(movementId)
  if (!Number.isFinite(id) || id <= 0)
    return

  try {
    const raw = await $apiRaw(`/cash-movements/${id}/ticket`, { responseType: 'blob' })
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
    snackbar.text = 'No se pudo abrir el ticket del movimiento.'
    snackbar.show = true
  }
}

/** Ticket de venta (origen venta) o ticket del movimiento manual. */
function openMovementRowTicket(item) {
  if (item.source === 'sale_payment') {
    if (item.sale_id != null)
      return openSaleTicketPdf(item.sale_id)

    snackbar.text = 'No se pudo vincular este cobro a una venta.'
    snackbar.show = true

    return
  }

  return openMovementTicketPdf(item.id)
}

async function fetchActive() {
  try {
    const res = await $api('/cash-register-sessions/active')
    activeSessions.value = Array.isArray(res?.data) ? res.data : []
    if (activeSessions.value.length === 1)
      manualForm.cash_register_session_id = activeSessions.value[0].id
  }
  catch {
    activeSessions.value = []
  }
}

async function fetchMovements() {
  errorMsg.value = ''
  loading.value = true
  try {
    const q = {}
    if (filterFrom.value)
      q.from = filterFrom.value
    if (filterTo.value)
      q.to = filterTo.value
    if (filterSessionId.value != null)
      q.cash_register_session_id = filterSessionId.value

    const res = await $api('/cash-movements', { query: q })
    movements.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    movements.value = []
    errorMsg.value = messageFromApiError(e, { fallback: 'No se pudieron cargar movimientos.' })
  }
  finally {
    loading.value = false
  }
}

function formatDt(iso) {
  if (!iso)
    return '—'

  try {
    return new Date(iso).toLocaleString('es-BO')
  }
  catch {
    return iso
  }
}

async function submitManual() {
  manualError.value = ''
  if (manualForm.cash_register_session_id == null) {
    manualError.value = 'Seleccioná un turno abierto.'
    return
  }
  const amt = Number(String(manualForm.amount).replace(',', '.'))
  if (!Number.isFinite(amt) || amt <= 0) {
    manualError.value = 'Indicá un monto válido.'
    return
  }
  manualSubmitting.value = true
  try {
    await $api('/cash-movements', {
      method: 'POST',
      body: {
        cash_register_session_id: manualForm.cash_register_session_id,
        type: manualForm.type,
        amount: amt,
        method_payment: manualForm.method_payment,
        description: manualForm.description.trim() || null,
      },
    })
    snackbar.text = 'Movimiento registrado.'
    snackbar.show = true
    manualOpen.value = false
    manualForm.amount = ''
    manualForm.description = ''
    await fetchMovements()
    if (filterSessionId.value != null)
      await fetchMovementSummary(filterSessionId.value)
  }
  catch (e) {
    manualError.value = messageFromApiError(e, { fallback: 'No se pudo registrar.' })
  }
  finally {
    manualSubmitting.value = false
  }
}

async function onSessionFilterChange(id) {
  const sid = id ?? null
  filterSessionId.value = sid
  await router.replace({ query: { ...route.query, session: sid != null ? String(sid) : undefined } })
  await fetchMovementSummary(sid)
  await fetchMovements()
}

const formatBs = formatBsAmount

onMounted(async () => {
  await authStore.fetchMe()
  await fetchActive()
  const q = route.query.session
  if (q != null && q !== '') {
    const n = Number(q)
    if (Number.isFinite(n))
      filterSessionId.value = n
  }
  await fetchMovementSummary(filterSessionId.value)
  await fetchMovements()
})
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-6">
      <div>
        <h1 class="text-h4 font-weight-bold">
          Movimientos de caja
        </h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Ingresos y egresos manuales (retiros, gastos). Los cobros de ventas se registran automáticamente.
        </p>
      </div>
      <VBtn
        color="primary"
        prepend-icon="ri-exchange-line"
        @click="manualOpen = true"
      >
        Registrar movimiento
      </VBtn>
    </div>

    <VCard variant="outlined" class="mb-4 pa-4">
      <VRow dense align="center">
        <VCol cols="12" md="4">
          <VSelect
            :model-value="filterSessionId"
            :items="sessionFilterItems"
            item-title="title"
            item-value="value"
            label="Turno de caja"
            density="compact"
            hide-details
            clearable
            variant="outlined"
            @update:model-value="onSessionFilterChange"
          />
        </VCol>
        <VCol cols="12" sm="4">
          <VTextField
            v-model="filterFrom"
            label="Desde"
            type="date"
            density="compact"
            hide-details
            variant="outlined"
          />
        </VCol>
        <VCol cols="12" sm="4">
          <VTextField
            v-model="filterTo"
            label="Hasta"
            type="date"
            density="compact"
            hide-details
            variant="outlined"
          />
        </VCol>
        <VCol cols="12" sm="4" md="4">
          <VBtn block color="primary" variant="tonal" @click="fetchMovements">
            Filtrar fechas
          </VBtn>
        </VCol>
      </VRow>
    </VCard>

    <VAlert v-if="errorMsg" type="error" variant="tonal" class="mb-4">
      {{ errorMsg }}
    </VAlert>

    <VSheet
      v-if="filterSessionId != null"
      border
      rounded="lg"
      class="pa-4 mb-4"
    >
      <div class="text-caption text-medium-emphasis mb-2">
        Resumen del turno #{{ filterSessionId }}
      </div>
      <div v-if="summaryLoading" class="text-body-2">
        Cargando…
      </div>
      <template v-else-if="movementSummary">
        <VRow dense>
          <VCol cols="12" sm="6" md="3">
            <div class="text-caption text-medium-emphasis">
              Ventas (cant.)
            </div>
            <div class="text-h6 font-weight-bold">
              {{ movementSummary.sales_count }}
            </div>
          </VCol>
          <VCol cols="12" sm="6" md="3">
            <div class="text-caption text-medium-emphasis">
              Total vendido
            </div>
            <div class="text-h6 font-weight-bold">
              {{ formatBs(movementSummary.sales_total) }} Bs.
            </div>
          </VCol>
          <VCol cols="12" sm="6" md="3">
            <div class="text-caption text-medium-emphasis">
              Efectivo esperado (cajón)
            </div>
            <div class="text-h6 font-weight-bold">
              {{ formatBs(movementSummary.expected_cash) }} Bs.
            </div>
          </VCol>
          <VCol cols="12" sm="6" md="3" class="d-flex align-center justify-end flex-wrap gap-2">
            <VBtn
              color="primary"
              variant="tonal"
              prepend-icon="ri-file-pdf-line"
              size="small"
              @click="openSessionReportPdf(filterSessionId)"
            >
              PDF del turno
            </VBtn>
          </VCol>
        </VRow>
      </template>
      <div v-else class="text-medium-emphasis text-body-2">
        No se pudo cargar el resumen.
      </div>
    </VSheet>

    <VCard>
      <VDataTable
        :headers="[
          { title: 'Fecha / hora', key: 'occurred_at' },
          { title: 'Turno', key: 'cash_register_session_id' },
          { title: 'Tipo', key: 'type' },
          { title: 'Origen', key: 'source' },
          { title: 'Monto', key: 'amount' },
          { title: 'Método', key: 'method_payment' },
          { title: 'Descripción', key: 'description' },
          { title: 'Caja', key: 'cash_register_name' },
          { title: 'Ticket', key: 'actions', sortable: false, align: 'end', width: '88px' },
        ]"
        :items="movements"
        :loading="loading"
      >
        <template #item.occurred_at="{ item }">
          {{ formatDt(item.occurred_at) }}
        </template>
        <template #item.cash_register_session_id="{ item }">
          #{{ item.cash_register_session_id }}
        </template>
        <template #item.type="{ item }">
          <VChip size="small" :color="item.type === 'income' ? 'success' : 'warning'" variant="tonal">
            {{ item.type === 'income' ? 'Ingreso' : 'Egreso' }}
          </VChip>
        </template>
        <template #item.source="{ item }">
          {{ item.source === 'sale_payment' ? 'Venta' : 'Manual' }}
        </template>
        <template #item.amount="{ item }">
          {{ formatBs(item.amount) }}
        </template>
        <template #item.cash_register_name="{ item }">
          {{ item.cash_register_name || item.cash_register?.name || '—' }}
        </template>
        <template #item.actions="{ item }">
          <VBtn
            icon
            variant="text"
            color="primary"
            size="small"
            :title="item.source === 'sale_payment' ? 'Ticket de venta' : 'Ticket del movimiento'"
            :aria-label="item.source === 'sale_payment' ? 'Ticket de venta' : 'Ticket del movimiento'"
            @click="openMovementRowTicket(item)"
          >
            <VIcon icon="ri-receipt-line" />
          </VBtn>
        </template>
        <template #no-data>
          <div class="py-8 text-center text-medium-emphasis">
            Sin movimientos en el rango.
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="manualOpen" max-width="480">
      <VCard>
        <VCardTitle>Movimiento manual</VCardTitle>
        <VCardText>
          <VAlert v-if="manualError" type="error" variant="tonal" class="mb-3" density="compact">
            {{ manualError }}
          </VAlert>
          <VSelect
            v-model="manualForm.cash_register_session_id"
            :items="sessionItems"
            item-title="title"
            item-value="value"
            label="Turno abierto"
            density="comfortable"
            class="mb-3"
          />
          <VSelect
            v-model="manualForm.type"
            :items="[
              { title: 'Ingreso', value: 'income' },
              { title: 'Egreso', value: 'expense' },
            ]"
            item-title="title"
            item-value="value"
            label="Tipo"
            density="comfortable"
            class="mb-3"
          />
          <VTextField
            v-model="manualForm.amount"
            label="Monto"
            type="number"
            min="0.01"
            step="0.01"
            density="comfortable"
            class="mb-3"
          />
          <VSelect
            v-model="manualForm.method_payment"
            :items="methodItems"
            item-title="title"
            item-value="value"
            label="Medio"
            density="comfortable"
            class="mb-3"
          />
          <VTextField
            v-model="manualForm.description"
            label="Descripción"
            density="comfortable"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="manualOpen = false">Cancelar</VBtn>
          <VBtn color="primary" :loading="manualSubmitting" @click="submitManual">
            Guardar
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar.show" color="success">
      {{ snackbar.text }}
    </VSnackbar>
  </div>
</template>
