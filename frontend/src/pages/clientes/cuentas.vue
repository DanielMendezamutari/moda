<script setup>
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'
import { formatBsAmount } from '@/utils/formatNumbers'

definePage({
  meta: {
    navActiveLink: 'clientes-cuentas',
  },
})

const authStore = useAuthStore()

const canView = computed(() =>
  authStore.isAdmin || authStore.hasRole('cashier'),
)

const loading = ref(false)
const errorMsg = ref('')
const rows = ref([])

const headers = [
  { title: 'Cliente', key: 'full_name', sortable: true },
  { title: 'Documento', key: 'n_document', sortable: false },
  { title: 'Saldo adeudado', key: 'credit_balance', sortable: true },
  { title: 'Límite', key: 'credit_limit', sortable: true },
  { title: 'Disponible', key: 'disponible', sortable: false },
  { title: 'Sucursal', key: 'branch', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end', width: '100px' },
]

const formatBs = formatBsAmount

function parseNum(v) {
  const n = Number(String(v ?? '').replace(',', '.'))

  return Number.isFinite(n) ? n : 0
}

const tableItems = computed(() => {
  const list = Array.isArray(rows.value) ? [...rows.value] : []
  list.sort((a, b) => parseNum(b.credit_balance) - parseNum(a.credit_balance))

  return list.map(c => {
    const bal = parseNum(c.credit_balance)
    const limRaw = c.credit_limit
    const lim = limRaw != null && limRaw !== '' ? parseNum(limRaw) : null
    const disponible = lim != null && Number.isFinite(lim)
      ? Math.max(0, Math.round((lim - bal) * 100) / 100)
      : null

    return {
      ...c,
      _bal: bal,
      _lim: lim,
      _disponible: disponible,
    }
  })
})

async function load() {
  if (!canView.value)
    return
  loading.value = true
  errorMsg.value = ''
  try {
    const res = await $api('/clients?credit_enabled=1')
    rows.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver clientes.',
      fallback: 'No se pudo cargar la cartera.',
    })
    rows.value = []
  }
  finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div>
    <VRow class="mb-4">
      <VCol cols="12">
        <div class="d-flex flex-wrap align-center justify-space-between gap-3">
          <div>
            <h1 class="text-h4 font-weight-bold mb-1">
              Cuentas corrientes
            </h1>
            <p class="text-body-2 text-medium-emphasis mb-0">
              Clientes con fiado habilitado y saldo al día. Desde acá podés revisar límites antes de vender a crédito en el POS.
            </p>
          </div>
          <VBtn
            color="primary"
            variant="tonal"
            prepend-icon="ri-refresh-line"
            :loading="loading"
            :disabled="!canView"
            @click="load"
          >
            Actualizar
          </VBtn>
        </div>
      </VCol>
    </VRow>

    <VAlert
      v-if="!canView"
      type="warning"
      variant="tonal"
      rounded="lg"
    >
      No tenés acceso a clientes.
    </VAlert>

    <VAlert
      v-else-if="errorMsg"
      type="error"
      variant="tonal"
      rounded="lg"
      class="mb-4"
    >
      {{ errorMsg }}
    </VAlert>

    <VCard v-if="canView">
      <VDataTable
        :headers="headers"
        :items="tableItems"
        :loading="loading"
        class="text-no-wrap"
      >
        <template #item.credit_balance="{ item }">
          <span class="font-weight-medium">{{ formatBs(item._bal) }}</span>
        </template>
        <template #item.credit_limit="{ item }">
          {{ item._lim != null ? formatBs(item._lim) : '—' }}
        </template>
        <template #item.disponible="{ item }">
          {{ item._disponible != null ? formatBs(item._disponible) : 'sin tope' }}
        </template>
        <template #item.branch="{ item }">
          {{ item.branch?.name ?? '—' }}
        </template>
        <template #item.actions="{ item }">
          <VBtn
            size="small"
            variant="text"
            color="primary"
            :to="{ name: 'clientes', query: { search: item.n_document || item.full_name || '' } }"
          >
            Ver ficha
          </VBtn>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
