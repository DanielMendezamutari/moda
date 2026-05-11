<script setup>
import { useAuthStore } from '@/stores/auth'
import { useBoliviaDepartments } from '@/composables/useBoliviaDepartments'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'

const {
  departments: boliviaDepartments,
  loading: boliviaDepartmentsLoading,
  load: loadBoliviaDepartments,
} = useBoliviaDepartments()

definePage({
  meta: {
    navActiveLink: 'proveedores',
  },
})

const authStore = useAuthStore()

const search = ref('')
const loading = ref(false)
const errorMsg = ref('')
const suppliers = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const detailOpen = ref(false)
const detailSupplier = ref(null)

function emptySupplierForm() {
  return {
    name: '',
    ruc: '',
    email: '',
    phone: '',
    address: '',
    is_active: true,
    contact_name: '',
    phone_alt: '',
    website: '',
    city: '',
    department: '',
    country: 'Bolivia',
    payment_terms: '',
    notes: '',
  }
}

const createDialogOpen = ref(false)
const createSubmitting = ref(false)
const createFormError = ref('')
const createForm = reactive(emptySupplierForm())

const editDialogOpen = ref(false)
const editSubmitting = ref(false)
const editFormError = ref('')
const editForm = reactive({ id: null, ...emptySupplierForm() })

const deleteDialogOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref(null)

const headers = [
  { title: 'Nombre / razón social', key: 'name', sortable: true },
  { title: 'RUC / NIT', key: 'ruc', sortable: true },
  { title: 'Correo', key: 'email', sortable: false },
  { title: 'Teléfono', key: 'phone', sortable: false },
  { title: 'Ciudad', key: 'city', sortable: true },
  { title: 'Activo', key: 'is_active', sortable: false },
  { title: 'Registro', key: 'created_at', sortable: true },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: '140px' },
]

function opt(s) {
  const t = String(s || '').trim()

  return t ? t : null
}

function toPayload(form) {
  return {
    name: String(form.name || '').trim(),
    ruc: String(form.ruc || '').trim(),
    email: opt(form.email),
    phone: String(form.phone || '').trim(),
    address: String(form.address || '').trim(),
    is_active: !!form.is_active,
    contact_name: opt(form.contact_name),
    phone_alt: opt(form.phone_alt),
    website: opt(form.website),
    city: opt(form.city),
    department: form.department ? String(form.department).trim() : null,
    country: opt(form.country) || 'Bolivia',
    payment_terms: opt(form.payment_terms),
    notes: opt(form.notes),
  }
}

async function fetchSuppliers() {
  errorMsg.value = ''
  loading.value = true

  try {
    const res = await $api('/suppliers')

    suppliers.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    suppliers.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver proveedores.',
      fallback: 'No se pudieron cargar los proveedores.',
    })
  }
  finally {
    loading.value = false
  }
}

function openCreateDialog() {
  Object.assign(createForm, emptySupplierForm())
  createFormError.value = ''
  createDialogOpen.value = true
}

function closeCreateDialog() {
  createDialogOpen.value = false
}

async function submitCreateSupplier() {
  createFormError.value = ''
  const payload = toPayload(createForm)

  if (!payload.name || !payload.ruc || !payload.phone || !payload.address) {
    createFormError.value = 'Completá nombre / razón social, RUC o NIT, teléfono y dirección.'

    return
  }

  createSubmitting.value = true

  try {
    await $api('/suppliers', {
      method: 'POST',
      body: payload,
    })

    snackbar.text = 'Proveedor registrado correctamente.'
    snackbar.show = true
    closeCreateDialog()
    await fetchSuppliers()
  }
  catch (e) {
    createFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para crear proveedores.',
      fallback: 'No se pudo crear el proveedor.',
    })
  }
  finally {
    createSubmitting.value = false
  }
}

function openEdit(row) {
  editForm.id = row.id
  editForm.name = row.name || ''
  editForm.ruc = row.ruc || ''
  editForm.email = row.email || ''
  editForm.phone = row.phone || ''
  editForm.address = row.address || ''
  editForm.is_active = !!row.is_active
  editForm.contact_name = row.contact_name || ''
  editForm.phone_alt = row.phone_alt || ''
  editForm.website = row.website || ''
  editForm.city = row.city || ''
  editForm.department = row.department || ''
  editForm.country = row.country || 'Bolivia'
  editForm.payment_terms = row.payment_terms || ''
  editForm.notes = row.notes || ''
  editFormError.value = ''
  editDialogOpen.value = true
}

function closeEditDialog() {
  editDialogOpen.value = false
}

async function submitEditSupplier() {
  editFormError.value = ''
  const payload = toPayload(editForm)

  if (!payload.name || !payload.ruc || !payload.phone || !payload.address) {
    editFormError.value = 'Completá nombre / razón social, RUC o NIT, teléfono y dirección.'

    return
  }

  editSubmitting.value = true

  try {
    await $api(`/suppliers/${editForm.id}`, {
      method: 'PATCH',
      body: payload,
    })

    snackbar.text = 'Proveedor actualizado correctamente.'
    snackbar.show = true
    closeEditDialog()
    await fetchSuppliers()
  }
  catch (e) {
    editFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para editar proveedores.',
      fallback: 'No se pudo actualizar el proveedor.',
    })
  }
  finally {
    editSubmitting.value = false
  }
}

function openDetail(row) {
  detailSupplier.value = row
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
}

watch(detailOpen, open => {
  if (!open)
    detailSupplier.value = null
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

async function confirmDeleteSupplier() {
  if (!deleteTarget.value)
    return

  deleteSubmitting.value = true

  try {
    await $api(`/suppliers/${deleteTarget.value.id}`, {
      method: 'DELETE',
    })

    snackbar.text = 'Proveedor eliminado.'
    snackbar.show = true
    closeDeleteDialog()
    await fetchSuppliers()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      forbidden: 'No tenés permiso para eliminar proveedores.',
      fallback: 'No se pudo eliminar el proveedor.',
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
    fetchSuppliers()
  }
})

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return suppliers.value

  return suppliers.value.filter((row) => {
    const blob = [
      row.name,
      row.ruc,
      row.email,
      row.phone,
      row.phone_alt,
      row.city,
      row.contact_name,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return blob.includes(q)
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
          Los proveedores solo pueden gestionarlos administradores.
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
                Proveedores
              </VCardTitle>
              <VCardSubtitle class="text-wrap pa-0">
                Datos fiscales y de contacto (Bolivia). Obligatorios: nombre o razón social, RUC/NIT, teléfono y dirección. Correo y demás campos son opcionales.
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
                Nuevo proveedor
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
                @click="fetchSuppliers"
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
              class="proveedores-table text-no-wrap"
              hover
            >
            <template #item.name="{ item }">
              <span class="font-weight-medium text-wrap">{{ item.name }}</span>
            </template>

            <template #item.email="{ item }">
              <span v-if="item.email">{{ item.email }}</span>
              <span
                v-else
                class="text-medium-emphasis text-body-2"
              >—</span>
            </template>

            <template #item.city="{ item }">
              <span v-if="item.city">{{ item.city }}</span>
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
                No hay proveedores. Agregá el primero con «Nuevo proveedor».
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
            <VCardTitle>Nuevo proveedor</VCardTitle>
            <VCardSubtitle>
              Registro para compras y facturación.
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

            <VForm @submit.prevent="submitCreateSupplier">
              <p class="text-subtitle-2 text-medium-emphasis mb-2">
                Datos principales
              </p>
              <VTextField
                v-model="createForm.name"
                density="comfortable"
                class="mb-3"
                autocomplete="organization"
                :disabled="createSubmitting"
              >
                <template #label>
                  <span>Nombre completo o razón social <span class="text-error">*</span></span>
                </template>
              </VTextField>
              <VTextField
                v-model="createForm.ruc"
                density="comfortable"
                class="mb-4"
                autocomplete="off"
                hint="NIT / RUC según documento del proveedor."
                persistent-hint
                :disabled="createSubmitting"
              >
                <template #label>
                  <span>RUC / NIT <span class="text-error">*</span></span>
                </template>
              </VTextField>

              <p class="text-subtitle-2 text-medium-emphasis mb-2">
                Contacto
              </p>
              <VTextField
                v-model="createForm.email"
                type="email"
                density="comfortable"
                class="mb-3"
                autocomplete="email"
                :disabled="createSubmitting"
                label="Correo electrónico (opcional)"
              />
              <VTextField
                v-model="createForm.phone"
                density="comfortable"
                class="mb-3"
                autocomplete="tel"
                :disabled="createSubmitting"
              >
                <template #label>
                  <span>Teléfono principal <span class="text-error">*</span></span>
                </template>
              </VTextField>
              <VTextField
                v-model="createForm.phone_alt"
                density="comfortable"
                class="mb-3"
                autocomplete="tel"
                label="Teléfono alternativo / WhatsApp (opcional)"
                :disabled="createSubmitting"
              />
              <VTextField
                v-model="createForm.contact_name"
                density="comfortable"
                class="mb-4"
                autocomplete="name"
                label="Persona de contacto (opcional)"
                :disabled="createSubmitting"
              />

              <p class="text-subtitle-2 text-medium-emphasis mb-2">
                Ubicación
              </p>
              <VTextarea
                v-model="createForm.address"
                rows="2"
                auto-grow
                density="comfortable"
                class="mb-3"
                :disabled="createSubmitting"
              >
                <template #label>
                  <span>Dirección fiscal / despacho <span class="text-error">*</span></span>
                </template>
              </VTextarea>
              <VRow dense>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="createForm.city"
                    density="comfortable"
                    label="Ciudad (opcional)"
                    autocomplete="address-level2"
                    :disabled="createSubmitting"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="createForm.department"
                    :items="boliviaDepartments"
                    density="comfortable"
                    variant="outlined"
                    hide-details="auto"
                    clearable
                    label="Departamento (opcional)"
                    :loading="boliviaDepartmentsLoading"
                    :disabled="createSubmitting"
                    :menu-props="{ maxHeight: 280 }"
                  />
                </VCol>
              </VRow>
              <VTextField
                v-model="createForm.country"
                density="comfortable"
                class="mb-4 mt-2"
                label="País"
                :disabled="createSubmitting"
              />

              <p class="text-subtitle-2 text-medium-emphasis mb-2">
                Otros (opcional)
              </p>
              <VTextField
                v-model="createForm.website"
                density="comfortable"
                class="mb-3"
                autocomplete="url"
                label="Sitio web"
                hint="URL del proveedor o catálogo."
                persistent-hint
                :disabled="createSubmitting"
              />
              <VTextField
                v-model="createForm.payment_terms"
                density="comfortable"
                class="mb-3"
                label="Condiciones de pago"
                hint="Ej. contado, crédito 30 días."
                persistent-hint
                :disabled="createSubmitting"
              />
              <VTextarea
                v-model="createForm.notes"
                rows="2"
                auto-grow
                density="comfortable"
                class="mb-2"
                label="Notas internas"
                :disabled="createSubmitting"
              />

              <VSwitch
                v-model="createForm.is_active"
                inset
                color="primary"
                label="Proveedor activo"
                density="comfortable"
                hide-details
                class="mb-6"
                :disabled="createSubmitting"
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
        :width="$vuetify.display.smAndDown ? 'auto' : 720"
        scrollable
        @after-leave="editFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Editar proveedor</VCardTitle>
            <VCardSubtitle>
              Actualizá datos fiscales y de contacto.
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

            <VForm @submit.prevent="submitEditSupplier">
              <p class="text-subtitle-2 text-medium-emphasis mb-2">
                Datos principales
              </p>
              <VTextField
                v-model="editForm.name"
                density="comfortable"
                class="mb-3"
                autocomplete="organization"
                :disabled="editSubmitting"
              >
                <template #label>
                  <span>Nombre completo o razón social <span class="text-error">*</span></span>
                </template>
              </VTextField>
              <VTextField
                v-model="editForm.ruc"
                density="comfortable"
                class="mb-4"
                autocomplete="off"
                hint="NIT / RUC según documento del proveedor."
                persistent-hint
                :disabled="editSubmitting"
              >
                <template #label>
                  <span>RUC / NIT <span class="text-error">*</span></span>
                </template>
              </VTextField>

              <p class="text-subtitle-2 text-medium-emphasis mb-2">
                Contacto
              </p>
              <VTextField
                v-model="editForm.email"
                type="email"
                density="comfortable"
                class="mb-3"
                autocomplete="email"
                :disabled="editSubmitting"
                label="Correo electrónico (opcional)"
              />
              <VTextField
                v-model="editForm.phone"
                density="comfortable"
                class="mb-3"
                autocomplete="tel"
                :disabled="editSubmitting"
              >
                <template #label>
                  <span>Teléfono principal <span class="text-error">*</span></span>
                </template>
              </VTextField>
              <VTextField
                v-model="editForm.phone_alt"
                density="comfortable"
                class="mb-3"
                autocomplete="tel"
                label="Teléfono alternativo / WhatsApp (opcional)"
                :disabled="editSubmitting"
              />
              <VTextField
                v-model="editForm.contact_name"
                density="comfortable"
                class="mb-4"
                autocomplete="name"
                label="Persona de contacto (opcional)"
                :disabled="editSubmitting"
              />

              <p class="text-subtitle-2 text-medium-emphasis mb-2">
                Ubicación
              </p>
              <VTextarea
                v-model="editForm.address"
                rows="2"
                auto-grow
                density="comfortable"
                class="mb-3"
                :disabled="editSubmitting"
              >
                <template #label>
                  <span>Dirección fiscal / despacho <span class="text-error">*</span></span>
                </template>
              </VTextarea>
              <VRow dense>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="editForm.city"
                    density="comfortable"
                    label="Ciudad (opcional)"
                    autocomplete="address-level2"
                    :disabled="editSubmitting"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="editForm.department"
                    :items="boliviaDepartments"
                    density="comfortable"
                    variant="outlined"
                    hide-details="auto"
                    clearable
                    label="Departamento (opcional)"
                    :loading="boliviaDepartmentsLoading"
                    :disabled="editSubmitting"
                    :menu-props="{ maxHeight: 280 }"
                  />
                </VCol>
              </VRow>
              <VTextField
                v-model="editForm.country"
                density="comfortable"
                class="mb-4 mt-2"
                label="País"
                :disabled="editSubmitting"
              />

              <p class="text-subtitle-2 text-medium-emphasis mb-2">
                Otros (opcional)
              </p>
              <VTextField
                v-model="editForm.website"
                density="comfortable"
                class="mb-3"
                autocomplete="url"
                label="Sitio web"
                persistent-hint
                hint="URL del proveedor o catálogo."
                :disabled="editSubmitting"
              />
              <VTextField
                v-model="editForm.payment_terms"
                density="comfortable"
                class="mb-3"
                label="Condiciones de pago"
                persistent-hint
                hint="Ej. contado, crédito 30 días."
                :disabled="editSubmitting"
              />
              <VTextarea
                v-model="editForm.notes"
                rows="2"
                auto-grow
                density="comfortable"
                class="mb-2"
                label="Notas internas"
                :disabled="editSubmitting"
              />

              <VSwitch
                v-model="editForm.is_active"
                inset
                color="primary"
                label="Proveedor activo"
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
        :width="$vuetify.display.smAndDown ? 'auto' : 560"
        scrollable
      >
        <VCard v-if="detailSupplier">
          <VCardItem>
            <VCardTitle>{{ detailSupplier.name }}</VCardTitle>
            <VCardSubtitle>
              RUC / NIT: {{ detailSupplier.ruc }}
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
          <VCardText class="text-body-2">
            <p class="mb-2">
              <span class="font-weight-medium text-high-emphasis">Estado:</span>
              {{ detailSupplier.is_active ? 'Activo' : 'Inactivo' }}
            </p>
            <p
              v-if="detailSupplier.email"
              class="mb-2"
            >
              <span class="font-weight-medium text-high-emphasis">Correo:</span>
              {{ detailSupplier.email }}
            </p>
            <p class="mb-2">
              <span class="font-weight-medium text-high-emphasis">Teléfono:</span>
              {{ detailSupplier.phone }}
            </p>
            <p
              v-if="detailSupplier.phone_alt"
              class="mb-2"
            >
              <span class="font-weight-medium text-high-emphasis">Tel. alternativo:</span>
              {{ detailSupplier.phone_alt }}
            </p>
            <p
              v-if="detailSupplier.contact_name"
              class="mb-2"
            >
              <span class="font-weight-medium text-high-emphasis">Contacto:</span>
              {{ detailSupplier.contact_name }}
            </p>
            <p class="mb-2">
              <span class="font-weight-medium text-high-emphasis">Dirección:</span>
              {{ detailSupplier.address }}
            </p>
            <p
              v-if="detailSupplier.city || detailSupplier.department"
              class="mb-2"
            >
              <span class="font-weight-medium text-high-emphasis">Ubicación:</span>
              {{ [detailSupplier.city, detailSupplier.department, detailSupplier.country].filter(Boolean).join(', ') }}
            </p>
            <p
              v-if="detailSupplier.website"
              class="mb-2"
            >
              <span class="font-weight-medium text-high-emphasis">Web:</span>
              {{ detailSupplier.website }}
            </p>
            <p
              v-if="detailSupplier.payment_terms"
              class="mb-2"
            >
              <span class="font-weight-medium text-high-emphasis">Condiciones de pago:</span>
              {{ detailSupplier.payment_terms }}
            </p>
            <p
              v-if="detailSupplier.notes"
              class="mb-2 text-wrap"
            >
              <span class="font-weight-medium text-high-emphasis">Notas:</span>
              {{ detailSupplier.notes }}
            </p>
            <p class="mb-0">
              <span class="font-weight-medium text-high-emphasis">Registro:</span>
              {{ formatDate(detailSupplier.created_at) }}
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
            <VCardTitle>Eliminar proveedor</VCardTitle>
            <VCardSubtitle>
              ¿Eliminar <strong>{{ deleteTarget.name }}</strong>? Esta acción no borra compras ya registradas en el futuro módulo de compras.
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
              @click="confirmDeleteSupplier"
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
.proveedores-table :deep(th) {
  font-weight: 600;
}
</style>
