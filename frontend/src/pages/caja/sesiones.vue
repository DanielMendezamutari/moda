<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'
import { formatBsAmount } from '@/utils/formatNumbers'

definePage({
  meta: {
    navActiveLink: 'caja-sesiones',
  },
})

const authStore = useAuthStore()
const router = useRouter()

const loading = ref(false)
const registers = ref([])
const activeSessions = ref([])
const recentSessions = ref([])
const errorMsg = ref('')

const snackbar = reactive({ show: false, text: '' })

const openDialog = ref(false)
const openSubmitting = ref(false)
const openError = ref('')
const openForm = reactive({
  cash_register_id: null,
  opening_float: '0',
})

const closeDialog = ref(false)
const closeSubmitting = ref(false)
const closeError = ref('')
const closeTarget = ref(null)
const closeForm = reactive({
  counted_cash: '',
  notes: '',
})

/** Resumen por turno abierto (ventas / esperado). */
const sessionSummaries = ref({})
const summariesLoading = ref(false)

const closeSummary = ref(null)
const closeSummaryLoading = ref(false)

const registerItems = computed(() =>
  registers.value.map(r => ({
    title: r.code ? `${r.name} (${r.code})` : r.name,
    value: r.id,
  })),
)

async function fetchRegisters() {
  try {
    const res = await $api('/cash-registers')
    registers.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    registers.value = []
  }
}

async function refreshSessions() {
  errorMsg.value = ''
  loading.value = true
  try {
    const [activeRes, recentRes] = await Promise.all([
      $api('/cash-register-sessions/active'),
      $api('/cash-register-sessions?status=closed&per_page=30'),
    ])
    activeSessions.value = Array.isArray(activeRes?.data) ? activeRes.data : []
    recentSessions.value = Array.isArray(recentRes?.data) ? recentRes.data : []
    await fetchSummariesForActive()
  }
  catch (e) {
    errorMsg.value = messageFromApiError(e, { fallback: 'No se pudieron cargar los turnos.' })
  }
  finally {
    loading.value = false
  }
}

async function fetchSummariesForActive() {
  summariesLoading.value = true
  const map = {}
  try {
    for (const s of activeSessions.value) {
      try {
        const res = await $api(`/cash-register-sessions/${s.id}/summary`)
        if (res?.data)
          map[s.id] = res.data
      }
      catch {
        /* vacío */
      }
    }
    sessionSummaries.value = map
  }
  finally {
    summariesLoading.value = false
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

function goToMovements(sessionId) {
  router.push({ name: 'caja-movimientos', query: { session: String(sessionId) } })
}

const formatBs = formatBsAmount

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

function openOpenDialog() {
  openForm.cash_register_id = registerItems.value[0]?.value ?? null
  openForm.opening_float = '0'
  openError.value = ''
  openDialog.value = true
}

async function submitOpen() {
  openError.value = ''
  const oid = openForm.cash_register_id
  if (oid == null) {
    openError.value = 'Seleccioná una caja.'
    return
  }
  const mo = Number(String(openForm.opening_float).replace(',', '.'))
  if (!Number.isFinite(mo) || mo < 0) {
    openError.value = 'Monto inicial inválido.'
    return
  }
  openSubmitting.value = true
  try {
    await $api('/cash-register-sessions/open', {
      method: 'POST',
      body: {
        cash_register_id: oid,
        opening_float: mo,
      },
    })
    snackbar.text = 'Turno abierto.'
    snackbar.show = true
    openDialog.value = false
    await refreshSessions()
  }
  catch (e) {
    openError.value = messageFromApiError(e, { fallback: 'No se pudo abrir el turno.' })
  }
  finally {
    openSubmitting.value = false
  }
}

async function openCloseDialog(session) {
  closeTarget.value = session
  closeForm.counted_cash = ''
  closeForm.notes = ''
  closeError.value = ''
  closeSummary.value = null
  closeDialog.value = true
  closeSummaryLoading.value = true
  try {
    const res = await $api(`/cash-register-sessions/${session.id}/summary`)
    closeSummary.value = res?.data ?? null
  }
  catch {
    closeSummary.value = null
  }
  finally {
    closeSummaryLoading.value = false
  }
}

async function submitClose() {
  closeError.value = ''
  const n = Number(String(closeForm.counted_cash).replace(',', '.'))
  if (!Number.isFinite(n) || n < 0) {
    closeError.value = 'Indicá el efectivo contado.'
    return
  }
  closeSubmitting.value = true
  const closedId = closeTarget.value?.id
  try {
    await $api(`/cash-register-sessions/${closeTarget.value.id}/close`, {
      method: 'POST',
      body: {
        counted_cash: n,
        notes: closeForm.notes.trim() || null,
      },
    })
    snackbar.text = 'Turno cerrado (arqueo registrado).'
    snackbar.show = true
    closeDialog.value = false
    await refreshSessions()
    if (closedId != null)
      await openSessionReportPdf(closedId)
  }
  catch (e) {
    closeError.value = messageFromApiError(e, { fallback: 'No se pudo cerrar el turno.' })
  }
  finally {
    closeSubmitting.value = false
  }
}

onMounted(async () => {
  await authStore.fetchMe()
  await fetchRegisters()
  await refreshSessions()
})
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-6">
      <div>
        <h1 class="text-h4 font-weight-bold">
          Apertura / turnos
        </h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Abrí la caja con fondo inicial (caja chica) y cerrá con arqueo de efectivo contado.
        </p>
      </div>
      <VBtn
        color="primary"
        prepend-icon="ri-door-open-line"
        :disabled="!registerItems.length"
        @click="openOpenDialog"
      >
        Abrir turno
      </VBtn>
    </div>

    <VAlert
      v-if="errorMsg"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      {{ errorMsg }}
    </VAlert>

    <VCard class="mb-4" variant="outlined">
      <VCardTitle class="text-subtitle-1 py-3">
        Turnos abiertos ahora
      </VCardTitle>
      <VDivider />
      <VCardText>
        <div v-if="loading" class="py-6 text-center">
          <VProgressCircular indeterminate color="primary" />
        </div>
        <div v-else-if="!activeSessions.length" class="text-medium-emphasis text-body-2">
          No hay turnos abiertos. Usá «Abrir turno» para iniciar.
        </div>
        <VRow v-else dense>
          <VCol
            v-for="s in activeSessions"
            :key="s.id"
            cols="12"
            md="6"
          >
            <VSheet border rounded="lg" class="pa-4">
              <div class="text-overline text-medium-emphasis">
                #{{ s.id }} · {{ s.cash_register?.name }}
              </div>
              <div class="text-body-2 mb-2">
                Abierta {{ formatDt(s.opened_at) }}
              </div>
              <div class="text-body-2 mb-1">
                Fondo inicial: <strong>{{ formatBs(s.opening_float) }} Bs.</strong>
              </div>
              <div
                v-if="summariesLoading"
                class="text-caption text-medium-emphasis mb-3"
              >
                Cargando resumen…
              </div>
              <div
                v-else-if="sessionSummaries[s.id]"
                class="text-body-2 mb-3 pa-2 rounded bg-surface-variant"
              >
                <div class="font-weight-medium text-high-emphasis">
                  Ventas: {{ sessionSummaries[s.id].sales_count }} · Total {{ formatBs(sessionSummaries[s.id].sales_total) }} Bs.
                </div>
                <div class="text-caption text-medium-emphasis mt-1">
                  Efectivo esperado en cajón: {{ formatBs(sessionSummaries[s.id].expected_cash) }} Bs.
                </div>
              </div>
              <div
                v-else
                class="text-caption text-medium-emphasis mb-3"
              >
                Sin datos de resumen.
              </div>
              <div class="d-flex flex-wrap gap-2">
                <VBtn
                  color="primary"
                  variant="tonal"
                  size="small"
                  prepend-icon="ri-file-list-3-line"
                  @click="goToMovements(s.id)"
                >
                  Movimientos
                </VBtn>
                <VBtn
                  color="secondary"
                  variant="tonal"
                  size="small"
                  prepend-icon="ri-file-pdf-line"
                  @click="openSessionReportPdf(s.id)"
                >
                  Vista PDF
                </VBtn>
                <VBtn
                  color="warning"
                  variant="flat"
                  size="small"
                  prepend-icon="ri-lock-line"
                  @click="openCloseDialog(s)"
                >
                  Cerrar caja
                </VBtn>
              </div>
            </VSheet>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard>
      <VCardTitle class="text-subtitle-1 py-3">
        Últimos cierres
      </VCardTitle>
      <VDivider />
      <VDataTable
        :headers="[
          { title: 'Caja', key: 'cr' },
          { title: 'Apertura', key: 'opened_at' },
          { title: 'Cierre', key: 'closed_at' },
          { title: 'Esperado', key: 'expected' },
          { title: 'Contado', key: 'counted' },
          { title: 'Dif.', key: 'diff' },
          { title: 'Acciones', key: 'actions', sortable: false, align: 'end' },
        ]"
        :items="recentSessions"
        :loading="loading"
      >
        <template #item.cr="{ item }">
          {{ item.cash_register?.name || '—' }}
        </template>
        <template #item.opened_at="{ item }">
          {{ formatDt(item.opened_at) }}
        </template>
        <template #item.closed_at="{ item }">
          {{ formatDt(item.closed_at) }}
        </template>
        <template #item.expected="{ item }">
          {{ formatBs(item.expected_cash) }}
        </template>
        <template #item.counted="{ item }">
          {{ formatBs(item.counted_cash) }}
        </template>
        <template #item.diff="{ item }">
          {{ formatBs(item.difference_amount) }}
        </template>
        <template #item.actions="{ item }">
          <VBtn
            icon
            variant="text"
            color="primary"
            size="small"
            aria-label="PDF de cierre"
            @click="openSessionReportPdf(item.id)"
          >
            <VIcon icon="ri-file-pdf-line" />
          </VBtn>
        </template>
        <template #no-data>
          <div class="text-center py-6 text-medium-emphasis">
            Sin datos recientes.
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="openDialog" max-width="440">
      <VCard>
        <VCardTitle>Abrir turno</VCardTitle>
        <VCardText>
          <VAlert v-if="openError" type="error" variant="tonal" class="mb-3" density="compact">
            {{ openError }}
          </VAlert>
          <VSelect
            v-model="openForm.cash_register_id"
            :items="registerItems"
            item-title="title"
            item-value="value"
            label="Caja"
            density="comfortable"
            class="mb-3"
          />
          <VTextField
            v-model="openForm.opening_float"
            label="Monto inicial en efectivo (caja chica)"
            type="number"
            min="0"
            step="0.01"
            density="comfortable"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="openDialog = false">Cancelar</VBtn>
          <VBtn color="primary" :loading="openSubmitting" @click="submitOpen">
            Abrir
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="closeDialog" max-width="520" scrollable>
      <VCard>
        <VCardTitle>Cierre / arqueo</VCardTitle>
        <VCardText>
          <VAlert v-if="closeError" type="error" variant="tonal" class="mb-3" density="compact">
            {{ closeError }}
          </VAlert>
          <div
            v-if="closeSummaryLoading"
            class="text-caption text-medium-emphasis mb-3"
          >
            Cargando resumen del turno…
          </div>
          <VSheet
            v-else-if="closeSummary"
            border
            rounded="lg"
            class="pa-3 mb-4 bg-surface-variant"
          >
            <div class="text-caption font-weight-bold mb-2">
              Resumen hasta ahora
            </div>
            <div class="text-body-2">
              Ventas: {{ closeSummary.sales_count }} · Total {{ formatBs(closeSummary.sales_total) }} Bs.
            </div>
            <div class="text-body-2 mt-1">
              Efectivo esperado en cajón: <strong>{{ formatBs(closeSummary.expected_cash) }} Bs.</strong>
            </div>
          </VSheet>
          <p class="text-body-2 text-medium-emphasis mb-3">
            Contá solo el efectivo físico en cajón. El esperado se calcula con fondo inicial ± movimientos en efectivo.
          </p>
          <VTextField
            v-model="closeForm.counted_cash"
            label="Efectivo contado"
            type="number"
            min="0"
            step="0.01"
            density="comfortable"
            class="mb-3"
          />
          <VTextarea
            v-model="closeForm.notes"
            label="Notas (opcional)"
            rows="2"
            density="comfortable"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="closeDialog = false">Cancelar</VBtn>
          <VBtn color="primary" :loading="closeSubmitting" @click="submitClose">
            Cerrar turno
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar.show" color="success">
      {{ snackbar.text }}
    </VSnackbar>
  </div>
</template>
