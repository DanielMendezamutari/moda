<script setup>
/* eslint-disable camelcase -- API snake_case */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'

const router = useRouter()

definePage({
  meta: {
    navActiveLink: 'conversion-registrar',
  },
})

const authStore = useAuthStore()
const canManage = computed(() => authStore.isAdmin)

const branches = ref([])
const warehouses = ref([])
const units = ref([])

const branchId = ref(null)
const warehouseId = ref(null)
const productId = ref(null)
const productLabel = ref('')
const productSearch = ref('')
const productHits = ref([])
const productSearchLoading = ref(false)
const unitStartId = ref(null)
const unitEndId = ref(null)
const quantityStart = ref('1')
const quantityEnd = ref('1')
const description = ref('')

const submitError = ref('')
const submitting = ref(false)

const branchItems = computed(() =>
  branches.value.map(b => ({
    title: b.code ? `${b.name} (${b.code})` : b.name,
    value: b.id,
  })),
)

const warehousesFiltered = computed(() => {
  const bid = branchId.value
  if (bid == null || bid === '')
    return warehouses.value

  return warehouses.value.filter(w => Number(w.branch_id) === Number(bid))
})

const warehouseItems = computed(() =>
  warehousesFiltered.value.map(w => ({
    title: w.branch?.name ? `${w.name} — ${w.branch.name}` : w.name,
    value: w.id,
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

watch(() => productId.value, (id) => {
  if (!id) {
    productLabel.value = ''

    return
  }
  const it = productSelectItems.value.find(x => Number(x.value) === Number(id))
  productLabel.value = it?.raw?.name || `Producto #${id}`
})

watch(branchId, () => {
  const allowed = new Set(warehousesFiltered.value.map(w => w.id))
  if (warehouseId.value != null && !allowed.has(warehouseId.value))
    warehouseId.value = null
})

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

async function submitForm() {
  submitError.value = ''

  if (!warehouseId.value) {
    submitError.value = 'Elegí el almacén.'

    return
  }
  if (!productId.value) {
    submitError.value = 'Elegí un producto.'

    return
  }
  if (!unitStartId.value || !unitEndId.value) {
    submitError.value = 'Elegí unidad de origen y de destino.'

    return
  }
  if (Number(unitStartId.value) === Number(unitEndId.value)) {
    submitError.value = 'Las unidades deben ser distintas.'

    return
  }

  const qs = Number(String(quantityStart.value || '').replace(',', '.'))
  const qe = Number(String(quantityEnd.value || '').replace(',', '.'))
  if (!Number.isFinite(qs) || qs <= 0 || !Number.isFinite(qe) || qe <= 0) {
    submitError.value = 'Indicá cantidades válidas mayores a cero.'

    return
  }

  submitting.value = true
  try {
    await $api('/conversions', {
      method: 'POST',
      body: {
        product_id: Number(productId.value),
        warehouse_id: Number(warehouseId.value),
        unit_start_id: Number(unitStartId.value),
        unit_end_id: Number(unitEndId.value),
        quantity_start: qs,
        quantity_end: qe,
        description: String(description.value || '').trim() || null,
      },
    })
    await router.push({ name: 'conversion' })
  }
  catch (e) {
    submitError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para registrar conversiones.',
      fallback: 'No se pudo guardar la conversión.',
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
  if (wf.length === 1)
    warehouseId.value = wf[0].id
})
</script>

<template>
  <div>
    <div
      v-if="authStore.loading"
      class="d-flex justify-center py-16"
    >
      <VProgressCircular indeterminate />
    </div>

    <VCard v-else-if="!canManage">
      <VCardText class="py-12 text-center text-medium-emphasis">
        Solo administradores.
      </VCardText>
    </VCard>

    <div
      v-else
      class="pa-2 pa-md-4"
    >
      <div class="d-flex flex-wrap justify-space-between align-center gap-3 mb-4">
        <div class="d-flex align-start gap-3">
          <VAvatar
            color="deep-purple"
            variant="tonal"
            size="52"
            rounded="lg"
          >
            <VIcon
              icon="ri-scales-3-line"
              size="28"
            />
          </VAvatar>
          <div>
            <h1 class="text-h5">
              Registrar conversión
            </h1>
            <p class="text-body-2 text-medium-emphasis mb-0">
              El producto debe tener <strong>unidad de stock</strong> definida en ese almacén y factores entre unidades en <strong>Unidades</strong> del menú.
            </p>
          </div>
        </div>
        <VBtn
          variant="tonal"
          prepend-icon="ri-history-line"
          :to="{ name: 'conversion' }"
        >
          Historial
        </VBtn>
      </div>

      <VCard>
        <VCardText>
          <VAlert
            v-if="submitError"
            type="error"
            variant="tonal"
            class="mb-4"
            rounded="lg"
          >
            {{ submitError }}
          </VAlert>

          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            class="mb-6"
            rounded="lg"
          >
            <strong>Precios y ganancia:</strong> la conversión solo mueve cantidades. Los precios de compra van en <strong>Compras → Registrar</strong> (precio unitario por línea). El costo de referencia del producto está en <strong>Productos</strong> como <em>Precio de compra / costo</em>; la ganancia típica es precio de venta menos ese costo (no hay un módulo de margen global todavía).
          </VAlert>

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
                v-model="warehouseId"
                label="Almacén *"
                :items="warehouseItems"
                :disabled="!branchId"
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            />
            <VCol cols="12">
              <VTextField
                v-model="productSearch"
                label="Buscar producto"
                prepend-inner-icon="ri-search-line"
                density="comfortable"
                clearable
                :loading="productSearchLoading"
                :disabled="!warehouseId"
                @update:model-value="onProductSearchInput"
              />
              <VSelect
                v-model="productId"
                label="Producto *"
                :items="productSelectItems"
                item-title="title"
                item-value="value"
                clearable
                density="comfortable"
                class="mt-2"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
              md="4"
            >
              <VSelect
                v-model="unitStartId"
                label="Unidad origen *"
                :items="unitItems"
                clearable
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
              md="4"
            >
              <VSelect
                v-model="unitEndId"
                label="Unidad destino *"
                :items="unitItems"
                clearable
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="6"
              md="2"
            >
              <VTextField
                v-model="quantityStart"
                label="Cant. origen"
                type="number"
                min="0.0001"
                step="any"
                density="comfortable"
              />
            </VCol>
            <VCol
              cols="6"
              md="2"
            >
              <VTextField
                v-model="quantityEnd"
                label="Cant. destino"
                type="number"
                min="0.0001"
                step="any"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="description"
                label="Descripción (opcional)"
                rows="2"
                density="comfortable"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            color="primary"
            size="large"
            prepend-icon="ri-save-line"
            :loading="submitting"
            @click="submitForm"
          >
            Guardar conversión
          </VBtn>
        </VCardActions>
      </VCard>
    </div>
  </div>
</template>
