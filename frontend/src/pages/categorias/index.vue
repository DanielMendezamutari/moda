<script setup>
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'categorias',
  },
})

const authStore = useAuthStore()

const search = ref('')
const loading = ref(false)
const errorMsg = ref('')
const categories = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const detailOpen = ref(false)
const detailCategory = ref(null)

const createDialogOpen = ref(false)
const createSubmitting = ref(false)
const createFormError = ref('')
const createImageFiles = ref([])
const createForm = reactive({
  title: '',
  is_active: true,
})

const editDialogOpen = ref(false)
const editSubmitting = ref(false)
const editFormError = ref('')
const editImageFiles = ref([])
const editRemoveImage = ref(false)
const editHadImage = ref(false)
const editForm = reactive({
  id: null,
  title: '',
  is_active: true,
})

const deleteDialogOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref(null)

const headers = [
  { title: '', key: 'thumb', sortable: false, width: '64px' },
  { title: 'Título', key: 'title', sortable: true },
  { title: 'Estado', key: 'is_active', sortable: false },
  { title: 'Registro', key: 'created_at', sortable: true },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: '140px' },
]

async function fetchCategories() {
  errorMsg.value = ''
  loading.value = true

  try {
    const res = await $api('/categories')

    categories.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    categories.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver categorías.',
      fallback: 'No se pudieron cargar las categorías.',
    })
  }
  finally {
    loading.value = false
  }
}

function openCreateDialog() {
  createForm.title = ''
  createForm.is_active = true
  createImageFiles.value = []
  createFormError.value = ''
  createDialogOpen.value = true
}

function closeCreateDialog() {
  createDialogOpen.value = false
}

async function submitCreateCategory() {
  createFormError.value = ''
  const title = createForm.title.trim()

  if (!title) {
    createFormError.value = 'Completá el título de la categoría.'

    return
  }

  createSubmitting.value = true

  try {
    const fd = new FormData()

    fd.append('title', title)
    fd.append('is_active', createForm.is_active ? '1' : '0')

    const files = createImageFiles.value
    if (files?.length && files[0])
      fd.append('image', files[0])

    await $api('/categories', {
      method: 'POST',
      body: fd,
    })

    snackbar.text = 'Categoría registrada correctamente.'
    snackbar.show = true
    closeCreateDialog()
    await fetchCategories()
  }
  catch (e) {
    createFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para crear categorías.',
      fallback: 'No se pudo crear la categoría.',
    })
  }
  finally {
    createSubmitting.value = false
  }
}

function openEdit(row) {
  editForm.id = row.id
  editForm.title = row.title || ''
  editForm.is_active = !!row.is_active
  editImageFiles.value = []
  editRemoveImage.value = false
  editHadImage.value = !!row.image_url
  editFormError.value = ''
  editDialogOpen.value = true
}

function closeEditDialog() {
  editDialogOpen.value = false
}

async function submitEditCategory() {
  editFormError.value = ''
  const title = editForm.title.trim()

  if (!title) {
    editFormError.value = 'Completá el título de la categoría.'

    return
  }

  editSubmitting.value = true

  try {
    const fd = new FormData()

    fd.append('title', title)
    fd.append('is_active', editForm.is_active ? '1' : '0')

    if (editRemoveImage.value)
      fd.append('remove_image', '1')

    const files = editImageFiles.value
    if (files?.length && files[0])
      fd.append('image', files[0])

    await $api(`/categories/${editForm.id}`, {
      method: 'POST',
      body: fd,
    })

    snackbar.text = 'Categoría actualizada correctamente.'
    snackbar.show = true
    closeEditDialog()
    await fetchCategories()
  }
  catch (e) {
    editFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para editar categorías.',
      fallback: 'No se pudo actualizar la categoría.',
    })
  }
  finally {
    editSubmitting.value = false
  }
}

function openDetail(row) {
  detailCategory.value = row
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
}

watch(detailOpen, open => {
  if (!open)
    detailCategory.value = null
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

async function confirmDeleteCategory() {
  if (!deleteTarget.value)
    return

  deleteSubmitting.value = true

  try {
    await $api(`/categories/${deleteTarget.value.id}`, {
      method: 'DELETE',
    })

    snackbar.text = 'Categoría eliminada.'
    snackbar.show = true
    closeDeleteDialog()
    await fetchCategories()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      forbidden: 'No tenés permiso para eliminar categorías.',
      fallback: 'No se pudo eliminar la categoría.',
    })
    snackbar.show = true
  }
  finally {
    deleteSubmitting.value = false
  }
}

onMounted(async () => {
  await authStore.fetchMe()
  if (authStore.isAdmin)
    fetchCategories()
})

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return categories.value

  return categories.value.filter((row) => {
    const inTitle = String(row.title || '').toLowerCase().includes(q)

    return inTitle
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
          Las categorías solo pueden gestionarlas administradores.
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
                Categorías
              </VCardTitle>
              <VCardSubtitle class="text-wrap pa-0">
                Título, imagen opcional y estado activo o inactivo. Las inactivas podés ocultarlas luego en catálogo / POS.
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
                Nueva categoría
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
                @click="fetchCategories"
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
              class="categorias-table text-no-wrap"
              hover
            >
            <template #item.thumb="{ item }">
              <VAvatar
                v-if="item.image_url"
                size="40"
                rounded
              >
                <VImg
                  :src="item.image_url"
                  cover
                />
              </VAvatar>
              <VAvatar
                v-else
                size="40"
                rounded
                color="secondary"
                variant="tonal"
              >
                <VIcon
                  icon="ri-image-line"
                  size="22"
                />
              </VAvatar>
            </template>

            <template #item.title="{ item }">
              <span class="font-weight-medium">{{ item.title }}</span>
            </template>

            <template #item.is_active="{ item }">
              <VChip
                v-if="item.is_active"
                size="small"
                label
                color="success"
                variant="tonal"
              >
                Activa
              </VChip>
              <VChip
                v-else
                size="small"
                label
                color="error"
                variant="tonal"
              >
                Inactiva
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
                No hay categorías. Creá la primera con «Nueva categoría».
              </div>
            </template>
            </VDataTable>
          </VSheet>
        </VCardText>
      </VCard>

      <VDialog
        v-model="createDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 520"
        scrollable
        @after-leave="createFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Nueva categoría</VCardTitle>
            <VCardSubtitle>
              Imagen opcional (recomendado cuadrado o banner pequeño).
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

            <VForm @submit.prevent="submitCreateCategory">
              <VTextField
                v-model="createForm.title"
                density="comfortable"
                class="mb-4"
                autocomplete="off"
                :disabled="createSubmitting"
              >
                <template #label>
                  <span>Título <span class="text-error">*</span></span>
                </template>
              </VTextField>

              <VFileInput
                v-model="createImageFiles"
                label="Imagen (opcional)"
                prepend-icon="ri-image-add-line"
                accept="image/*"
                show-size
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-4"
                :disabled="createSubmitting"
              />

              <VSwitch
                v-model="createForm.is_active"
                inset
                color="primary"
                label="Categoría activa"
                density="comfortable"
                hide-details
                class="mb-6"
                :disabled="createSubmitting"
              />
              <p class="text-caption text-medium-emphasis mb-6">
                Si está inactiva, podés ocultarla en ventas o reportes cuando lo implementes en productos.
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
        :width="$vuetify.display.smAndDown ? 'auto' : 520"
        scrollable
        @after-leave="editFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Editar categoría</VCardTitle>
            <VCardSubtitle>
              Cambiá título, imagen o estado.
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

            <VForm @submit.prevent="submitEditCategory">
              <VTextField
                v-model="editForm.title"
                density="comfortable"
                class="mb-4"
                autocomplete="off"
                :disabled="editSubmitting"
              >
                <template #label>
                  <span>Título <span class="text-error">*</span></span>
                </template>
              </VTextField>

              <VFileInput
                v-model="editImageFiles"
                label="Nueva imagen (opcional)"
                prepend-icon="ri-image-add-line"
                accept="image/*"
                show-size
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-2"
                :disabled="editSubmitting"
              />

              <VCheckbox
                v-if="editHadImage"
                v-model="editRemoveImage"
                density="comfortable"
                hide-details
                class="mb-4"
                :disabled="editSubmitting"
                label="Quitar imagen actual"
              />

              <VSwitch
                v-model="editForm.is_active"
                inset
                color="primary"
                label="Categoría activa"
                density="comfortable"
                hide-details
                class="mb-6"
                :disabled="editSubmitting"
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
        v-model="detailOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 480"
      >
        <VCard v-if="detailCategory">
          <VCardItem>
            <VCardTitle>{{ detailCategory.title }}</VCardTitle>
            <VCardSubtitle>
              Detalle de categoría
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
            <div
              v-if="detailCategory.image_url"
              class="mb-4 rounded-lg overflow-hidden border"
              style="max-height: 200px;"
            >
              <VImg
                :src="detailCategory.image_url"
                cover
                max-height="200"
              />
            </div>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium text-high-emphasis">Estado:</span>
              {{ detailCategory.is_active ? 'Activa' : 'Inactiva' }}
            </p>
            <p class="text-body-2 mb-4">
              <span class="font-weight-medium text-high-emphasis">Registro:</span>
              {{ formatDate(detailCategory.created_at) }}
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
            <VCardTitle>Eliminar categoría</VCardTitle>
            <VCardSubtitle>
              ¿Eliminar <strong>{{ deleteTarget.title }}</strong>? Los productos que la usen habría que reasignar antes (cuando exista el vínculo).
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
              @click="confirmDeleteCategory"
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
.categorias-table :deep(th) {
  font-weight: 600;
}
</style>
