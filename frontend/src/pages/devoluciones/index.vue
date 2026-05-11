<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'devoluciones',
  },
})

const authStore = useAuthStore()

const canAccess = computed(() =>
  authStore.isAdmin || authStore.hasRole('cashier'),
)

const search = ref('')
const stateFilter = ref(null)
const typeFilter = ref(null)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const errorMsg = ref('')
const returns = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const TYPE_OPTIONS = [
  { value: 'reparacion', title: 'Reparación' },
  { value: 'remplazo', title: 'Reemplazo' },
  { value: 'devolucion', title: 'Devolución' },
]

const STATE_OPTIONS = [
  { value: 'pendiente', title: 'Pendiente' },
  { value: 'revision', title: 'Revisión' },
  { value: 'reparado', title: 'Reparado' },
  { value: 'descartado', title: 'Descartado' },
]

const headers = [
  { title: 'ID', key: 'id', sortable: false, width: '72px' },
  { title: 'Producto', key: 'product', sortable: false, minWidth: '160px' },
  { title: 'Cant.', key: 'quantity', sortable: false },
  { title: 'Tipo', key: 'type', sortable: false },
  { title: 'Estado', key: 'state', sortable: false },
  { title: 'Cliente', key: 'client', sortable: false },
  { title: 'Registro', key: 'created_at', sortable: false },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: '120px' },
]

function typeLabel(v) {
  return TYPE_OPTIONS.find(t => t.value === v)?.title ?? v ?? '—'
}

function stateLabel(v) {
  return STATE_OPTIONS.find(s => s.value === v)?.title ?? v ?? '—'
}

function stateColor(v) {
  if (v === 'reparado')
    return 'success'
  if (v === 'descartado')
    return 'error'
  if (v === 'revision')
    return 'warning'

  return 'secondary'
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

async function fetchReturns() {
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
    if (typeFilter.value)
      q.set('type', typeFilter.value)

    const res = await $api(`/product-returns?${q.toString()}`)

    returns.value = Array.isArray(res?.data) ? res.data : []
    lastPage.value = Number(res?.last_page) || 1
    total.value = Number(res?.total) ?? returns.value.length
  }
  catch (e) {
    returns.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver devoluciones.',
      fallback: 'No se pudieron cargar las devoluciones.',
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
    fetchReturns()
  }, 380)
}

watch(page, () => {
  fetchReturns()
})

watch([stateFilter, typeFilter], () => {
  page.value = 1
  fetchReturns()
})

/** --- Alta: elegir línea desde venta --- */
const createOpen = ref(false)
const createSubmitting = ref(false)
const createError = ref('')
const saleIdInput = ref('')
const saleLoading = ref(false)
const saleForPick = ref(null)
const createForm = reactive({
  sale_detail_id: null,
  quantity: '1',
  type: 'devolucion',
  state: 'pendiente',
  description: '',
})

function resetCreateForm() {
  saleIdInput.value = ''
  saleForPick.value = null
  createForm.sale_detail_id = null
  createForm.quantity = '1'
  createForm.type = 'devolucion'
  createForm.state = 'pendiente'
  createForm.description = ''
  createError.value = ''
}

function openCreate() {
  resetCreateForm()
  createOpen.value = true
}

function closeCreate() {
  createOpen.value = false
}

const saleLineItems = computed(() => {
  const items = saleForPick.value?.items || saleForPick.value?.sale_details || []
  return Array.isArray(items) ? items : []
})

watch(() => createForm.sale_detail_id, (id) => {
  if (!id || !saleForPick.value)
    return
  const line = saleLineItems.value.find(l => Number(l.id) === Number(id))
  if (line)
    createForm.quantity = String(Math.max(1, Math.floor(Number(line.quantity) || 1)))
})

async function loadSaleForReturn() {
  const id = Number(String(saleIdInput.value || '').trim())
  if (!Number.isFinite(id) || id <= 0) {
    createError.value = 'Ingresá un ID de venta válido.'

    return
  }

  createError.value = ''
  saleLoading.value = true
  saleForPick.value = null
  createForm.sale_detail_id = null

  try {
    const res = await $api(`/sales/${id}`)
    saleForPick.value = res?.data ?? null
    if (saleForPick.value?.state_sale === 'cancelled') {
      createError.value = 'Esta venta está anulada; no se registran devoluciones.'
      saleForPick.value = null
    }
  }
  catch (e) {
    saleForPick.value = null
    createError.value = messageFromApiError(e, {
      forbidden: 'No podés ver esa venta.',
      fallback: 'No se encontró la venta.',
    })
  }
  finally {
    saleLoading.value = false
  }
}

async function submitCreate() {
  createError.value = ''
  const detailId = createForm.sale_detail_id
  if (!detailId) {
    createError.value = 'Elegí una línea de la venta.'

    return
  }

  createSubmitting.value = true
  try {
    await $api('/product-returns', {
      method: 'POST',
      body: {
        sale_detail_id: Number(detailId),
        quantity: Number(String(createForm.quantity || '').replace(',', '.')),
        type: createForm.type,
        state: createForm.state,
        description: String(createForm.description || '').trim() || null,
      },
    })
    snackbar.text = 'Devolución registrada.'
    snackbar.show = true
    closeCreate()
    await fetchReturns()
  }
  catch (e) {
    createError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para registrar devoluciones.',
      fallback: 'No se pudo guardar.',
    })
  }
  finally {
    createSubmitting.value = false
  }
}

/** --- Edición --- */
const editOpen = ref(false)
const editSubmitting = ref(false)
const editError = ref('')
const editRow = ref(null)
const editForm = reactive({
  quantity: '',
  type: 'devolucion',
  state: 'pendiente',
  description: '',
  description_resolution: '',
  resolution_date: '',
})

function openEdit(row) {
  editRow.value = row
  editForm.quantity = String(row.quantity ?? '1')
  editForm.type = row.type
  editForm.state = row.state
  editForm.description = row.description ?? ''
  editForm.description_resolution = row.description_resolution ?? ''
  editForm.resolution_date = row.resolution_date
    ? String(row.resolution_date).slice(0, 10)
    : ''
  editError.value = ''
  editOpen.value = true
}

function closeEdit() {
  editOpen.value = false
  editRow.value = null
}

async function submitEdit() {
  if (!editRow.value?.id)
    return

  editError.value = ''
  editSubmitting.value = true
  try {
    const body = {
      quantity: Number(String(editForm.quantity || '').replace(',', '.')),
      type: editForm.type,
      state: editForm.state,
      description: String(editForm.description || '').trim() || null,
      description_resolution: String(editForm.description_resolution || '').trim() || null,
    }
    if (editForm.resolution_date)
      body.resolution_date = `${editForm.resolution_date}T12:00:00`

    await $api(`/product-returns/${editRow.value.id}`, {
      method: 'PUT',
      body,
    })
    snackbar.text = 'Cambios guardados.'
    snackbar.show = true
    closeEdit()
    await fetchReturns()
  }
  catch (e) {
    editError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para editar.',
      fallback: 'No se pudo actualizar.',
    })
  }
  finally {
    editSubmitting.value = false
  }
}

const deleteOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref(null)

function openDelete(row) {
  deleteTarget.value = row
  deleteOpen.value = true
}

function closeDelete() {
  deleteOpen.value = false
  deleteTarget.value = null
}

async function confirmDelete() {
  if (!deleteTarget.value?.id)
    return

  deleteSubmitting.value = true
  try {
    await $api(`/product-returns/${deleteTarget.value.id}`, { method: 'DELETE' })
    snackbar.text = 'Registro eliminado.'
    snackbar.show = true
    closeDelete()
    await fetchReturns()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      fallback: 'No se pudo eliminar (¿ya ingresó stock?).',
    })
    snackbar.show = true
  }
  finally {
    deleteSubmitting.value = false
  }
}

onMounted(async () => {
  await authStore.fetchMe(true)
  if (canAccess.value)
    await fetchReturns()
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

    <VCard v-else-if="!canAccess">
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
          Sin acceso
        </h2>
        <p class="text-body-2 text-medium-emphasis mx-auto" style="max-width: 420px;">
          Solo administradores y cajeros pueden gestionar devoluciones.
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
              Devoluciones
            </VCardTitle>
            <VCardSubtitle class="pa-0">
              Reparación, reemplazo o devolución ligada a una línea de venta (<code>sale_details</code>). Al pasar a <strong>Reparado</strong> se ingresa stock al almacén de la línea (una sola vez).
            </VCardSubtitle>
          </div>
          <VBtn
            color="primary"
            prepend-icon="ri-add-line"
            @click="openCreate"
          >
            Nueva devolución
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

          <div class="d-flex flex-column flex-md-row flex-wrap gap-3 mb-4">
            <VTextField
              v-model="search"
              label="Buscar"
              density="comfortable"
              prepend-inner-icon="ri-search-line"
              clearable
              hide-details
              style="max-width: 280px;"
              @update:model-value="onSearchInput"
            />
            <VSelect
              v-model="stateFilter"
              label="Estado"
              :items="[{ title: 'Todos', value: null }, ...STATE_OPTIONS]"
              clearable
              density="comfortable"
              hide-details
              style="max-width: 200px;"
            />
            <VSelect
              v-model="typeFilter"
              label="Tipo"
              :items="[{ title: 'Todos', value: null }, ...TYPE_OPTIONS]"
              clearable
              density="comfortable"
              hide-details
              style="max-width: 200px;"
            />
            <div class="text-body-2 text-medium-emphasis d-flex align-center">
              {{ total }} resultado(s)
            </div>
          </div>

          <VDataTable
            :headers="headers"
            :items="returns"
            :loading="loading"
            hide-default-footer
            class="elevation-0"
          >
            <template #item.product="{ item }">
              <div class="font-weight-medium">
                {{ item.product?.name || '—' }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ item.warehouse?.name || '' }} · detalle #{{ item.sale_detail_id }}
              </div>
            </template>

            <template #item.type="{ item }">
              {{ typeLabel(item.type) }}
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

            <template #item.client="{ item }">
              <span v-if="item.client">{{ item.client.full_name }}</span>
              <span
                v-else
                class="text-medium-emphasis"
              >—</span>
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
                  @click="openEdit(item)"
                >
                  <VIcon icon="ri-edit-line" />
                  <VTooltip
                    activator="parent"
                    location="bottom"
                  >
                    Editar
                  </VTooltip>
                </VBtn>
                <VBtn
                  size="small"
                  variant="tonal"
                  color="error"
                  icon
                  :disabled="!!item.inventory_applied_at"
                  @click="openDelete(item)"
                >
                  <VIcon icon="ri-delete-bin-line" />
                  <VTooltip
                    activator="parent"
                    location="bottom"
                  >
                    Eliminar
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

      <!-- Alta -->
      <VDialog
        v-model="createOpen"
        max-width="560"
        scrollable
        @update:model-value="v => !v && closeCreate()"
      >
        <VCard>
          <VCardTitle>Nueva devolución</VCardTitle>
          <VDivider />
          <VCardText>
            <VAlert
              v-if="createError"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              {{ createError }}
            </VAlert>

            <div class="d-flex flex-wrap gap-2 mb-4">
              <VTextField
                v-model="saleIdInput"
                label="ID de venta"
                type="number"
                density="comfortable"
                hide-details
                style="min-width: 140px;"
              />
              <VBtn
                color="primary"
                variant="tonal"
                :loading="saleLoading"
                @click="loadSaleForReturn"
              >
                Cargar venta
              </VBtn>
            </div>

            <template v-if="saleForPick">
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
                class="mb-4"
              >
                Venta #{{ saleForPick.id }} · {{ saleForPick.reference || 'sin ref.' }} · {{ saleForPick.client?.full_name || 'Mostrador' }}
              </VAlert>

              <VSelect
                v-model="createForm.sale_detail_id"
                label="Línea vendida"
                :items="saleLineItems.map(l => ({
                  value: l.id,
                  title: `${l.product?.name || 'Producto'} — cant. ${l.quantity} (${l.warehouse?.name || 'almacén'})`,
                }))"
                density="comfortable"
                class="mb-3"
              />

              <VTextField
                v-model="createForm.quantity"
                label="Cantidad a devolver"
                type="number"
                density="comfortable"
                class="mb-3"
              />

              <VSelect
                v-model="createForm.type"
                label="Tipo"
                :items="TYPE_OPTIONS"
                density="comfortable"
                class="mb-3"
              />

              <VSelect
                v-model="createForm.state"
                label="Estado inicial"
                :items="STATE_OPTIONS"
                density="comfortable"
                class="mb-3"
              />

              <VTextarea
                v-model="createForm.description"
                label="Descripción / motivo"
                rows="2"
                density="comfortable"
              />
            </template>
          </VCardText>
          <VCardActions class="justify-end">
            <VBtn
              variant="text"
              @click="closeCreate"
            >
              Cancelar
            </VBtn>
            <VBtn
              color="primary"
              :loading="createSubmitting"
              :disabled="!saleForPick || !createForm.sale_detail_id"
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
        max-width="520"
        scrollable
        @update:model-value="v => !v && closeEdit()"
      >
        <VCard>
          <VCardTitle>Editar devolución #{{ editRow?.id }}</VCardTitle>
          <VDivider />
          <VCardText>
            <VAlert
              v-if="editError"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              {{ editError }}
            </VAlert>

            <VAlert
              v-if="editRow?.inventory_applied_at"
              type="success"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              Stock ya ingresado el {{ formatDate(editRow.inventory_applied_at) }}. La cantidad no es editable.
            </VAlert>

            <VTextField
              v-model="editForm.quantity"
              label="Cantidad"
              type="number"
              density="comfortable"
              class="mb-3"
              :disabled="!!editRow?.inventory_applied_at"
            />

            <VSelect
              v-model="editForm.type"
              label="Tipo"
              :items="TYPE_OPTIONS"
              density="comfortable"
              class="mb-3"
            />

            <VSelect
              v-model="editForm.state"
              label="Estado"
              :items="STATE_OPTIONS"
              density="comfortable"
              class="mb-3"
            />

            <VTextField
              v-model="editForm.resolution_date"
              label="Fecha resolución"
              type="date"
              density="comfortable"
              class="mb-3"
            />

            <VTextarea
              v-model="editForm.description"
              label="Descripción"
              rows="2"
              density="comfortable"
              class="mb-3"
            />

            <VTextarea
              v-model="editForm.description_resolution"
              label="Notas de resolución"
              rows="2"
              density="comfortable"
            />
          </VCardText>
          <VCardActions class="justify-end">
            <VBtn
              variant="text"
              @click="closeEdit"
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
        max-width="420"
      >
        <VCard>
          <VCardTitle>Eliminar registro</VCardTitle>
          <VCardText class="text-body-2">
            ¿Eliminar la devolución #{{ deleteTarget?.id }}? Solo se permite si aún no se ingresó stock.
          </VCardText>
          <VCardActions class="justify-end">
            <VBtn
              variant="text"
              @click="closeDelete"
            >
              Cancelar
            </VBtn>
            <VBtn
              color="error"
              :loading="deleteSubmitting"
              @click="confirmDelete"
            >
              Eliminar
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </div>
  </div>
</template>
