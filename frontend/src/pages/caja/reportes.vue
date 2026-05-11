<script setup>
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'caja-reportes',
  },
})

const authStore = useAuthStore()

const loading = ref(false)
const errorMsg = ref('')
const sessionRows = ref([])
const movementRows = ref([])
const salesRows = ref([])

const filterFrom = ref('')
const filterTo = ref('')

async function loadAll() {
  errorMsg.value = ''
  loading.value = true
  try {
    const q = {}
    if (filterFrom.value)
      q.from = filterFrom.value
    if (filterTo.value)
      q.to = filterTo.value

    const [sRes, mRes, vRes] = await Promise.all([
      $api('/cash-reports/sessions', { query: q }),
      $api('/cash-reports/movements', { query: q }),
      $api('/cash-reports/sales-by-day', { query: q }),
    ])
    sessionRows.value = Array.isArray(sRes?.data) ? sRes.data : []
    movementRows.value = Array.isArray(mRes?.data) ? mRes.data : []
    salesRows.value = Array.isArray(vRes?.data) ? vRes.data : []
  }
  catch (e) {
    errorMsg.value = messageFromApiError(e, { fallback: 'No se pudieron cargar los reportes.' })
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

onMounted(async () => {
  await authStore.fetchMe()
  await loadAll()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-h4 font-weight-bold">
        Reportes de caja
      </h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        Arqueos por período, movimientos y ventas diarias vinculadas a turno de caja.
      </p>
    </div>

    <VCard variant="outlined" class="mb-4 pa-4">
      <VRow dense align="center">
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
        <VCol cols="12" sm="4">
          <VBtn block color="primary" variant="tonal" :loading="loading" @click="loadAll">
            Actualizar
          </VBtn>
        </VCol>
      </VRow>
    </VCard>

    <VAlert v-if="errorMsg" type="error" variant="tonal" class="mb-4">
      {{ errorMsg }}
    </VAlert>

    <VCard class="mb-4">
      <VCardTitle class="text-subtitle-1">
        Turnos / arqueos
      </VCardTitle>
      <VDivider />
      <VDataTable
        :headers="[
          { title: 'Estado', key: 'status' },
          { title: 'Caja', key: 'cash_register' },
          { title: 'Apertura', key: 'opened_at' },
          { title: 'Cierre', key: 'closed_at' },
          { title: 'Diferencia', key: 'difference_amount' },
        ]"
        :items="sessionRows"
        :loading="loading"
      >
        <template #item.cash_register="{ item }">
          {{ item.cash_register?.name || '—' }}
        </template>
        <template #item.opened_at="{ item }">
          {{ formatDt(item.opened_at) }}
        </template>
        <template #item.closed_at="{ item }">
          {{ formatDt(item.closed_at) }}
        </template>
        <template #no-data>
          <div class="py-8 text-center text-medium-emphasis">
            Sin datos.
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VCard class="mt-4">
      <VCardTitle class="text-subtitle-1">
        Movimientos (detalle)
      </VCardTitle>
      <VDivider />
      <VDataTable
        :headers="[
          { title: 'Fecha', key: 'occurred_at' },
          { title: 'Tipo', key: 'type' },
          { title: 'Origen', key: 'source' },
          { title: 'Monto', key: 'amount' },
          { title: 'Método', key: 'method_payment' },
          { title: 'Descripción', key: 'description' },
        ]"
        :items="movementRows"
        :loading="loading"
      >
        <template #item.occurred_at="{ item }">
          {{ formatDt(item.occurred_at) }}
        </template>
        <template #no-data>
          <div class="py-6 text-center text-medium-emphasis">
            Sin movimientos.
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VCard class="mt-4">
      <VCardTitle class="text-subtitle-1">
        Ventas por día (con sesión de caja)
      </VCardTitle>
      <VDivider />
      <VDataTable
        :headers="[
          { title: 'Fecha', key: 'date' },
          { title: 'Ventas', key: 'sales_count' },
          { title: 'Total', key: 'total_sales' },
        ]"
        :items="salesRows"
        :loading="loading"
      >
        <template #no-data>
          <div class="py-6 text-center text-medium-emphasis">
            Sin ventas en el período.
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
