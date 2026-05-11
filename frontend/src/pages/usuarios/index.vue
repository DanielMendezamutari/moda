<script setup>
import avatar1 from '@images/avatars/avatar-1.png'
import avatar2 from '@images/avatars/avatar-2.png'
import avatar3 from '@images/avatars/avatar-3.png'
import avatar4 from '@images/avatars/avatar-4.png'
import avatar5 from '@images/avatars/avatar-5.png'
import avatar6 from '@images/avatars/avatar-6.png'
import avatar7 from '@images/avatars/avatar-7.png'
import avatar8 from '@images/avatars/avatar-8.png'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'usuarios',
  },
})

const authStore = useAuthStore()

const avatarPresets = [
  { key: 'avatar-1', src: avatar1 },
  { key: 'avatar-2', src: avatar2 },
  { key: 'avatar-3', src: avatar3 },
  { key: 'avatar-4', src: avatar4 },
  { key: 'avatar-5', src: avatar5 },
  { key: 'avatar-6', src: avatar6 },
  { key: 'avatar-7', src: avatar7 },
  { key: 'avatar-8', src: avatar8 },
]

const avatarSrcByPreset = Object.fromEntries(avatarPresets.map(p => [p.key, p.src]))

const search = ref('')
const loading = ref(false)
const errorMsg = ref('')
const users = ref([])
const branches = ref([])
const roleOptions = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const detailOpen = ref(false)
const detailUser = ref(null)

const createDialogOpen = ref(false)
const createSubmitting = ref(false)
const createFormError = ref('')
const createAvatarFiles = ref([])
const createForm = reactive({
  name: '',
  email: '',
  document_number: '',
  gender: 'male',
  is_active: true,
  password: '',
  pin: '',
  branch_id: null,
  role: null,
  avatar_preset: null,
})

const editDialogOpen = ref(false)
const editSubmitting = ref(false)
const editFormError = ref('')
const editAvatarFiles = ref([])
const editForm = reactive({
  id: null,
  name: '',
  email: '',
  document_number: '',
  gender: 'male',
  is_active: true,
  password: '',
  pin: '',
  branch_id: null,
  role: null,
  avatar_preset: null,
})

const deleteDialogOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref(null)

const headers = [
  { title: '', key: 'avatar', sortable: false, width: '56px' },
  { title: 'Nombre', key: 'name', sortable: true },
  { title: 'Documento', key: 'document_number', sortable: true },
  { title: 'Correo', key: 'email', sortable: true },
  { title: 'Género', key: 'gender_label', sortable: false },
  { title: 'Sucursal', key: 'branch', sortable: false },
  { title: 'Rol', key: 'role_display', sortable: false },
  { title: 'Estado', key: 'is_active', sortable: false },
  { title: 'Fecha de registro', key: 'created_at', sortable: true },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: '140px' },
]

const branchItems = computed(() =>
  branches.value.map(b => ({
    title: b.code ? `${b.name} (${b.code})` : b.name,
    value: b.id,
  })),
)

const roleSelectItems = computed(() =>
  roleOptions.value.map(r => ({
    title: r.display_name || r.name,
    value: r.name,
  })),
)

const genderItems = [
  { title: 'Masculino', value: 'male' },
  { title: 'Femenino', value: 'female' },
]

function rowAvatarSrc(row) {
  if (row.avatar_url)
    return row.avatar_url
  if (row.avatar_preset && avatarSrcByPreset[row.avatar_preset])
    return avatarSrcByPreset[row.avatar_preset]

  return avatar1
}

function appendCommonUserFields(fd, form) {
  fd.append('name', form.name.trim())
  fd.append('email', form.email.trim())
  fd.append('document_number', form.document_number.trim())
  fd.append('gender', form.gender)
  fd.append('is_active', form.is_active ? '1' : '0')
  if (form.branch_id != null && form.branch_id !== '')
    fd.append('branch_id', String(form.branch_id))

  fd.append('role', form.role)
  const pin = form.pin?.trim()
  if (pin)
    fd.append('pin', pin)
}

function onCreateAvatarFilesChanged(files) {
  if (files?.length)
    createForm.avatar_preset = null
}

function onEditAvatarFilesChanged(files) {
  if (files?.length)
    editForm.avatar_preset = null
}

function selectCreatePreset(key) {
  createForm.avatar_preset = key
  createAvatarFiles.value = []
}

function selectEditPreset(key) {
  editForm.avatar_preset = key
  editAvatarFiles.value = []
}

async function fetchUsers() {
  errorMsg.value = ''
  loading.value = true

  try {
    const res = await $api('/users')

    users.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    users.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tienes permiso para ver usuarios. Solo los administradores pueden acceder a esta información.',
      fallback: 'No se pudieron cargar los usuarios.',
    })
  }
  finally {
    loading.value = false
  }
}

async function fetchBranches() {
  try {
    const res = await $api('/branches')

    branches.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    branches.value = []
  }
}

async function fetchRoleOptions() {
  try {
    const res = await $api('/roles')

    roleOptions.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    roleOptions.value = []
  }
}

function openCreateDialog() {
  createForm.name = ''
  createForm.email = ''
  createForm.document_number = ''
  createForm.gender = 'male'
  createForm.is_active = true
  createForm.password = ''
  createForm.pin = ''
  createForm.branch_id = null
  createForm.role = roleSelectItems.value[0]?.value ?? null
  createForm.avatar_preset = null
  createAvatarFiles.value = []
  createFormError.value = ''
  createDialogOpen.value = true
  fetchBranches()
  fetchRoleOptions()
}

function closeCreateDialog() {
  createDialogOpen.value = false
}

async function submitCreateUser() {
  createFormError.value = ''
  const name = createForm.name.trim()
  const email = createForm.email.trim()
  const doc = createForm.document_number.trim()
  const password = createForm.password

  if (!name || !email || !doc) {
    createFormError.value = 'Completá nombre, correo y número de documento.'

    return
  }
  if (!password || password.length < 8) {
    createFormError.value = 'La contraseña debe tener al menos 8 caracteres.'

    return
  }
  if (!createForm.role) {
    createFormError.value = 'Seleccioná un rol.'

    return
  }

  createSubmitting.value = true

  try {
    const fd = new FormData()

    appendCommonUserFields(fd, createForm)
    fd.append('password', password)

    const files = createAvatarFiles.value
    if (files?.length && files[0])
      fd.append('avatar', files[0])
    else if (createForm.avatar_preset)
      fd.append('avatar_preset', createForm.avatar_preset)

    await $api('/users', {
      method: 'POST',
      body: fd,
    })

    snackbar.text = 'Usuario registrado correctamente.'
    snackbar.show = true
    closeCreateDialog()
    await fetchUsers()
  }
  catch (e) {
    createFormError.value = messageFromApiError(e, {
      forbidden: 'No tienes permiso para crear usuarios.',
      fallback: 'No se pudo crear el usuario.',
    })
  }
  finally {
    createSubmitting.value = false
  }
}

function openEdit(row) {
  editForm.id = row.id
  editForm.name = row.name || ''
  editForm.email = row.email || ''
  editForm.document_number = row.document_number || ''
  editForm.gender = row.gender || 'male'
  editForm.is_active = !!row.is_active
  editForm.password = ''
  editForm.pin = ''
  editForm.branch_id = row.branch_id ?? null
  editForm.role = row.role ?? null
  editForm.avatar_preset = row.avatar_preset ?? null
  editAvatarFiles.value = []
  editFormError.value = ''
  editDialogOpen.value = true
  fetchBranches()
  fetchRoleOptions()
}

function closeEditDialog() {
  editDialogOpen.value = false
}

async function submitEditUser() {
  editFormError.value = ''
  const name = editForm.name.trim()
  const email = editForm.email.trim()
  const doc = editForm.document_number.trim()

  if (!name || !email || !doc) {
    editFormError.value = 'Completá nombre, correo y número de documento.'

    return
  }
  if (!editForm.role) {
    editFormError.value = 'Seleccioná un rol.'

    return
  }
  if (editForm.password && editForm.password.length < 8) {
    editFormError.value = 'La contraseña debe tener al menos 8 caracteres.'

    return
  }

  editSubmitting.value = true

  try {
    const fd = new FormData()

    appendCommonUserFields(fd, editForm)

    if (editForm.password.trim())
      fd.append('password', editForm.password)

    const files = editAvatarFiles.value
    if (files?.length && files[0]) {
      fd.append('avatar', files[0])
    }
    else if (editForm.avatar_preset) {
      fd.append('avatar_preset', editForm.avatar_preset)
    }

    /** POST: multipart + PATCH en PHP suele llegar vacío al servidor. */
    await $api(`/users/${editForm.id}`, {
      method: 'POST',
      body: fd,
    })

    snackbar.text = 'Usuario actualizado correctamente.'
    snackbar.show = true
    closeEditDialog()
    await fetchUsers()
  }
  catch (e) {
    editFormError.value = messageFromApiError(e, {
      forbidden: 'No tienes permiso para editar usuarios.',
      fallback: 'No se pudo actualizar el usuario.',
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

async function confirmDeleteUser() {
  if (!deleteTarget.value)
    return

  deleteSubmitting.value = true

  try {
    await $api(`/users/${deleteTarget.value.id}`, {
      method: 'DELETE',
    })

    snackbar.text = 'Usuario eliminado.'
    snackbar.show = true
    closeDeleteDialog()
    await fetchUsers()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      forbidden: 'No tienes permiso para eliminar usuarios.',
      fallback: 'No se pudo eliminar el usuario.',
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
    await fetchBranches()
    await fetchRoleOptions()
    fetchUsers()
  }
})

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return users.value

  return users.value.filter((row) => {
    const inName = row.name?.toLowerCase().includes(q)
    const inEmail = row.email?.toLowerCase().includes(q)
    const inDoc = String(row.document_number || '').toLowerCase().includes(q)
    const inBranch = row.branch?.name?.toLowerCase().includes(q)
    const inRole = row.role_display?.toLowerCase().includes(q)
      || row.role?.toLowerCase().includes(q)

    return inName || inEmail || inDoc || inBranch || inRole
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
  detailUser.value = row
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
}

watch(detailOpen, open => {
  if (!open)
    detailUser.value = null
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
                Usuarios
              </VCardTitle>
              <VCardSubtitle class="text-wrap pa-0">
                Gestión de cuentas: documento, género, estado, foto o avatar de plantilla (guard <code>api</code>).
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
                Nuevo usuario
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
                @click="fetchUsers"
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
              class="usuarios-table text-no-wrap"
              hover
            >
            <template #item.avatar="{ item }">
              <VAvatar
                :image="rowAvatarSrc(item)"
                size="36"
                rounded
              />
            </template>

            <template #item.branch="{ item }">
              <span v-if="item.branch?.name">{{ item.branch.name }}</span>
              <span
                v-else
                class="text-medium-emphasis text-body-2"
              >—</span>
            </template>

            <template #item.gender_label="{ item }">
              <span v-if="item.gender_label">{{ item.gender_label }}</span>
              <span
                v-else
                class="text-medium-emphasis text-body-2"
              >—</span>
            </template>

            <template #item.role_display="{ item }">
              <VChip
                v-if="item.role_display"
                size="small"
                label
                variant="tonal"
                color="primary"
              >
                {{ item.role_display }}
              </VChip>
              <span
                v-else
                class="text-medium-emphasis text-body-2"
              >—</span>
            </template>

            <template #item.is_active="{ item }">
              <VChip
                v-if="item.is_active"
                size="small"
                label
                color="success"
                variant="tonal"
              >
                Activo
              </VChip>
              <VChip
                v-else
                size="small"
                label
                color="error"
                variant="tonal"
              >
                Inactivo
              </VChip>
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
        :width="$vuetify.display.smAndDown ? 'auto' : 720"
        scrollable
        @after-leave="createFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Registrar usuario</VCardTitle>
            <VCardSubtitle>
              Envío con FormData: podés subir una imagen o elegir un avatar de la plantilla.
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

            <VForm @submit.prevent="submitCreateUser">
              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="createForm.name"
                    density="comfortable"
                    class="mb-1"
                    autocomplete="name"
                    :disabled="createSubmitting"
                  >
                    <template #label>
                      <span>Nombre completo <span class="text-error">*</span></span>
                    </template>
                  </VTextField>
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="createForm.email"
                    type="email"
                    density="comfortable"
                    class="mb-1"
                    autocomplete="off"
                    :disabled="createSubmitting"
                  >
                    <template #label>
                      <span>Correo electrónico <span class="text-error">*</span></span>
                    </template>
                  </VTextField>
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="createForm.document_number"
                    density="comfortable"
                    class="mb-1"
                    autocomplete="off"
                    :disabled="createSubmitting"
                  >
                    <template #label>
                      <span>Número de documento <span class="text-error">*</span></span>
                    </template>
                  </VTextField>
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="createForm.gender"
                    :items="genderItems"
                    density="comfortable"
                    variant="outlined"
                    hide-details="auto"
                    class="mb-1"
                    :disabled="createSubmitting"
                  >
                    <template #label>
                      <span>Género <span class="text-error">*</span></span>
                    </template>
                  </VSelect>
                </VCol>
                <VCol cols="12">
                  <div class="text-body-2 font-weight-medium mb-2">
                    Foto o avatar
                  </div>
                  <p class="text-body-2 text-medium-emphasis mb-3">
                    Subí una imagen o tocá un avatar (opcional).
                  </p>
                  <VFileInput
                    v-model="createAvatarFiles"
                    accept="image/*"
                    prepend-icon="ri-image-add-line"
                    show-size
                    class="mb-3"
                    :disabled="createSubmitting"
                    @update:model-value="onCreateAvatarFilesChanged"
                  />
                  <div class="d-flex flex-wrap gap-2 mb-4">
                    <VBtn
                      v-for="p in avatarPresets"
                      :key="p.key"
                      icon
                      variant="tonal"
                      size="small"
                      :color="createForm.avatar_preset === p.key ? 'primary' : undefined"
                      :disabled="createSubmitting"
                      @click="selectCreatePreset(p.key)"
                    >
                      <VAvatar
                        :image="p.src"
                        size="40"
                        rounded
                      />
                    </VBtn>
                  </div>
                </VCol>
                <VCol cols="12">
                  <VSwitch
                    v-model="createForm.is_active"
                    inset
                    color="primary"
                    label="Usuario activo"
                    density="comfortable"
                    hide-details
                    class="mb-2"
                    :disabled="createSubmitting"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="createForm.password"
                    type="password"
                    density="comfortable"
                    class="mb-1"
                    autocomplete="new-password"
                    :disabled="createSubmitting"
                  >
                    <template #label>
                      <span>Contraseña <span class="text-error">*</span></span>
                    </template>
                  </VTextField>
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="createForm.pin"
                    label="PIN POS (opcional)"
                    hint="Solo dígitos, entre 4 y 16."
                    persistent-hint
                    density="comfortable"
                    class="mb-1"
                    autocomplete="off"
                    :disabled="createSubmitting"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="createForm.branch_id"
                    :items="branchItems"
                    clearable
                    density="comfortable"
                    variant="outlined"
                    hide-details="auto"
                    class="mb-1"
                    label="Sucursal"
                    :disabled="createSubmitting || !branchItems.length"
                    no-data-text="No hay sucursales."
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="createForm.role"
                    :items="roleSelectItems"
                    density="comfortable"
                    variant="outlined"
                    hide-details="auto"
                    class="mb-1"
                    :disabled="createSubmitting || !roleSelectItems.length"
                    no-data-text="No hay roles disponibles."
                  >
                    <template #label>
                      <span>Rol <span class="text-error">*</span></span>
                    </template>
                  </VSelect>
                </VCol>
              </VRow>

              <div class="d-flex justify-end gap-2 mt-2">
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
        :width="$vuetify.display.smAndDown ? 'auto' : 720"
        scrollable
        @after-leave="editFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Editar usuario</VCardTitle>
            <VCardSubtitle>
              Envío con POST + FormData (compatible con PHP); contraseña y PIN solo si querés cambiarlos.
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

            <VForm @submit.prevent="submitEditUser">
              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="editForm.name"
                    density="comfortable"
                    class="mb-1"
                    autocomplete="name"
                    :disabled="editSubmitting"
                  >
                    <template #label>
                      <span>Nombre completo <span class="text-error">*</span></span>
                    </template>
                  </VTextField>
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="editForm.email"
                    type="email"
                    density="comfortable"
                    class="mb-1"
                    autocomplete="off"
                    :disabled="editSubmitting"
                  >
                    <template #label>
                      <span>Correo electrónico <span class="text-error">*</span></span>
                    </template>
                  </VTextField>
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="editForm.document_number"
                    density="comfortable"
                    class="mb-1"
                    autocomplete="off"
                    :disabled="editSubmitting"
                  >
                    <template #label>
                      <span>Número de documento <span class="text-error">*</span></span>
                    </template>
                  </VTextField>
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="editForm.gender"
                    :items="genderItems"
                    density="comfortable"
                    variant="outlined"
                    hide-details="auto"
                    class="mb-1"
                    :disabled="editSubmitting"
                  >
                    <template #label>
                      <span>Género <span class="text-error">*</span></span>
                    </template>
                  </VSelect>
                </VCol>
                <VCol cols="12">
                  <div class="text-body-2 font-weight-medium mb-2">
                    Foto o avatar
                  </div>
                  <VFileInput
                    v-model="editAvatarFiles"
                    accept="image/*"
                    prepend-icon="ri-image-add-line"
                    show-size
                    class="mb-3"
                    :disabled="editSubmitting"
                    @update:model-value="onEditAvatarFilesChanged"
                  />
                  <div class="d-flex flex-wrap gap-2 mb-4">
                    <VBtn
                      v-for="p in avatarPresets"
                      :key="p.key"
                      icon
                      variant="tonal"
                      size="small"
                      :color="editForm.avatar_preset === p.key ? 'primary' : undefined"
                      :disabled="editSubmitting"
                      @click="selectEditPreset(p.key)"
                    >
                      <VAvatar
                        :image="p.src"
                        size="40"
                        rounded
                      />
                    </VBtn>
                  </div>
                </VCol>
                <VCol cols="12">
                  <VSwitch
                    v-model="editForm.is_active"
                    inset
                    color="primary"
                    label="Usuario activo"
                    density="comfortable"
                    hide-details
                    class="mb-2"
                    :disabled="editSubmitting"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="editForm.password"
                    type="password"
                    density="comfortable"
                    class="mb-1"
                    autocomplete="new-password"
                    :disabled="editSubmitting"
                    label="Nueva contraseña (opcional)"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="editForm.pin"
                    label="Nuevo PIN POS (opcional)"
                    hint="Solo dígitos, entre 4 y 16. Dejar vacío para no cambiar."
                    persistent-hint
                    density="comfortable"
                    class="mb-1"
                    autocomplete="off"
                    :disabled="editSubmitting"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="editForm.branch_id"
                    :items="branchItems"
                    clearable
                    density="comfortable"
                    variant="outlined"
                    hide-details="auto"
                    class="mb-1"
                    label="Sucursal"
                    :disabled="editSubmitting || !branchItems.length"
                    no-data-text="No hay sucursales."
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="editForm.role"
                    :items="roleSelectItems"
                    density="comfortable"
                    variant="outlined"
                    hide-details="auto"
                    class="mb-1"
                    :disabled="editSubmitting || !roleSelectItems.length"
                    no-data-text="No hay roles disponibles."
                  >
                    <template #label>
                      <span>Rol <span class="text-error">*</span></span>
                    </template>
                  </VSelect>
                </VCol>
              </VRow>

              <div class="d-flex justify-end gap-2 mt-2">
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
        <VCard v-if="detailUser">
          <VCardItem>
            <template #prepend>
              <VAvatar
                :image="rowAvatarSrc(detailUser)"
                size="48"
                rounded
                class="me-2"
              />
            </template>
            <VCardTitle>{{ detailUser.name }}</VCardTitle>
            <VCardSubtitle>{{ detailUser.email }}</VCardSubtitle>
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
              <span class="font-weight-medium text-high-emphasis">Documento:</span>
              {{ detailUser.document_number ?? '—' }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium text-high-emphasis">Género:</span>
              {{ detailUser.gender_label ?? '—' }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium text-high-emphasis">Estado:</span>
              {{ detailUser.is_active ? 'Activo' : 'Inactivo' }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium text-high-emphasis">Sucursal:</span>
              {{ detailUser.branch?.name ?? '—' }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium text-high-emphasis">Rol:</span>
              {{ detailUser.role_display ?? detailUser.role ?? '—' }}
            </p>
            <p class="text-body-2 mb-4">
              <span class="font-weight-medium text-high-emphasis">Fecha de registro:</span>
              {{ formatDate(detailUser.created_at) }}
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
            <VCardTitle>Eliminar usuario</VCardTitle>
            <VCardSubtitle>
              ¿Seguro que querés eliminar a {{ deleteTarget.name }}?
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
              @click="confirmDeleteUser"
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
.usuarios-table :deep(th) {
  font-weight: 600;
}

code {
  font-size: 0.85em;
}
</style>
