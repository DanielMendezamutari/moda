<script setup>
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { formatPermissionLabel } from '@/utils/permissionLabels'
import { $api } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'roles-permisos',
  },
})

const authStore = useAuthStore()

const search = ref('')
const loading = ref(false)
const errorMsg = ref('')
const roles = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const detailOpen = ref(false)
const detailRole = ref(null)

const createDialogOpen = ref(false)
const createSubmitting = ref(false)
const createFormError = ref('')
const availablePermissions = ref([])

const permissionSelectItems = computed(() =>
  availablePermissions.value.map(p => ({
    title: formatPermissionLabel(p),
    value: p,
  })),
)

const createForm = reactive({
  name: '',
  permissions: [],
})

const editDialogOpen = ref(false)
const editSubmitting = ref(false)
const editFormError = ref('')
const editForm = reactive({
  id: null,
  name: '',
  permissions: [],
  is_system: false,
})

const deleteDialogOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref(null)

const headers = [
  { title: 'Rol', key: 'name', sortable: true },
  { title: 'Nombre', key: 'display_name', sortable: true },
  { title: 'Fecha de registro', key: 'created_at', sortable: true },
  { title: 'Permisos', key: 'permissions', sortable: false },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: '140px' },
]

async function fetchRoles() {
  errorMsg.value = ''
  loading.value = true

  try {
    const res = await $api('/roles')

    roles.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    roles.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tienes permiso para ver los roles. Solo los administradores pueden acceder a esta información.',
      fallback: 'No se pudieron cargar los roles.',
    })
  }
  finally {
    loading.value = false
  }
}

async function fetchPermissions() {
  try {
    const res = await $api('/permissions')

    availablePermissions.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    availablePermissions.value = []
  }
}

function openCreateDialog() {
  createForm.name = ''
  createForm.permissions = []
  createFormError.value = ''
  createDialogOpen.value = true
  fetchPermissions()
}

function closeCreateDialog() {
  createDialogOpen.value = false
}

async function submitCreateRole() {
  createFormError.value = ''
  const name = createForm.name.trim()
  if (!name) {
    createFormError.value = 'Indica el nombre del rol.'

    return
  }

  createSubmitting.value = true

  try {
    await $api('/roles', {
      method: 'POST',
      body: {
        name,
        permissions: createForm.permissions,
      },
    })

    snackbar.text = 'Rol registrado correctamente.'
    snackbar.show = true
    closeCreateDialog()
    await fetchRoles()
  }
  catch (e) {
    createFormError.value = messageFromApiError(e, {
      forbidden: 'No tienes permiso para crear roles.',
      fallback: 'No se pudo crear el rol.',
    })
  }
  finally {
    createSubmitting.value = false
  }
}

onMounted(async () => {
  await authStore.fetchMe()
  if (authStore.isAdmin)
    fetchRoles()
})

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return roles.value

  return roles.value.filter((row) => {
    const inName = row.name?.toLowerCase().includes(q)
    const inDisplay = row.display_name?.toLowerCase().includes(q)
    const inPerm = row.permissions?.some((p) => {
      const tech = String(p).toLowerCase()

      return tech.includes(q) || formatPermissionLabel(p).toLowerCase().includes(q)
    })

    return inName || inDisplay || inPerm
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

function openDetail(row) {
  detailRole.value = row
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
}

watch(detailOpen, open => {
  if (!open)
    detailRole.value = null
})

function openEdit(row) {
  editForm.id = row.id
  editForm.name = row.name || ''
  editForm.permissions = Array.isArray(row.permissions) ? [...row.permissions] : []
  editForm.is_system = !!row.is_system
  editFormError.value = ''
  editDialogOpen.value = true
  fetchPermissions()
}

function closeEditDialog() {
  editDialogOpen.value = false
}

async function submitEditRole() {
  editFormError.value = ''

  if (!editForm.is_system) {
    const n = editForm.name.trim()
    if (!n) {
      editFormError.value = 'Indica el nombre del rol.'

      return
    }
  }

  editSubmitting.value = true

  try {
    const body = {
      permissions: editForm.permissions,
    }
    if (!editForm.is_system)
      body.name = editForm.name.trim()

    await $api(`/roles/${editForm.id}`, {
      method: 'PATCH',
      body,
    })

    snackbar.text = 'Rol actualizado correctamente.'
    snackbar.show = true
    closeEditDialog()
    await fetchRoles()
  }
  catch (e) {
    editFormError.value = messageFromApiError(e, {
      forbidden: 'No tienes permiso para editar roles.',
      fallback: 'No se pudo actualizar el rol.',
    })
  }
  finally {
    editSubmitting.value = false
  }
}

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

async function confirmDeleteRole() {
  if (!deleteTarget.value)
    return

  deleteSubmitting.value = true

  try {
    await $api(`/roles/${deleteTarget.value.id}`, {
      method: 'DELETE',
    })

    snackbar.text = 'Rol eliminado.'
    snackbar.show = true
    closeDeleteDialog()
    await fetchRoles()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      forbidden: 'No tienes permiso para eliminar roles.',
      fallback: 'No se pudo eliminar el rol.',
    })
    snackbar.show = true
  }
  finally {
    deleteSubmitting.value = false
  }
}

function onEdit(row) {
  openEdit(row)
}

function onDelete(row) {
  openDelete(row)
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
                Roles y permisos
              </VCardTitle>
              <VCardSubtitle class="text-wrap pa-0">
                Roles del sistema (guard <code>api</code>) y permisos asignados desde Spatie Laravel Permission.
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
                Nuevo rol
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
                @click="fetchRoles"
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
              class="roles-table text-no-wrap"
              hover
            >
          <template #item.created_at="{ item }">
            {{ formatDate(item.created_at) }}
          </template>

          <template #item.permissions="{ item }">
            <div class="d-flex flex-wrap gap-1 py-1 permissions-cell">
              <VChip
                v-for="perm in item.permissions"
                :key="perm"
                size="small"
                label
                variant="tonal"
                color="primary"
              >
                {{ formatPermissionLabel(perm) }}
              </VChip>
              <span
                v-if="!item.permissions?.length"
                class="text-medium-emphasis text-body-2"
              >Sin permisos</span>
            </div>
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
                @click="onEdit(item)"
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
                :disabled="item.is_system"
                @click="onDelete(item)"
              >
                <VIcon icon="ri-delete-bin-line" />
                <VTooltip
                  activator="parent"
                  location="top"
                >
                  {{ item.is_system ? 'No se pueden eliminar roles base' : 'Eliminar' }}
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
          <VCardTitle>Registrar rol</VCardTitle>
          <VCardSubtitle>
            Identificador del rol y permisos del guard <code>api</code>.
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

          <VForm @submit.prevent="submitCreateRole">
            <VTextField
              v-model="createForm.name"
              label="Nombre del rol"
              placeholder="ej. supervisor"
              hint="Letras minúsculas, números, guiones y guión bajo (alpha_dash)."
              persistent-hint
              density="comfortable"
              class="mb-4"
              autocomplete="off"
              :disabled="createSubmitting"
            />

            <div class="text-body-2 font-weight-medium mb-2">
              Permisos
            </div>
            <VSelect
              v-model="createForm.permissions"
              :items="permissionSelectItems"
              item-title="title"
              item-value="value"
              label="Seleccionar permisos"
              multiple
              chips
              closable-chips
              density="comfortable"
              variant="outlined"
              hide-details="auto"
              class="mb-6"
              :disabled="createSubmitting || !availablePermissions.length"
              no-data-text="No hay permisos disponibles."
            />

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
          <VCardTitle>Editar rol</VCardTitle>
          <VCardSubtitle>
            <template v-if="editForm.is_system">
              Los roles <strong>admin</strong> y <strong>cajero</strong> solo permiten cambiar permisos.
            </template>
            <template v-else>
              Identificador del rol y permisos del guard <code>api</code>.
            </template>
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

          <VForm @submit.prevent="submitEditRole">
            <VTextField
              v-model="editForm.name"
              label="Nombre del rol"
              placeholder="ej. supervisor"
              hint="Letras minúsculas, números, guiones y guión bajo (alpha_dash)."
              persistent-hint
              density="comfortable"
              class="mb-4"
              autocomplete="off"
              :disabled="editSubmitting || editForm.is_system"
              :readonly="editForm.is_system"
            />

            <div class="text-body-2 font-weight-medium mb-2">
              Permisos
            </div>
            <VSelect
              v-model="editForm.permissions"
              :items="permissionSelectItems"
              item-title="title"
              item-value="value"
              label="Seleccionar permisos"
              multiple
              chips
              closable-chips
              density="comfortable"
              variant="outlined"
              hide-details="auto"
              class="mb-6"
              :disabled="editSubmitting || !availablePermissions.length"
              no-data-text="No hay permisos disponibles."
            />

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
      v-model="deleteDialogOpen"
      :width="$vuetify.display.smAndDown ? 'auto' : 440"
    >
      <VCard v-if="deleteTarget">
        <VCardItem>
          <VCardTitle>Eliminar rol</VCardTitle>
          <VCardSubtitle>
            ¿Eliminar el rol <strong>{{ deleteTarget.name }}</strong>? Solo es posible si ningún usuario lo tiene asignado.
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
            @click="confirmDeleteRole"
          >
            Eliminar
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="detailOpen"
      :width="$vuetify.display.smAndDown ? 'auto' : 520"
    >
      <VCard v-if="detailRole">
        <VCardItem>
          <VCardTitle>{{ detailRole.display_name }}</VCardTitle>
          <VCardSubtitle>Rol: {{ detailRole.name }}</VCardSubtitle>
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
          <p class="text-body-2 mb-4">
            <span class="font-weight-medium text-high-emphasis">Fecha de registro:</span>
            {{ formatDate(detailRole.created_at) }}
          </p>
          <p class="text-body-2 font-weight-medium text-high-emphasis mb-2">
            Permisos
          </p>
          <div class="d-flex flex-wrap gap-2">
            <VChip
              v-for="perm in detailRole.permissions"
              :key="perm"
              label
              size="small"
              variant="tonal"
              color="primary"
            >
              {{ formatPermissionLabel(perm) }}
            </VChip>
            <span
              v-if="!detailRole.permissions?.length"
              class="text-medium-emphasis"
            >Sin permisos asignados.</span>
          </div>
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
.permissions-cell {
  max-inline-size: min(100%, 420px);
}

.roles-table :deep(th) {
  font-weight: 600;
}

code {
  font-size: 0.85em;
}
</style>
