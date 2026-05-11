<script setup>
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'
import { formatQuantityMax2 } from '@/utils/formatNumbers'

definePage({
  meta: {
    navActiveLink: 'unidades',
  },
})

const authStore = useAuthStore()

/** Clave dimensión → etiqueta (API meta/unit-dimensions). */
const dimensionLabels = ref({})

const search = ref('')
const loading = ref(false)
const errorMsg = ref('')
const units = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const detailOpen = ref(false)
const detailUnit = ref(null)

const createDialogOpen = ref(false)
const createSubmitting = ref(false)
const createFormError = ref('')
const createForm = reactive({
  name: '',
  description: '',
  dimension: 'count',
  is_active: true,
  /** Opcional: unidad de referencia (ej. «Unidad») para la que 1 [nueva] = factor. */
  equivalent_to_unit_id: null,
  equivalent_quantity: '',
})

const editDialogOpen = ref(false)
const editSubmitting = ref(false)
const editFormError = ref('')
const editForm = reactive({
  id: null,
  name: '',
  description: '',
  dimension: 'count',
  is_active: true,
  equivalent_to_unit_id: null,
  equivalent_quantity: '',
})

/** Conversión «origen = esta unidad» que edita el formulario (si hay varias, la primera). */
const editManagedConversionId = ref(null)
const editMultipleForwardHint = ref(false)

const deleteDialogOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref(null)

/** Diálogo conversiones */
const conversionDialogOpen = ref(false)
const conversionUnit = ref(null)
const conversionLoading = ref(false)
const conversionError = ref('')
const conversions = ref([])
const conversionSubmitting = ref(false)
const conversionFormError = ref('')
const conversionForm = reactive({
  to_unit_id: null,
  factor: '',
})

const dimensionSelectItems = computed(() =>
  Object.entries(dimensionLabels.value).map(([value, title]) => ({ title, value })),
)

const equivalentRefItemsCreate = computed(() =>
  units.value
    .filter(u => (u.dimension || 'count') === createForm.dimension && u.is_active !== false)
    .map(u => ({ title: u.name, value: u.id })),
)

const equivalentRefItemsEdit = computed(() =>
  units.value
    .filter(u =>
      (u.dimension || 'count') === editForm.dimension
      && Number(u.id) !== Number(editForm.id)
      && u.is_active !== false,
    )
    .map(u => ({ title: u.name, value: u.id })),
)

watch(() => createForm.dimension, () => {
  const allowed = new Set(equivalentRefItemsCreate.value.map(i => i.value))
  if (
    createForm.equivalent_to_unit_id != null
    && !allowed.has(createForm.equivalent_to_unit_id)
  )
    createForm.equivalent_to_unit_id = null
})

const headers = [
  { title: 'Nombre', key: 'name', sortable: true },
  { title: 'Dimensión', key: 'dimension_label', sortable: true },
  { title: 'Descripción', key: 'description', sortable: false },
  { title: 'Estado', key: 'is_active', sortable: false },
  { title: 'Registro', key: 'created_at', sortable: true },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: '200px' },
]

async function fetchDimensionLabels() {
  try {
    const res = await $api('/meta/unit-dimensions')

    dimensionLabels.value = res?.data && typeof res.data === 'object' ? res.data : {}
  }
  catch {
    dimensionLabels.value = {}
  }
}

async function fetchUnits() {
  errorMsg.value = ''
  loading.value = true

  try {
    const res = await $api('/units')

    units.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    units.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver unidades.',
      fallback: 'No se pudieron cargar las unidades.',
    })
  }
  finally {
    loading.value = false
  }
}

function openCreateDialog() {
  createForm.name = ''
  createForm.description = ''
  createForm.dimension = 'count'
  createForm.is_active = true
  createForm.equivalent_to_unit_id = null
  createForm.equivalent_quantity = ''
  createFormError.value = ''
  createDialogOpen.value = true
}

function closeCreateDialog() {
  createDialogOpen.value = false
}

async function submitCreateUnit() {
  createFormError.value = ''
  const name = createForm.name.trim()

  if (!name) {
    createFormError.value = 'Completá el nombre de la unidad.'

    return
  }

  const eqRaw = String(createForm.equivalent_quantity || '').trim()
  const refId = createForm.equivalent_to_unit_id
  const hasRef = refId != null && refId !== ''
  const eqNum = eqRaw === '' ? NaN : Number(eqRaw.replace(',', '.'))
  const hasValidQty = Number.isFinite(eqNum) && eqNum > 0

  if (hasRef && !hasValidQty) {
    createFormError.value = 'En el equivalente, indicá una cantidad mayor a cero.'

    return
  }
  if (!hasRef && eqRaw !== '') {
    createFormError.value = 'Elegí la unidad de referencia o borrá la cantidad del equivalente.'

    return
  }

  createSubmitting.value = true

  try {
    const res = await $api('/units', {
      method: 'POST',
      body: {
        name,
        description: createForm.description.trim() || null,
        dimension: createForm.dimension,
        is_active: createForm.is_active,
      },
    })

    const newId = res?.data?.id
    const wantEquiv = newId != null && hasRef && hasValidQty

    if (wantEquiv) {
      try {
        await $api(`/units/${newId}/conversions`, {
          method: 'POST',
          body: {
            to_unit_id: Number(refId),
            factor: eqNum,
          },
        })
      }
      catch (e2) {
        snackbar.text = messageFromApiError(e2, {
          fallback: 'Unidad creada, pero no se pudo guardar el equivalente. Definilo en «Conversiones».',
        })
        snackbar.show = true
        closeCreateDialog()
        await fetchUnits()
        createSubmitting.value = false

        return
      }
    }

    snackbar.text = wantEquiv
      ? 'Unidad y equivalente guardados correctamente.'
      : 'Unidad registrada correctamente.'
    snackbar.show = true
    closeCreateDialog()
    await fetchUnits()
  }
  catch (e) {
    createFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para crear unidades.',
      fallback: 'No se pudo crear la unidad.',
    })
  }
  finally {
    createSubmitting.value = false
  }
}

async function openEdit(row) {
  editForm.id = row.id
  editForm.name = row.name || ''
  editForm.description = row.description || ''
  editForm.dimension = row.dimension || 'count'
  editForm.is_active = !!row.is_active
  editForm.equivalent_to_unit_id = null
  editForm.equivalent_quantity = ''
  editManagedConversionId.value = null
  editMultipleForwardHint.value = false
  editFormError.value = ''

  try {
    const res = await $api(`/units/${row.id}/conversions`)
    const list = Array.isArray(res?.data) ? res.data : []
    const forwards = list.filter(c => Number(c.from_unit_id) === Number(row.id))
    editMultipleForwardHint.value = forwards.length > 1
    if (forwards.length) {
      const c = forwards[0]
      editManagedConversionId.value = c.id
      editForm.equivalent_to_unit_id = c.to_unit_id
      editForm.equivalent_quantity = String(c.factor ?? '')
    }
  }
  catch {
    /* sin conversiones o sin permiso: el formulario sigue sin equivalente */
  }

  editDialogOpen.value = true
}

function closeEditDialog() {
  editDialogOpen.value = false
}

async function submitEditUnit() {
  editFormError.value = ''
  const name = editForm.name.trim()

  if (!name) {
    editFormError.value = 'Completá el nombre de la unidad.'

    return
  }

  const eqRaw = String(editForm.equivalent_quantity || '').trim()
  const refId = editForm.equivalent_to_unit_id
  const hasRef = refId != null && refId !== ''
  const eqNum = eqRaw === '' ? NaN : Number(eqRaw.replace(',', '.'))
  const hasValidQty = Number.isFinite(eqNum) && eqNum > 0

  if (hasRef && !hasValidQty) {
    editFormError.value = 'En el equivalente, indicá una cantidad mayor a cero.'

    return
  }
  if (!hasRef && eqRaw !== '') {
    editFormError.value = 'Elegí la unidad de referencia o borrá la cantidad del equivalente.'

    return
  }

  editSubmitting.value = true

  try {
    await $api(`/units/${editForm.id}`, {
      method: 'PATCH',
      body: {
        name,
        description: editForm.description.trim() || null,
        dimension: editForm.dimension,
        is_active: editForm.is_active,
      },
    })

    const uid = Number(editForm.id)
    const wantEquiv = hasRef && hasValidQty

    if (wantEquiv) {
      if (editManagedConversionId.value) {
        await $api(`/unit-conversions/${editManagedConversionId.value}`, {
          method: 'PATCH',
          body: {
            from_unit_id: uid,
            to_unit_id: Number(refId),
            factor: eqNum,
          },
        })
      }
      else {
        await $api(`/units/${uid}/conversions`, {
          method: 'POST',
          body: {
            to_unit_id: Number(refId),
            factor: eqNum,
          },
        })
      }
    }
    else if (editManagedConversionId.value) {
      await $api(`/unit-conversions/${editManagedConversionId.value}`, {
        method: 'DELETE',
      })
      editManagedConversionId.value = null
    }

    snackbar.text = 'Unidad actualizada correctamente.'
    snackbar.show = true
    closeEditDialog()
    await fetchUnits()
  }
  catch (e) {
    editFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para editar unidades.',
      fallback: 'No se pudo actualizar la unidad o el equivalente.',
    })
  }
  finally {
    editSubmitting.value = false
  }
}

function openDetail(row) {
  detailUnit.value = row
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
}

watch(detailOpen, open => {
  if (!open)
    detailUnit.value = null
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

async function confirmDeleteUnit() {
  if (!deleteTarget.value)
    return

  deleteSubmitting.value = true

  try {
    await $api(`/units/${deleteTarget.value.id}`, {
      method: 'DELETE',
    })

    snackbar.text = 'Unidad eliminada.'
    snackbar.show = true
    closeDeleteDialog()
    await fetchUnits()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      forbidden: 'No tenés permiso para eliminar unidades.',
      fallback: 'No se pudo eliminar la unidad.',
    })
    snackbar.show = true
  }
  finally {
    deleteSubmitting.value = false
  }
}

function pairKey(id1, id2) {
  const a = Math.min(Number(id1), Number(id2))
  const b = Math.max(Number(id1), Number(id2))

  return `${a}-${b}`
}

/** Par de unidades que ya tiene conversión guardada (cualquier sentido). */
const occupiedConversionPairs = computed(() => {
  const set = new Set()

  for (const c of conversions.value)
    set.add(pairKey(c.from_unit_id, c.to_unit_id))

  return set
})

/** Destinos permitidos: misma dimensión, activas, y sin par ya definido con esta unidad. */
const conversionToItems = computed(() => {
  const u = conversionUnit.value
  if (!u)
    return []

  const dim = u.dimension || 'count'

  return units.value
    .filter((x) => {
      if (x.id === u.id || !x.is_active)
        return false
      if ((x.dimension || 'count') !== dim)
        return false
      if (occupiedConversionPairs.value.has(pairKey(u.id, x.id)))
        return false

      return true
    })
    .map(x => ({ title: x.name, value: x.id }))
})

const conversionExample = computed(() => {
  const u = conversionUnit.value
  const tid = conversionForm.to_unit_id
  const f = conversionForm.factor
  if (!u || tid == null || tid === '' || f === '' || f === null)
    return ''

  const toName = units.value.find(x => x.id === tid)?.name ?? ''
  const fn = Number(String(f).replace(',', '.'))

  if (!Number.isFinite(fn) || fn <= 0 || !toName)
    return ''

  return `Ejemplo: 1 ${u.name} = ${fn} ${toName} · 5 ${u.name} = ${5 * fn} ${toName}`
})

async function openConversionDialog(row) {
  conversionUnit.value = row
  conversionError.value = ''
  conversionForm.to_unit_id = null
  conversionForm.factor = ''
  conversionFormError.value = ''
  conversionDialogOpen.value = true
  await fetchConversionsForUnit(row.id)
}

function closeConversionDialog() {
  conversionDialogOpen.value = false
}

watch(conversionDialogOpen, open => {
  if (!open) {
    conversionUnit.value = null
    conversions.value = []
  }
})

async function fetchConversionsForUnit(unitId) {
  conversionLoading.value = true
  conversionError.value = ''

  try {
    const res = await $api(`/units/${unitId}/conversions`)

    conversions.value = Array.isArray(res?.data) ? res.data : []
  }
  catch (e) {
    conversions.value = []
    conversionError.value = messageFromApiError(e, {
      fallback: 'No se pudieron cargar las conversiones.',
    })
  }
  finally {
    conversionLoading.value = false
  }
}

async function submitConversion() {
  conversionFormError.value = ''
  if (!conversionUnit.value)
    return

  const factorNum = Number(String(conversionForm.factor).replace(',', '.'))

  if (conversionForm.to_unit_id == null || conversionForm.to_unit_id === '') {
    conversionFormError.value = 'Seleccioná la unidad destino.'

    return
  }
  if (!Number.isFinite(factorNum) || factorNum <= 0) {
    conversionFormError.value = 'Ingresá un factor mayor a cero.'

    return
  }

  conversionSubmitting.value = true

  try {
    await $api(`/units/${conversionUnit.value.id}/conversions`, {
      method: 'POST',
      body: {
        to_unit_id: conversionForm.to_unit_id,
        factor: factorNum,
      },
    })

    snackbar.text = 'Conversión agregada.'
    snackbar.show = true
    conversionForm.to_unit_id = null
    conversionForm.factor = ''
    await fetchConversionsForUnit(conversionUnit.value.id)
    await fetchUnits()
  }
  catch (e) {
    conversionFormError.value = messageFromApiError(e, {
      fallback: 'No se pudo guardar la conversión.',
    })
  }
  finally {
    conversionSubmitting.value = false
  }
}

const deleteConversionSubmitting = ref(false)

async function deleteConversion(row) {
  if (!row?.id)
    return

  deleteConversionSubmitting.value = true

  try {
    await $api(`/unit-conversions/${row.id}`, {
      method: 'DELETE',
    })

    snackbar.text = 'Conversión eliminada.'
    snackbar.show = true
    if (conversionUnit.value)
      await fetchConversionsForUnit(conversionUnit.value.id)
    await fetchUnits()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      fallback: 'No se pudo eliminar la conversión.',
    })
    snackbar.show = true
  }
  finally {
    deleteConversionSubmitting.value = false
  }
}

function formatFactorDisplay(f) {
  if (f == null || f === '')
    return '—'

  const n = Number(f)

  if (!Number.isFinite(n))
    return String(f)

  return formatQuantityMax2(n)
}

onMounted(async () => {
  await authStore.fetchMe()
  if (authStore.isAdmin) {
    await fetchDimensionLabels()
    fetchUnits()
  }
})

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return units.value

  return units.value.filter((row) => {
    const inName = String(row.name || '').toLowerCase().includes(q)
    const inDesc = String(row.description || '').toLowerCase().includes(q)
    const inDim = String(row.dimension_label || '').toLowerCase().includes(q)

    return inName || inDesc || inDim
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
          Las unidades de medida solo pueden gestionarlas administradores.
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
                Unidades de medida
              </VCardTitle>
              <VCardSubtitle class="text-wrap pa-0">
                Pensado para boutique de <strong>ropa femenina</strong>: piezas, pares (medias), packs y docenas; telas por metro; opcional peso en insumos. Cada unidad tiene <strong>dimensión</strong> (cantidad, longitud, masa): solo se convierte dentro de la misma. Entre dos unidades hay <strong>una sola</strong> relación guardada; el sistema usa la inversa al calcular.
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
                Nueva unidad
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
                @click="fetchUnits"
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
              class="unidades-table text-no-wrap"
              hover
            >
            <template #item.dimension_label="{ item }">
              <span class="text-body-2">{{ item.dimension_label || '—' }}</span>
            </template>

            <template #item.description="{ item }">
              <span
                v-if="item.description"
                class="text-wrap desc-cell"
              >{{ item.description }}</span>
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
              <div class="d-flex align-center justify-end gap-1 flex-wrap">
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
                  color="primary"
                  @click="openConversionDialog(item)"
                >
                  <VIcon icon="ri-exchange-line" />
                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    Conversiones
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
                No hay unidades. Creá la primera con «Nueva unidad».
              </div>
            </template>
            </VDataTable>
          </VSheet>
        </VCardText>
      </VCard>

      <!-- Diálogo conversiones -->
      <VDialog
        v-model="conversionDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 640"
        scrollable
      >
        <VCard v-if="conversionUnit">
          <VCardItem>
            <VCardTitle>Conversiones: {{ conversionUnit.name }}</VCardTitle>
            <VCardSubtitle>
              Lista de relaciones <strong>guardadas</strong> que incluyen «{{ conversionUnit.name }}». Factor: cantidad en destino por <strong>1</strong> unidad de origen (según fila). Para el par A↔B solo existe una fila; la conversión inversa se calcula al usar «Convertir» en API o módulos de compras.
            </VCardSubtitle>
            <template #append>
              <VBtn
                icon
                variant="text"
                @click="closeConversionDialog"
              >
                <VIcon icon="ri-close-line" />
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText class="pt-4">
            <VAlert
              v-if="conversionError"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
              rounded="lg"
            >
              {{ conversionError }}
            </VAlert>

            <VTable
              density="compact"
              class="mb-4 conversion-table"
            >
              <thead>
                <tr>
                  <th class="text-start">
                    Origen
                  </th>
                  <th class="text-start">
                    Destino
                  </th>
                  <th class="text-start">
                    Fórmula
                  </th>
                  <th
                    class="text-end"
                    style="width: 56px;"
                  />
                </tr>
              </thead>
              <tbody>
                <template v-if="conversionLoading">
                  <tr>
                    <td
                      colspan="4"
                      class="py-6 text-center"
                    >
                      <VProgressCircular
                        indeterminate
                        color="primary"
                        size="24"
                      />
                    </td>
                  </tr>
                </template>
                <template v-else-if="conversions.length">
                  <tr
                    v-for="item in conversions"
                    :key="item.id"
                  >
                    <td>{{ item.from_unit?.name ?? '—' }}</td>
                    <td>{{ item.to_unit?.name ?? '—' }}</td>
                    <td class="text-body-2 text-wrap">
                      {{ item.formula || `1 ${item.from_unit?.name ?? ''} = ${formatFactorDisplay(item.factor)} ${item.to_unit?.name ?? ''}` }}
                    </td>
                    <td class="text-end">
                      <VBtn
                        icon
                        variant="text"
                        size="small"
                        color="error"
                        :loading="deleteConversionSubmitting"
                        @click="deleteConversion(item)"
                      >
                        <VIcon icon="ri-delete-bin-line" />
                        <VTooltip
                          activator="parent"
                          location="top"
                        >
                          Eliminar conversión
                        </VTooltip>
                      </VBtn>
                    </td>
                  </tr>
                </template>
                <tr v-else>
                  <td
                    colspan="4"
                    class="py-4 text-medium-emphasis text-body-2"
                  >
                    No hay conversiones que incluyan esta unidad. Agregá una abajo (origen fijo en esta unidad).
                  </td>
                </tr>
              </tbody>
            </VTable>

            <VDivider class="mb-4" />

            <p class="text-subtitle-2 mb-2">
              Nueva conversión (origen fijo: «{{ conversionUnit.name }}», dimensión {{ conversionUnit.dimension_label || conversionUnit.dimension }})
            </p>
            <p class="text-caption text-medium-emphasis mb-3">
              Solo aparecen unidades activas de la <strong>misma dimensión</strong> y sin par ya definido (ni directo ni inverso).
            </p>
            <VAlert
              v-if="conversionFormError"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-3"
              rounded="lg"
            >
              {{ conversionFormError }}
            </VAlert>

            <VRow dense>
              <VCol
                cols="12"
                md="6"
              >
                <VSelect
                  v-model="conversionForm.to_unit_id"
                  :items="conversionToItems"
                  label="Unidad destino *"
                  density="comfortable"
                  variant="outlined"
                  hide-details="auto"
                  :disabled="conversionSubmitting"
                  no-data-text="No hay más unidades disponibles para combinar."
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="conversionForm.factor"
                  type="text"
                  inputmode="decimal"
                  label="Factor *"
                  density="comfortable"
                  hint="Cantidad en unidad destino equivalente a 1 unidad de origen (esta fila)."
                  persistent-hint
                  :disabled="conversionSubmitting"
                />
              </VCol>
            </VRow>

            <p
              v-if="conversionExample"
              class="text-body-2 text-medium-emphasis mb-2"
            >
              {{ conversionExample }}
            </p>

            <div class="d-flex justify-end mt-4">
              <VBtn
                color="primary"
                :loading="conversionSubmitting"
                :disabled="!conversionToItems.length"
                @click="submitConversion"
              >
                Agregar conversión
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VDialog>

      <!-- Resto diálogos (crear / editar / detalle / eliminar) como categorías -->
      <VDialog
        v-model="createDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 520"
        scrollable
        @after-leave="createFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Nueva unidad</VCardTitle>
            <VCardSubtitle>
              Nombre obligatorio. Podés indicar cuántas unidades de referencia (ej. prendas) entran en 1 docena, caja, etc.
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

            <VForm @submit.prevent="submitCreateUnit">
              <VTextField
                v-model="createForm.name"
                density="comfortable"
                class="mb-4"
                autocomplete="off"
                :disabled="createSubmitting"
              >
                <template #label>
                  <span>Nombre <span class="text-error">*</span></span>
                </template>
              </VTextField>

              <VTextarea
                v-model="createForm.description"
                rows="2"
                auto-grow
                density="comfortable"
                class="mb-4"
                label="Descripción (opcional)"
                :disabled="createSubmitting"
              />

              <VSelect
                v-model="createForm.dimension"
                :items="dimensionSelectItems"
                label="Dimensión física *"
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-4"
                :disabled="createSubmitting"
                :menu-props="{ maxHeight: 280 }"
              />

              <VDivider class="my-4" />

              <div class="text-subtitle-2 mb-2">
                Equivalente (opcional)
              </div>
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
                class="mb-4"
                rounded="lg"
              >
                Regla del sistema: <strong>1</strong> de esta unidad nueva = <strong>N</strong> de la unidad de referencia
                (ej. 1 Docena = 12 Unidad). Solo se listan unidades con la misma dimensión que elegiste arriba.
              </VAlert>
              <VSelect
                v-model="createForm.equivalent_to_unit_id"
                :items="equivalentRefItemsCreate"
                label="Unidad de referencia (ej. Unidad)"
                clearable
                density="comfortable"
                class="mb-3"
                :disabled="createSubmitting || !equivalentRefItemsCreate.length"
                :hint="equivalentRefItemsCreate.length ? 'Si no ves la unidad base, creala antes con «Nueva unidad».' : 'No hay otras unidades con esta dimensión; creá primero la unidad más pequeña.'"
                persistent-hint
              />
              <VTextField
                v-model="createForm.equivalent_quantity"
                label="Cantidad en esa unidad por cada 1 de esta medida"
                type="number"
                min="0"
                step="0.01"
                density="comfortable"
                class="mb-4"
                :disabled="createSubmitting"
                hint="Ej. 12 para docena, 24 para caja de 24 piezas."
                persistent-hint
              />

              <VSwitch
                v-model="createForm.is_active"
                inset
                color="primary"
                label="Unidad activa"
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
        :width="$vuetify.display.smAndDown ? 'auto' : 520"
        scrollable
        @after-leave="editFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Editar unidad</VCardTitle>
            <VCardSubtitle>
              Podés ajustar el equivalente (cuántas unidades de referencia representa 1 de esta medida).
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
            <VAlert
              v-if="editMultipleForwardHint"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-4"
              rounded="lg"
            >
              Esta unidad tiene más de una conversión donde ella es el origen. Este formulario muestra y edita la primera;
              el resto seguí administrándolo en «Conversiones».
            </VAlert>

            <VForm @submit.prevent="submitEditUnit">
              <VTextField
                v-model="editForm.name"
                density="comfortable"
                class="mb-4"
                autocomplete="off"
                :disabled="editSubmitting"
              >
                <template #label>
                  <span>Nombre <span class="text-error">*</span></span>
                </template>
              </VTextField>

              <VTextarea
                v-model="editForm.description"
                rows="2"
                auto-grow
                density="comfortable"
                class="mb-4"
                label="Descripción (opcional)"
                :disabled="editSubmitting"
              />

              <VSelect
                v-model="editForm.dimension"
                :items="dimensionSelectItems"
                label="Dimensión física *"
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-4"
                :disabled="editSubmitting"
                :menu-props="{ maxHeight: 280 }"
              />

              <VDivider class="my-4" />

              <div class="text-subtitle-2 mb-2">
                Equivalente (opcional)
              </div>
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
                class="mb-4"
                rounded="lg"
              >
                <strong>1</strong> {{ editForm.name || 'esta unidad' }} = <strong>N</strong> de la unidad de referencia.
                Si vaciás ambos campos y guardás, se elimina el equivalente mostrado aquí (si había más de uno como origen, el resto no se toca).
              </VAlert>
              <VSelect
                v-model="editForm.equivalent_to_unit_id"
                :items="equivalentRefItemsEdit"
                label="Unidad de referencia"
                clearable
                density="comfortable"
                class="mb-3"
                :disabled="editSubmitting || !equivalentRefItemsEdit.length"
                persistent-hint
                hint="Misma dimensión física que esta unidad."
              />
              <VTextField
                v-model="editForm.equivalent_quantity"
                label="Cantidad en esa unidad por cada 1 de esta medida"
                type="number"
                min="0"
                step="0.01"
                density="comfortable"
                class="mb-4"
                :disabled="editSubmitting"
                persistent-hint
                hint="Ej. 12 si una docena son 12 prendas."
              />

              <VSwitch
                v-model="editForm.is_active"
                inset
                color="primary"
                label="Unidad activa"
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
        <VCard v-if="detailUnit">
          <VCardItem>
            <VCardTitle>{{ detailUnit.name }}</VCardTitle>
            <VCardSubtitle>Unidad de medida</VCardSubtitle>
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
            <p
              v-if="detailUnit.description"
              class="text-body-2 mb-4 text-wrap"
            >
              {{ detailUnit.description }}
            </p>
            <p
              v-else
              class="text-body-2 text-medium-emphasis mb-4"
            >
              Sin descripción.
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium text-high-emphasis">Dimensión:</span>
              {{ detailUnit.dimension_label || detailUnit.dimension || '—' }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium text-high-emphasis">Estado:</span>
              {{ detailUnit.is_active ? 'Activa' : 'Inactiva' }}
            </p>
            <p class="text-body-2 mb-0">
              <span class="font-weight-medium text-high-emphasis">Registro:</span>
              {{ formatDate(detailUnit.created_at) }}
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
            <VCardTitle>Eliminar unidad</VCardTitle>
            <VCardSubtitle>
              ¿Eliminar <strong>{{ deleteTarget.name }}</strong>? No debe haber conversiones que la usen.
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
              @click="confirmDeleteUnit"
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
.unidades-table :deep(th) {
  font-weight: 600;
}

.desc-cell {
  display: inline-block;
  max-inline-size: min(100%, 280px);
  white-space: normal;
  line-height: 1.35;
}
</style>
