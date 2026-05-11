<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'

const router = useRouter()

definePage({
  meta: {
    navActiveLink: 'caja-control',
  },
})

const authStore = useAuthStore()

const loading = ref(false)
const errorMsg = ref('')
const registers = ref([])
const branches = ref([])
const users = ref([])
const usersLoading = ref(false)

const snackbar = reactive({ show: false, text: '' })

const dialogOpen = ref(false)
const dialogSubmitting = ref(false)
const dialogError = ref('')
const dialogForm = reactive({
  name: '',
  code: '',
  branch_id: null,
  default_user_id: null,
})

const editDialogOpen = ref(false)
const editSubmitting = ref(false)
const editError = ref('')
const editForm = reactive({
  id: null,
  branch_id: null,
  name: '',
  code: '',
  default_user_id: null,
  is_active: true,
})

const branchItems = computed(() =>
  branches.value.map(b => ({
    title: b.code ? `${b.name} (${b.code})` : b.name,
    value: b.id,
  })),
)

function usersSelectItems(branchId) {
  if (branchId == null || branchId === '')
    return []
  const bid = Number(branchId)

  return users.value
    .filter(u => u.is_active && u.branch_id != null && Number(u.branch_id) === bid)
    .map(u => ({
      title: `${u.name} · ${u.role_display || u.role || 'usuario'}`,
      value: u.id,
    }))
}

const createUserItems = computed(() => usersSelectItems(dialogForm.branch_id))
const editUserItems = computed(() => usersSelectItems(editForm.branch_id))

watch(() => dialogForm.branch_id, () => {
  dialogForm.default_user_id = null
})

const headers = [
  { title: 'Nombre', key: 'name' },
  { title: 'Código', key: 'code' },
  { title: 'Sucursal', key: 'branch' },
  { title: 'Usuario asignado', key: 'default_user' },
  { title: 'Estado', key: 'is_active' },
  { title: 'Acciones', key: 'actions', align: 'end', sortable: false },
]

async function fetchBranches() {
  try {
    const res = await $api('/branches')
    branches.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    branches.value = []
  }
}

async function fetchUsers() {
  usersLoading.value = true
  try {
    const res = await $api('/users')
    users.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    users.value = []
  }
  finally {
    usersLoading.value = false
  }
}

async function fetchRegisters() {
  errorMsg.value = ''
  loading.value = true
  try {
    const res = await $api('/cash-registers')
    registers.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    registers.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'Sin permiso para ver cajas.',
      fallback: 'No se pudieron cargar las cajas.',
    })
  }
  finally {
    loading.value = false
  }
}

async function openCreate() {
  dialogForm.name = ''
  dialogForm.code = ''
  dialogForm.branch_id = branchItems.value[0]?.value ?? null
  dialogForm.default_user_id = null
  dialogError.value = ''
  dialogOpen.value = true
  fetchBranches()
  await fetchUsers()
}

async function submitCreate() {
  dialogError.value = ''
  if (!dialogForm.name.trim()) {
    dialogError.value = 'Indicá el nombre de la caja.'
    return
  }
  if (dialogForm.branch_id == null) {
    dialogError.value = 'Seleccioná sucursal.'
    return
  }
  dialogSubmitting.value = true
  try {
    await $api('/cash-registers', {
      method: 'POST',
      body: {
        name: dialogForm.name.trim(),
        code: dialogForm.code.trim() || null,
        branch_id: dialogForm.branch_id,
        default_user_id: dialogForm.default_user_id != null ? Number(dialogForm.default_user_id) : null,
      },
    })
    snackbar.text = 'Caja creada.'
    snackbar.show = true
    dialogOpen.value = false
    await fetchRegisters()
  }
  catch (e) {
    dialogError.value = messageFromApiError(e, {
      forbidden: 'Solo administración puede crear cajas.',
      fallback: 'No se pudo crear la caja.',
    })
  }
  finally {
    dialogSubmitting.value = false
  }
}

async function openEdit(row) {
  editForm.id = row.id
  editForm.branch_id = row.branch_id ?? null
  editForm.name = row.name || ''
  editForm.code = row.code || ''
  editForm.default_user_id = row.default_user_id ?? null
  editForm.is_active = !!row.is_active
  editError.value = ''
  editDialogOpen.value = true
  await fetchUsers()
}

async function submitEdit() {
  editError.value = ''
  if (!editForm.name.trim()) {
    editError.value = 'Indicá el nombre.'
    return
  }
  editSubmitting.value = true
  try {
    await $api(`/cash-registers/${editForm.id}`, {
      method: 'PATCH',
      body: {
        name: editForm.name.trim(),
        code: editForm.code.trim() || null,
        is_active: editForm.is_active,
        default_user_id: editForm.default_user_id != null ? Number(editForm.default_user_id) : null,
      },
    })
    snackbar.text = 'Caja actualizada.'
    snackbar.show = true
    editDialogOpen.value = false
    await fetchRegisters()
  }
  catch (e) {
    editError.value = messageFromApiError(e, { fallback: 'No se pudo actualizar.' })
  }
  finally {
    editSubmitting.value = false
  }
}

onMounted(async () => {
  await authStore.fetchMe()
  if (!authStore.isAdmin) {
    router.replace({ name: 'caja-sesiones' })

    return
  }
  await fetchRegisters()
  await fetchBranches()
  await fetchUsers()
})
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-6">
      <div>
        <h1 class="text-h4 font-weight-bold">
          Control de cajas
        </h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Definí cajas por sucursal (mostrador, administración, etc.).
        </p>
      </div>
      <VBtn
        v-if="authStore.isAdmin"
        color="primary"
        prepend-icon="ri-add-line"
        @click="openCreate"
      >
        Nueva caja
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

    <VCard v-if="!authStore.isAdmin" variant="outlined" class="mb-4">
      <VCardText class="text-body-2 text-medium-emphasis">
        La creación y edición de cajas está reservada a administración. Podés ver la lista de cajas activas de tu sucursal.
      </VCardText>
    </VCard>

    <VCard>
      <VDataTable
        :headers="headers"
        :items="registers"
        :loading="loading"
        class="text-no-wrap"
      >
        <template #item.branch="{ item }">
          {{ item.branch?.name || '—' }}
        </template>
        <template #item.default_user="{ item }">
          {{ item.default_user?.name || '—' }}
        </template>
        <template #item.is_active="{ item }">
          <VChip
            :color="item.is_active ? 'success' : 'default'"
            size="small"
            variant="tonal"
          >
            {{ item.is_active ? 'Activa' : 'Inactiva' }}
          </VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn
            v-if="authStore.isAdmin"
            size="small"
            variant="text"
            @click="openEdit(item)"
          >
            Editar
          </VBtn>
        </template>
        <template #no-data>
          <div class="text-center text-medium-emphasis py-8">
            No hay cajas registradas.
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog
      v-model="dialogOpen"
      max-width="520"
    >
      <VCard>
        <VCardTitle>Nueva caja</VCardTitle>
        <VCardText>
          <VAlert
            v-if="dialogError"
            type="error"
            variant="tonal"
            class="mb-3"
            density="compact"
          >
            {{ dialogError }}
          </VAlert>
          <VTextField
            v-model="dialogForm.name"
            label="Nombre"
            class="mb-3"
            density="comfortable"
          />
          <VTextField
            v-model="dialogForm.code"
            label="Código (opcional)"
            class="mb-3"
            density="comfortable"
          />
          <VSelect
            v-model="dialogForm.branch_id"
            :items="branchItems"
            item-title="title"
            item-value="value"
            label="Sucursal"
            density="comfortable"
            class="mb-3"
          />
          <VSelect
            v-model="dialogForm.default_user_id"
            :items="createUserItems"
            item-title="title"
            item-value="value"
            label="Usuario asignado (cajero/a)"
            density="comfortable"
            clearable
            :loading="usersLoading"
            :disabled="dialogForm.branch_id == null"
            hint="Solo usuarios activos de la sucursal elegida."
            persistent-hint
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialogOpen = false">Cancelar</VBtn>
          <VBtn
            color="primary"
            :loading="dialogSubmitting"
            @click="submitCreate"
          >
            Guardar
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="editDialogOpen"
      max-width="520"
    >
      <VCard>
        <VCardTitle>Editar caja</VCardTitle>
        <VCardText>
          <VAlert
            v-if="editError"
            type="error"
            variant="tonal"
            class="mb-3"
            density="compact"
          >
            {{ editError }}
          </VAlert>
          <VTextField
            v-model="editForm.name"
            label="Nombre"
            class="mb-3"
            density="comfortable"
          />
          <VTextField
            v-model="editForm.code"
            label="Código (opcional)"
            class="mb-3"
            density="comfortable"
          />
          <VSelect
            v-model="editForm.default_user_id"
            :items="editUserItems"
            item-title="title"
            item-value="value"
            label="Usuario asignado (cajero/a)"
            density="comfortable"
            clearable
            :loading="usersLoading"
            class="mb-3"
            hint="Solo usuarios activos de la sucursal de esta caja."
            persistent-hint
          />
          <VSwitch
            v-model="editForm.is_active"
            label="Activa"
            color="primary"
            hide-details
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="editDialogOpen = false">Cerrar</VBtn>
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

    <VSnackbar
      v-model="snackbar.show"
      color="success"
    >
      {{ snackbar.text }}
    </VSnackbar>
  </div>
</template>
