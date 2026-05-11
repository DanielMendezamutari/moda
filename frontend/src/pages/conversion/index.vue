<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'
import { formatQuantityMax2 } from '@/utils/formatNumbers'

definePage({
  meta: {
    navActiveLink: 'conversion',
  },
})

const authStore = useAuthStore()

const canView = computed(() => authStore.isAdmin)

const search = ref('')
const warehouseFilter = ref(null)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const errorMsg = ref('')
const rows = ref([])
const warehouses = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const headers = [
  { title: 'ID', key: 'id', width: '72px' },
  { title: 'Producto', key: 'product', sortable: false },
  { title: 'Almacén', key: 'warehouse', sortable: false },
  { title: 'Origen → destino', key: 'units', sortable: false },
  { title: 'Cantidades', key: 'qty', sortable: false },
  { title: 'Δ stock', key: 'stock_delta', sortable: false },
  { title: 'Usuario', key: 'user', sortable: false },
  { title: 'Fecha', key: 'created_at', sortable: false },
]

let searchTimer = null

async function fetchWarehouses() {
  try {
    const res = await $api('/warehouses')
    warehouses.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    warehouses.value = []
  }
}

async function fetchList() {
  errorMsg.value = ''
  loading.value = true
  try {
    const q = new URLSearchParams()
    q.set('page', String(page.value))
    const s = search.value.trim()
    if (s)
      q.set('search', s)
    if (warehouseFilter.value)
      q.set('warehouse_id', String(warehouseFilter.value))

    const res = await $api(`/conversions?${q.toString()}`)
    rows.value = Array.isArray(res?.data) ? res.data : []
    lastPage.value = Number(res?.last_page) || 1
    total.value = Number(res?.total) ?? rows.value.length
  }
  catch (e) {
    rows.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver conversiones.',
      fallback: 'No se pudo cargar el historial.',
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
    fetchList()
  }, 380)
}

watch(page, () => {
  fetchList()
})

watch(warehouseFilter, () => {
  page.value = 1
  fetchList()
})

function formatDate(iso) {
  if (!iso)
    return '—'
  try {
    return new Intl.DateTimeFormat('es', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso))
  }
  catch {
    return '—'
  }
}

onMounted(async () => {
  await authStore.fetchMe(true)
  if (canView.value) {
    await fetchWarehouses()
    await fetchList()
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
        size="48"
      />
    </div>

    <VCard v-else-if="!canView">
      <VCardText class="py-12 text-center">
        <h2 class="text-h5 mb-2">
          Sin acceso
        </h2>
        <p class="text-body-2 text-medium-emphasis">
          Solo administradores ven el historial de conversiones.
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
              color="deep-purple"
              variant="tonal"
              size="48"
              rounded="lg"
              class="mt-1"
            >
              <VIcon
                icon="ri-exchange-funds-line"
                size="26"
              />
            </VAvatar>
            <div>
              <VCardTitle class="text-h5 pa-0 pb-1">
                Conversiones de unidades
              </VCardTitle>
              <VCardSubtitle class="pa-0">
                Cambio de presentación del mismo producto en almacén. El stock se ajusta según la diferencia en la <strong>unidad de inventario</strong> del producto en ese almacén.
              </VCardSubtitle>
            </div>
          </div>
          <VBtn
            color="primary"
            prepend-icon="ri-add-line"
            :to="{ name: 'conversion-registrar' }"
          >
            Registrar conversión
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
              label="Buscar producto o almacén"
              density="comfortable"
              prepend-inner-icon="ri-search-line"
              clearable
              hide-details
              style="max-width: 320px;"
              @update:model-value="onSearchInput"
            />
            <VSelect
              v-model="warehouseFilter"
              label="Almacén"
              prepend-inner-icon="ri-store-2-line"
              :items="[{ title: 'Todos', value: null }, ...warehouses.map(w => ({ title: w.name, value: w.id }))]"
              clearable
              density="comfortable"
              hide-details
              style="max-width: 240px;"
            />
            <span class="text-body-2 text-medium-emphasis">{{ total }} registro(s)</span>
          </div>

          <VDataTable
            :headers="headers"
            :items="rows"
            :loading="loading"
            hide-default-footer
            class="elevation-0"
          >
            <template #item.product="{ item }">
              <div class="font-weight-medium">
                {{ item.product?.name || '—' }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ item.product?.sku || '' }}
              </div>
            </template>
            <template #item.warehouse="{ item }">
              {{ item.warehouse?.name || '—' }}
            </template>
            <template #item.units="{ item }">
              <span class="text-body-2">{{ item.unit_start?.name }} → {{ item.unit_end?.name }}</span>
            </template>
            <template #item.qty="{ item }">
              <span class="text-body-2">{{ formatQuantityMax2(item.quantity_start) }} / {{ formatQuantityMax2(item.quantity_end) }}</span>
            </template>
            <template #item.stock_delta="{ item }">
              <VChip
                size="small"
                :color="Number(item.stock_delta) < 0 ? 'error' : Number(item.stock_delta) > 0 ? 'success' : 'secondary'"
                variant="tonal"
              >
                {{ formatQuantityMax2(item.stock_delta) }}
              </VChip>
            </template>
            <template #item.user="{ item }">
              {{ item.user?.name || '—' }}
            </template>
            <template #item.created_at="{ item }">
              {{ formatDate(item.created_at) }}
            </template>
            <template #bottom>
              <div class="d-flex flex-wrap align-center justify-space-between gap-3 pt-4">
                <span class="text-body-2 text-medium-emphasis">Página {{ page }} de {{ lastPage }}</span>
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
        :timeout="2500"
      >
        {{ snackbar.text }}
      </VSnackbar>
    </div>
  </div>
</template>
