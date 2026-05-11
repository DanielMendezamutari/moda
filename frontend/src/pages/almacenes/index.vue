<script setup>
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'
import { useBoliviaDepartments } from '@/composables/useBoliviaDepartments'

const {
  departments: boliviaDepartments,
  loading: boliviaDepartmentsLoading,
  load: loadBoliviaDepartments,
} = useBoliviaDepartments()

definePage({
  meta: {
    navActiveLink: 'almacenes',
  },
})

const authStore = useAuthStore()

const search = ref('')
const loading = ref(false)
const errorMsg = ref('')
const warehouses = ref([])
const branchOptions = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const detailOpen = ref(false)
const detailWarehouse = ref(null)

const createDialogOpen = ref(false)
const createSubmitting = ref(false)
const createFormError = ref('')
const createForm = reactive({
  name: '',
  address: '',
  branch_id: null,
  state: '',
})

const editDialogOpen = ref(false)
const editSubmitting = ref(false)
const editFormError = ref('')
const editForm = reactive({
  id: null,
  name: '',
  address: '',
  branch_id: null,
  state: '',
})

const deleteDialogOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref(null)

const headers = [
  { title: 'Nombre', key: 'name', sortable: true },
  { title: 'Dirección', key: 'address', sortable: false },
  { title: 'Sucursal', key: 'branch', sortable: false },
  { title: 'Departamento', key: 'state', sortable: true },
  { title: 'Registro', key: 'created_at', sortable: true },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: '140px' },
]

const branchSelectItems = computed(() =>
  branchOptions.value.map(b => ({
    title: b.code ? `${b.name} (${b.code})` : b.name,
    value: b.id,
  })),
)

async function fetchWarehouses() {
  errorMsg.value = ''
  loading.value = true

  try {
    const res = await $api('/warehouses')

    warehouses.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    warehouses.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver almacenes.',
      fallback: 'No se pudieron cargar los almacenes.',
    })
  }
  finally {
    loading.value = false
  }
}

async function fetchBranchOptions() {
  try {
    const res = await $api('/branches')

    branchOptions.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    branchOptions.value = []
  }
}

function openCreateDialog() {
  createForm.name = ''
  createForm.address = ''
  createForm.branch_id = branchSelectItems.value[0]?.value ?? null
  createForm.state = ''
  createFormError.value = ''
  createDialogOpen.value = true
  fetchBranchOptions()
}

function closeCreateDialog() {
  createDialogOpen.value = false
}

async function submitCreateWarehouse() {
  createFormError.value = ''
  const name = createForm.name.trim()
  const address = createForm.address.trim()
  const state = createForm.state.trim()

  if (!name || !address || !state) {
    createFormError.value = 'Completá nombre, dirección y departamento del almacén.'

    return
  }
  if (createForm.branch_id == null || createForm.branch_id === '') {
    createFormError.value = 'Seleccioná la sucursal.'

    return
  }

  createSubmitting.value = true

  try {
    await $api('/warehouses', {
      method: 'POST',
      body: {
        name,
        address,
        branch_id: createForm.branch_id,
        state,
      },
    })

    snackbar.text = 'Almacén registrado correctamente.'
    snackbar.show = true
    closeCreateDialog()
    await fetchWarehouses()
  }
  catch (e) {
    createFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para crear almacenes.',
      fallback: 'No se pudo crear el almacén.',
    })
  }
  finally {
    createSubmitting.value = false
  }
}

function openEdit(row) {
  editForm.id = row.id
  editForm.name = row.name || ''
  editForm.address = row.address || ''
  editForm.branch_id = row.branch_id ?? null
  editForm.state = row.state || ''
  editFormError.value = ''
  editDialogOpen.value = true
  fetchBranchOptions()
}

function closeEditDialog() {
  editDialogOpen.value = false
}

async function submitEditWarehouse() {
  editFormError.value = ''
  const name = editForm.name.trim()
  const address = editForm.address.trim()
  const state = editForm.state.trim()

  if (!name || !address || !state) {
    editFormError.value = 'Completá nombre, dirección y departamento del almacén.'

    return
  }
  if (editForm.branch_id == null || editForm.branch_id === '') {
    editFormError.value = 'Seleccioná la sucursal.'

    return
  }

  editSubmitting.value = true

  try {
    await $api(`/warehouses/${editForm.id}`, {
      method: 'PATCH',
      body: {
        name,
        address,
        branch_id: editForm.branch_id,
        state,
      },
    })

    snackbar.text = 'Almacén actualizado correctamente.'
    snackbar.show = true
    closeEditDialog()
    await fetchWarehouses()
  }
  catch (e) {
    editFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para editar almacenes.',
      fallback: 'No se pudo actualizar el almacén.',
    })
  }
  finally {
    editSubmitting.value = false
  }
}

function openDetail(row) {
  detailWarehouse.value = row
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
}

watch(detailOpen, open => {
  if (!open)
    detailWarehouse.value = null
})

function openDelete(row) {
  deleteTarget.value = row
  deleteDialogOpen.value = true
}

function closeDeleteDialog() {
  deleteDialogOpen.value = false
}

watch(deleteDialogOpen, open => {
  if (!open)
    deleteTarget.value = null
})

async function confirmDeleteWarehouse() {
  if (!deleteTarget.value)
    return

  deleteSubmitting.value = true

  try {
    await $api(`/warehouses/${deleteTarget.value.id}`, {
      method: 'DELETE',
    })

    snackbar.text = 'Almacén eliminado.'
    snackbar.show = true
    closeDeleteDialog()
    await fetchWarehouses()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      forbidden: 'No tenés permiso para eliminar almacenes.',
      fallback: 'No se pudo eliminar el almacén.',
    })
    snackbar.show = true
  }
  finally {
    deleteSubmitting.value = false
  }
}

onMounted(async () => {
  await authStore.fetchMe()
  if (authStore.isAdmin) {
    await loadBoliviaDepartments()
    await fetchBranchOptions()
    fetchWarehouses()
  }
})

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return warehouses.value

  return warehouses.value.filter((row) => {
    const inName = row.name?.toLowerCase().includes(q)
    const inAddr = String(row.address || '').toLowerCase().includes(q)
    const inState = String(row.state || '').toLowerCase().includes(q)
    const inBranch = row.branch?.name?.toLowerCase().includes(q)

    return inName || inAddr || inState || inBranch
  })
})

function formatDate(iso) {
  if (!iso)
    return '—'

  try {
    return new Intl.DateTimeFormat('es', {
      dateStyle: 'short',
      timeStyle: 'short',
    }).format(new Date(iso))
  }
  catch {
    return '—'
  }
}
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
      v-else-if="!authStore.isAdmin"
      class="text-center"
    >
      <VCardText class="py-12 px-6">
        <VAvatar
          color="warning"
          variant="tonal"
          size="72"
          class="mb-6"
        >
          <VIcon
            icon="ri-shield-cross-line"
            size="40"
          />
        </VAvatar>
        <h2 class="text-h5 font-weight-medium mb-3">
          Acceso restringido
        </h2>
        <p class="text-body-1 text-medium-emphasis mb-2 mx-auto" style="max-width: 420px;">
          Esta sección solo está disponible para usuarios con rol de administrador.
        </p>
        <p class="text-body-2 text-disabled mb-8">
          Si necesitás estos permisos, contactá a un administrador del sistema.
        </p>
        <VBtn
          color="primary"
          prepend-icon="ri-home-4-line"
          :to="{ name: 'root' }"
        >
          Volver al inicio
        </VBtn>
      </VCardText>
    </VCard>

    <template v-else>
      <VCard>
        <VCardText class="pb-4">
          <VRow align="center">
            <VCol cols="12" lg="7">
              <VCardTitle class="text-h5 pa-0 pb-1">
                Almacenes
              </VCardTitle>
              <VCardSubtitle class="text-wrap pa-0">
                Almacenes ligados a una sucursal. «Departamento» es la división administrativa en Bolivia (9 departamentos), distinta del nombre de la sucursal o de la dirección.
              </VCardSubtitle>
            </VCol>
            <VCol
              cols="12"
              lg="5"
              class="d-flex flex-column flex-sm-row flex-wrap gap-3 align-stretch align-sm-center justify-lg-end"
            >
              <VBtn
                color="primary"
                prepend-icon="ri-add-line"
                @click="openCreateDialog"
              >
                Nuevo almacén
              </VBtn>
              <VTextField
                v-model="search"
                density="compact"
                placeholder="Buscar…"
                prepend-inner-icon="ri-search-line"
                hide-details
                clearable
                single-line
                class="flex-grow-1"
                style="min-width: 0;"
              />
              <VBtn
                color="primary"
                variant="tonal"
                icon
                :loading="loading"
                @click="fetchWarehouses"
              >
                <VIcon icon="ri-refresh-line" />
                <VTooltip
                  activator="parent"
                  location="bottom"
                >
                  Actualizar listado
                </VTooltip>
              </VBtn>
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
            rounded
            class="overflow-x-auto"
          >
            <VDataTable
              :headers="headers"
              :items="filteredItems"
              :loading="loading"
              item-value="id"
              class="almacenes-table text-no-wrap"
              hover
            >
            <template #item.address="{ item }">
              <span class="text-wrap address-cell">{{ item.address || '—' }}</span>
            </template>

            <template #item.branch="{ item }">
              <span v-if="item.branch?.name">{{ item.branch.name }}</span>
              <span
                v-else
                class="text-medium-emphasis text-body-2"
              >—</span>
            </template>

            <template #item.created_at="{ item }">
              {{ formatDate(item.created_at) }}
            </template>

            <template #item.actions="{ item }">
              <div class="d-flex align-center justify-end gap-1">
                <VBtn
                  icon
                  variant="text"
                  size="small"
                  color="medium-emphasis"
                  @click="openDetail(item)"
                >
                  <VIcon icon="ri-eye-line" />
                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    Ver detalle
                  </VTooltip>
                </VBtn>

                <VBtn
                  icon
                  variant="text"
                  size="small"
                  color="medium-emphasis"
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
                No hay datos para mostrar.
              </div>
            </template>
            </VDataTable>
          </VSheet>
        </VCardText>
      </VCard>

      <VDialog
        v-model="createDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 560"
        scrollable
        @after-leave="createFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Nuevo almacén</VCardTitle>
            <VCardSubtitle>
              Asociá el almacén a una sucursal y completá ubicación.
            </VCardSubtitle>
            <template #append>
              <VBtn
                icon
                variant="text"
                @click="closeCreateDialog"
              >
                <VIcon icon="ri-close-line" />
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText class="pt-4">
            <VAlert
              v-if="createFormError"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
              rounded="lg"
            >
              {{ createFormError }}
            </VAlert>

            <VForm @submit.prevent="submitCreateWarehouse">
              <VTextField
                v-model="createForm.name"
                density="comfortable"
                class="mb-4"
                autocomplete="organization"
                :disabled="createSubmitting"
              >
                <template #label>
                  <span>Nombre <span class="text-error">*</span></span>
                </template>
              </VTextField>

              <VSelect
                v-model="createForm.branch_id"
                :items="branchSelectItems"
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-4"
                :disabled="createSubmitting || !branchSelectItems.length"
                no-data-text="No hay sucursales. Creá una en Configuraciones → Sucursales."
              >
                <template #label>
                  <span>Sucursal <span class="text-error">*</span></span>
                </template>
              </VSelect>

              <VTextarea
                v-model="createForm.address"
                rows="3"
                auto-grow
                density="comfortable"
                class="mb-4"
                :disabled="createSubmitting"
              >
                <template #label>
                  <span>Dirección <span class="text-error">*</span></span>
                </template>
              </VTextarea>

              <VSelect
                v-model="createForm.state"
                :items="boliviaDepartments"
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-2"
                autocomplete="address-level1"
                :disabled="createSubmitting"
                :loading="boliviaDepartmentsLoading"
                :menu-props="{ maxHeight: 320 }"
                no-data-text="No se pudieron cargar los departamentos."
              >
                <template #label>
                  <span>Departamento <span class="text-error">*</span></span>
                </template>
              </VSelect>
              <p class="text-caption text-medium-emphasis mb-6">
                Corresponde al departamento boliviano donde está el almacén; no indica si está «activo» u ocupado.
              </p>

              <div class="d-flex justify-end gap-2">
                <VBtn
                  variant="text"
                  :disabled="createSubmitting"
                  @click="closeCreateDialog"
                >
                  Cancelar
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="createSubmitting"
                >
                  Guardar
                </VBtn>
              </div>
            </VForm>
          </VCardText>
        </VCard>
      </VDialog>

      <VDialog
        v-model="editDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 560"
        scrollable
        @after-leave="editFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Editar almacén</VCardTitle>
            <VCardSubtitle>
              Actualizá datos del almacén y la sucursal.
            </VCardSubtitle>
            <template #append>
              <VBtn
                icon
                variant="text"
                @click="closeEditDialog"
              >
                <VIcon icon="ri-close-line" />
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText class="pt-4">
            <VAlert
              v-if="editFormError"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
              rounded="lg"
            >
              {{ editFormError }}
            </VAlert>

            <VForm @submit.prevent="submitEditWarehouse">
              <VTextField
                v-model="editForm.name"
                density="comfortable"
                class="mb-4"
                autocomplete="organization"
                :disabled="editSubmitting"
              >
                <template #label>
                  <span>Nombre <span class="text-error">*</span></span>
                </template>
              </VTextField>

              <VSelect
                v-model="editForm.branch_id"
                :items="branchSelectItems"
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-4"
                :disabled="editSubmitting || !branchSelectItems.length"
                no-data-text="No hay sucursales."
              >
                <template #label>
                  <span>Sucursal <span class="text-error">*</span></span>
                </template>
              </VSelect>

              <VTextarea
                v-model="editForm.address"
                rows="3"
                auto-grow
                density="comfortable"
                class="mb-4"
                :disabled="editSubmitting"
              >
                <template #label>
                  <span>Dirección <span class="text-error">*</span></span>
                </template>
              </VTextarea>

              <VSelect
                v-model="editForm.state"
                :items="boliviaDepartments"
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-2"
                autocomplete="address-level1"
                :disabled="editSubmitting"
                :loading="boliviaDepartmentsLoading"
                :menu-props="{ maxHeight: 320 }"
                no-data-text="No se pudieron cargar los departamentos."
              >
                <template #label>
                  <span>Departamento <span class="text-error">*</span></span>
                </template>
              </VSelect>
              <p class="text-caption text-medium-emphasis mb-6">
                Corresponde al departamento boliviano donde está el almacén; no indica si está «activo» u ocupado.
              </p>

              <div class="d-flex justify-end gap-2">
                <VBtn
                  variant="text"
                  :disabled="editSubmitting"
                  @click="closeEditDialog"
                >
                  Cancelar
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="editSubmitting"
                >
                  Guardar cambios
                </VBtn>
              </div>
            </VForm>
          </VCardText>
        </VCard>
      </VDialog>

      <VDialog
        v-model="detailOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 520"
      >
        <VCard v-if="detailWarehouse">
          <VCardItem>
            <VCardTitle>{{ detailWarehouse.name }}</VCardTitle>
            <VCardSubtitle>
              Sucursal: {{ detailWarehouse.branch?.name ?? '—' }}
            </VCardSubtitle>
            <template #append>
              <VBtn
                icon
                variant="text"
                @click="closeDetail"
              >
                <VIcon icon="ri-close-line" />
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium text-high-emphasis">Dirección:</span>
              {{ detailWarehouse.address || '—' }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium text-high-emphasis">Departamento:</span>
              {{ detailWarehouse.state || '—' }}
            </p>
            <p class="text-body-2 mb-4">
              <span class="font-weight-medium text-high-emphasis">Registro:</span>
              {{ formatDate(detailWarehouse.created_at) }}
            </p>
          </VCardText>
          <VDivider />
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="flat"
              @click="closeDetail"
            >
              Cerrar
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <VDialog
        v-model="deleteDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 440"
      >
        <VCard v-if="deleteTarget">
          <VCardItem>
            <VCardTitle>Eliminar almacén</VCardTitle>
            <VCardSubtitle>
              ¿Eliminar <strong>{{ deleteTarget.name }}</strong>?
            </VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardActions class="pa-4">
            <VSpacer />
            <VBtn
              variant="text"
              :disabled="deleteSubmitting"
              @click="closeDeleteDialog"
            >
              Cancelar
            </VBtn>
            <VBtn
              color="error"
              variant="flat"
              :loading="deleteSubmitting"
              @click="confirmDeleteWarehouse"
            >
              Eliminar
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <VSnackbar
        v-model="snackbar.show"
        location="bottom end"
        :timeout="2800"
      >
        {{ snackbar.text }}
      </VSnackbar>
    </template>
  </div>
</template>

<style scoped lang="scss">
.almacenes-table :deep(th) {
  font-weight: 600;
}

.address-cell {
  display: inline-block;
  max-inline-size: min(100%, 280px);
  white-space: normal;
  line-height: 1.35;
}
</style>
