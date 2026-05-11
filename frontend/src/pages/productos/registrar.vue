<script setup>
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'
import { useBoliviaDepartments } from '@/composables/useBoliviaDepartments'

const router = useRouter()

const {
  departments: boliviaDepartments,
  loading: boliviaDepartmentsLoading,
  load: loadBoliviaDepartments,
} = useBoliviaDepartments()

definePage({
  meta: {
    navActiveLink: 'productos-registrar',
  },
})

const authStore = useAuthStore()

const canManage = computed(() => authStore.isAdmin)

const submitError = ref('')
const submitting = ref(false)
const imageFiles = ref([])
const form = reactive({
  name: '',
  sku: '',
  generate_sku: true,
  price: '',
  wholesale_price: '',
  description: '',
  cost_price: '',
  discount_percent: 0,
  warranty_days: 0,
  is_active: true,
  is_gift_card: false,
  category_id: null,
  barcode: '',
  generate_barcode: true,
})

/** Filas: almacén, stock, umbral, precio venta y compra en ese almacén */
const warehouseLines = ref([])

const categories = ref([])
const warehouses = ref([])
const branchOptions = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const fileInputEl = ref(null)
const imagePreviewUrl = ref('')
const imageDragOver = ref(false)

watch(imageFiles, files => {
  if (imagePreviewUrl.value)
    URL.revokeObjectURL(imagePreviewUrl.value)
  const f = files?.[0]
  imagePreviewUrl.value = f ? URL.createObjectURL(f) : ''
}, { deep: true })

onUnmounted(() => {
  if (imagePreviewUrl.value)
    URL.revokeObjectURL(imagePreviewUrl.value)
})

function triggerImagePick() {
  fileInputEl.value?.click()
}

function onImageInputChange(e) {
  const file = e.target.files?.[0]
  imageFiles.value = file ? [file] : []
}

function onImageDrop(e) {
  imageDragOver.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file && file.type.startsWith('image/'))
    imageFiles.value = [file]
}

function clearProductImage() {
  imageFiles.value = []
  if (fileInputEl.value)
    fileInputEl.value.value = ''
}

const categoryDialogOpen = ref(false)
const categorySubmitting = ref(false)
const categoryFormError = ref('')
const categoryImageFiles = ref([])
const categoryForm = reactive({
  title: '',
  is_active: true,
})

const warehouseDialogOpen = ref(false)
const warehouseSubmitting = ref(false)
const warehouseFormError = ref('')
const warehouseForm = reactive({
  name: '',
  address: '',
  branch_id: null,
  state: '',
})

const categoryItems = computed(() =>
  categories.value.map(c => ({
    title: c.is_active === false ? `${c.title} (inactiva)` : c.title,
    value: c.id,
  })),
)

const warehouseItems = computed(() =>
  warehouses.value.map(w => ({
    title: w.branch?.name ? `${w.name} — ${w.branch.name}` : w.name,
    value: w.id,
  })),
)

function warehouseItemsForRow(rowIndex) {
  const taken = new Set(
    warehouseLines.value
      .map((l, i) => (i !== rowIndex && l.warehouse_id != null ? l.warehouse_id : null))
      .filter(v => v != null),
  )

  return warehouses.value.map(w => ({
    title: w.branch?.name ? `${w.name} — ${w.branch.name}` : w.name,
    value: w.id,
    props: { disabled: taken.has(w.id) },
  }))
}

function emptyWarehouseLine() {
  return {
    warehouse_id: null,
    stock: 0,
    umbral: 0,
    sale_price: '',
    purchase_price: '',
  }
}

function addWarehouseLine() {
  warehouseLines.value.push(emptyWarehouseLine())
}

function removeWarehouseLine(index) {
  warehouseLines.value.splice(index, 1)
  if (!warehouseLines.value.length)
    warehouseLines.value.push(emptyWarehouseLine())
}

const branchSelectItems = computed(() =>
  branchOptions.value.map(b => ({
    title: b.code ? `${b.name} (${b.code})` : b.name,
    value: b.id,
  })),
)

function resetMainForm() {
  form.name = ''
  form.sku = ''
  form.generate_sku = true
  form.price = ''
  form.wholesale_price = ''
  form.description = ''
  form.cost_price = ''
  form.discount_percent = 0
  form.warranty_days = 0
  form.is_active = true
  form.is_gift_card = false
  form.category_id = null
  form.barcode = ''
  form.generate_barcode = true
  warehouseLines.value = [emptyWarehouseLine()]
  if (warehouseItems.value.length && warehouseLines.value[0])
    warehouseLines.value[0].warehouse_id = warehouseItems.value[0].value
  clearProductImage()
}

async function fetchCategories() {
  try {
    const res = await $api('/categories')

    categories.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    categories.value = []
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

async function fetchBranchOptions() {
  try {
    const res = await $api('/branches')

    branchOptions.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    branchOptions.value = []
  }
}

function appendProductFormFields(fd, src) {
  fd.append('generate_sku', src.generate_sku ? '1' : '0')
  if (!src.generate_sku)
    fd.append('sku', String(src.sku || '').trim())

  fd.append('name', String(src.name || '').trim())
  const desc = String(src.description ?? '').trim()
  if (desc !== '')
    fd.append('description', desc)

  fd.append('price', String(src.price ?? '').replace(',', '.'))
  const wp = String(src.wholesale_price ?? '').trim()
  if (wp !== '')
    fd.append('wholesale_price', wp.replace(',', '.'))

  fd.append('discount_percent', String(Number(src.discount_percent) || 0))
  fd.append('warranty_days', String(Math.max(0, parseInt(String(src.warranty_days), 10) || 0)))
  fd.append('is_active', src.is_active ? '1' : '0')
  fd.append('is_gift_card', src.is_gift_card ? '1' : '0')
  fd.append('generate_barcode', src.generate_barcode ? '1' : '0')

  const bc = String(src.barcode || '').trim()
  if (bc)
    fd.append('barcode', bc)

  const cp = String(src.cost_price ?? '').trim()
  if (cp !== '')
    fd.append('cost_price', cp.replace(',', '.'))

  if (src.category_id != null && src.category_id !== '')
    fd.append('category_id', String(src.category_id))

  const lines = (src.warehouseLines || []).filter(l => l.warehouse_id != null && l.warehouse_id !== '')
  const payload = lines.map(line => {
    const sp = String(line.sale_price ?? '').trim().replace(',', '.')
    const pp = String(line.purchase_price ?? '').trim().replace(',', '.')

    return {
      warehouse_id: Number(line.warehouse_id),
      stock: Number(line.stock) || 0,
      umbral: Math.max(0, parseInt(String(line.umbral), 10) || 0),
      sale_price: sp !== '' ? Number(sp) : null,
      purchase_price: pp !== '' ? Number(pp) : null,
    }
  })
  fd.append('warehouse_lines_json', JSON.stringify(payload))
}

async function submitProduct() {
  submitError.value = ''
  const name = form.name.trim()
  const price = String(form.price || '').replace(',', '.')
  const cost = String(form.cost_price || '').trim().replace(',', '.')
  if (cost !== '' && Number(cost) < 0) {
    submitError.value = 'El precio de compra debe ser mayor o igual a 0.'

    return
  }

  const wholesaleRaw = String(form.wholesale_price || '').trim().replace(',', '.')
  if (wholesaleRaw !== '' && Number(wholesaleRaw) < 0) {
    submitError.value = 'El precio empresa / mayoreo debe ser mayor o igual a 0.'

    return
  }

  if (!name || price === '' || Number(price) < 0) {
    submitError.value = 'Completá título y precio final válido.'

    return
  }
  if (!form.generate_sku && !form.sku.trim()) {
    submitError.value = 'Indicá el SKU o activá la generación automática.'

    return
  }

  const lines = warehouseLines.value.filter(l => l.warehouse_id != null && l.warehouse_id !== '')
  for (const line of lines) {
    const st = Number(line.stock) || 0
    const um = Math.max(0, parseInt(String(line.umbral), 10) || 0)
    if ((st > 0 || um > 0) && (line.warehouse_id == null || line.warehouse_id === '')) {
      submitError.value = 'Indicá almacén en cada fila donde hay stock o umbral.'

      return
    }
    const sp = String(line.sale_price ?? '').trim().replace(',', '.')
    const pp = String(line.purchase_price ?? '').trim().replace(',', '.')
    if (sp !== '' && Number(sp) < 0) {
      submitError.value = 'Los precios de venta por almacén deben ser válidos.'

      return
    }
    if (pp !== '' && Number(pp) < 0) {
      submitError.value = 'Los precios de compra por almacén deben ser válidos.'

      return
    }
  }

  submitting.value = true

  try {
    const fd = new FormData()

    appendProductFormFields(fd, { ...form, warehouseLines: lines })

    const files = imageFiles.value
    if (files?.length && files[0])
      fd.append('image', files[0])

    await $api('/products', {
      method: 'POST',
      body: fd,
    })

    await router.push({ name: 'productos' })
  }
  catch (e) {
    submitError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para crear productos.',
      fallback: 'No se pudo crear el producto.',
    })
  }
  finally {
    submitting.value = false
  }
}

function openCategoryDialog() {
  categoryForm.title = ''
  categoryForm.is_active = true
  categoryImageFiles.value = []
  categoryFormError.value = ''
  categoryDialogOpen.value = true
}

function closeCategoryDialog() {
  categoryDialogOpen.value = false
}

async function submitQuickCategory() {
  categoryFormError.value = ''
  const title = categoryForm.title.trim()

  if (!title) {
    categoryFormError.value = 'Completá el título de la categoría.'

    return
  }

  categorySubmitting.value = true

  try {
    const fd = new FormData()

    fd.append('title', title)
    fd.append('is_active', categoryForm.is_active ? '1' : '0')

    const files = categoryImageFiles.value
    if (files?.length && files[0])
      fd.append('image', files[0])

    const res = await $api('/categories', {
      method: 'POST',
      body: fd,
    })

    const newId = res?.data?.id
    await fetchCategories()
    if (newId != null)
      form.category_id = newId

    snackbar.text = 'Categoría registrada. Ya podés usarla en el producto.'
    snackbar.show = true
    closeCategoryDialog()
  }
  catch (e) {
    categoryFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para crear categorías.',
      fallback: 'No se pudo crear la categoría.',
    })
  }
  finally {
    categorySubmitting.value = false
  }
}

async function openWarehouseDialog() {
  warehouseForm.name = ''
  warehouseForm.address = ''
  warehouseForm.state = ''
  warehouseFormError.value = ''
  await fetchBranchOptions()
  warehouseForm.branch_id = branchSelectItems.value[0]?.value ?? null
  await loadBoliviaDepartments()
  warehouseDialogOpen.value = true
}

function closeWarehouseDialog() {
  warehouseDialogOpen.value = false
}

async function submitQuickWarehouse() {
  warehouseFormError.value = ''
  const name = warehouseForm.name.trim()
  const address = warehouseForm.address.trim()
  const state = warehouseForm.state.trim()

  if (!name || !address || !state) {
    warehouseFormError.value = 'Completá nombre, dirección y departamento del almacén.'

    return
  }
  if (warehouseForm.branch_id == null || warehouseForm.branch_id === '') {
    warehouseFormError.value = 'Seleccioná la sucursal.'

    return
  }

  warehouseSubmitting.value = true

  try {
    const res = await $api('/warehouses', {
      method: 'POST',
      body: {
        name,
        address,
        branch_id: warehouseForm.branch_id,
        state,
      },
    })

    const newId = res?.data?.id
    await fetchWarehouses()
    if (newId != null) {
      const free = warehouseLines.value.find(l => l.warehouse_id == null || l.warehouse_id === '')
      if (free)
        free.warehouse_id = newId
      else
        warehouseLines.value.push({ ...emptyWarehouseLine(), warehouse_id: newId })
    }

    snackbar.text = 'Almacén registrado. Elegilo en una fila o seguí editando.'
    snackbar.show = true
    closeWarehouseDialog()
  }
  catch (e) {
    warehouseFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para crear almacenes.',
      fallback: 'No se pudo crear el almacén.',
    })
  }
  finally {
    warehouseSubmitting.value = false
  }
}

onMounted(async () => {
  await authStore.fetchMe(true)
  if (!canManage.value)
    return

  await Promise.all([fetchCategories(), fetchWarehouses()])
  warehouseLines.value = [emptyWarehouseLine()]
  if (warehouseItems.value.length)
    warehouseLines.value[0].warehouse_id = warehouseItems.value[0].value
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
      v-else-if="!canManage"
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
        <p class="text-body-1 text-medium-emphasis mb-8 mx-auto" style="max-width: 420px;">
          Solo los administradores pueden registrar productos.
        </p>
        <VBtn
          color="primary"
          prepend-icon="ri-arrow-left-line"
          :to="{ name: 'productos' }"
        >
          Ir al listado
        </VBtn>
      </VCardText>
    </VCard>

    <template v-else>
      <div class="producto-registrar-root">
      <VCard class="producto-registrar-page">
        <VCardText class="pb-4">
          <VRow align="center">
            <VCol cols="12" lg="8">
              <VCardTitle class="text-h5 pa-0 pb-1">
                Registrar producto
              </VCardTitle>
              <VCardSubtitle class="text-wrap pa-0">
                Identidad y precios de venta en la primera tarjeta; compra y precios por almacén en la segunda. La foto y guardar quedan a la derecha en pantallas grandes.
              </VCardSubtitle>
            </VCol>
            <VCol
              cols="12"
              lg="4"
              class="d-flex justify-lg-end"
            >
              <VBtn
                variant="tonal"
                prepend-icon="ri-arrow-left-line"
                :to="{ name: 'productos' }"
              >
                Volver al listado
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardText class="pt-4 pb-lg-6">
          <VAlert
            v-if="submitError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            rounded="lg"
          >
            {{ submitError }}
          </VAlert>

          <VForm
            id="producto-registrar-form"
            @submit.prevent="submitProduct"
          >
            <VRow class="flex-lg-nowrap">
              <!-- Columna scroll: datos -->
              <VCol
                cols="12"
                lg="8"
                class="producto-registrar-scroll-col"
              >
                <div class="producto-registrar-scroll">
                  <VCard
                    variant="outlined"
                    class="mb-4"
                  >
                    <VCardItem class="pb-2">
                      <template #prepend>
                        <VAvatar
                          color="primary"
                          variant="tonal"
                          size="40"
                          rounded
                        >
                          <VIcon icon="ri-store-3-line" />
                        </VAvatar>
                      </template>
                      <VCardTitle class="text-h6">
                        Identidad y precios de venta
                      </VCardTitle>
                      <VCardSubtitle class="text-wrap">
                        Lo que ve el cliente: nombre, SKU, precio final, precio empresa y descripción.
                      </VCardSubtitle>
                    </VCardItem>
                    <VDivider />
                    <VCardText class="pt-4">
                      <VTextField
                        v-model="form.name"
                        label="Título / nombre *"
                        density="comfortable"
                        class="mb-3"
                        :disabled="submitting"
                      />
                      <VCheckbox
                        v-model="form.generate_sku"
                        density="comfortable"
                        hide-details
                        class="mb-1"
                        label="Generar SKU automáticamente"
                        :disabled="submitting"
                      />
                      <VTextField
                        v-model="form.sku"
                        :label="form.generate_sku ? 'SKU (se asigna al guardar)' : 'SKU *'"
                        density="comfortable"
                        class="mb-3"
                        :hint="form.generate_sku ? 'Desmarcá para escribir un SKU propio.' : 'Código interno único.'"
                        persistent-hint
                        :disabled="submitting || form.generate_sku"
                        :readonly="form.generate_sku"
                      />

                      <VRow dense>
                        <VCol
                          cols="12"
                          md="6"
                        >
                          <VTextField
                            v-model="form.price"
                            label="Precio de venta final *"
                            type="text"
                            inputmode="decimal"
                            density="comfortable"
                            hint="Precio al público / ticket."
                            persistent-hint
                            :disabled="submitting"
                          />
                        </VCol>
                        <VCol
                          cols="12"
                          md="6"
                        >
                          <VTextField
                            v-model="form.wholesale_price"
                            label="Precio de venta empresa"
                            type="text"
                            inputmode="decimal"
                            density="comfortable"
                            hint="Mayoreo, convenios o venta corporativa (opcional)."
                            persistent-hint
                            clearable
                            :disabled="submitting"
                          />
                        </VCol>
                      </VRow>

                      <VTextarea
                        v-model="form.description"
                        label="Descripción"
                        rows="4"
                        auto-grow
                        density="comfortable"
                        variant="outlined"
                        class="mt-3 mb-1"
                        hint="Características, materiales, talles, cuidados…"
                        persistent-hint
                        :disabled="submitting"
                      />

                      <VRow
                        dense
                        class="mt-3"
                      >
                        <VCol
                          cols="12"
                          sm="6"
                        >
                          <VTextField
                            v-model.number="form.discount_percent"
                            label="Descuento %"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            density="comfortable"
                            :disabled="submitting"
                          />
                        </VCol>
                        <VCol
                          cols="12"
                          sm="6"
                        >
                          <VTextField
                            v-model.number="form.warranty_days"
                            label="Días de garantía"
                            type="number"
                            min="0"
                            density="comfortable"
                            :disabled="submitting"
                          />
                        </VCol>
                      </VRow>
                    </VCardText>
                  </VCard>

                  <VCard
                    variant="outlined"
                    class="mb-4"
                  >
                    <VCardItem class="pb-2">
                      <template #prepend>
                        <VAvatar
                          color="warning"
                          variant="tonal"
                          size="40"
                          rounded
                        >
                          <VIcon icon="ri-building-4-line" />
                        </VAvatar>
                      </template>
                      <VCardTitle class="text-h6">
                        Compra y precios por almacén
                      </VCardTitle>
                      <VCardSubtitle class="text-wrap">
                        Referencia de costo y, por cada ubicación, stock y precios propios (opcionales).
                      </VCardSubtitle>
                      <template #append>
                        <VBtn
                          color="primary"
                          variant="tonal"
                          size="small"
                          prepend-icon="ri-add-line"
                          :disabled="submitting"
                          @click="openWarehouseDialog"
                        >
                          Nuevo almacén
                        </VBtn>
                      </template>
                    </VCardItem>
                    <VDivider />
                    <VCardText class="pt-4">
                      <VTextField
                        v-model="form.cost_price"
                        label="Precio de compra (referencia general)"
                        type="text"
                        inputmode="decimal"
                        density="comfortable"
                        class="mb-4"
                        hint="Costo medio o última compra. Opcional si cargás compra por almacén."
                        persistent-hint
                        clearable
                        :disabled="submitting"
                      />

                      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                        <span class="text-subtitle-2 text-medium-emphasis">Filas por almacén</span>
                        <VBtn
                          color="primary"
                          variant="tonal"
                          size="small"
                          prepend-icon="ri-add-line"
                          :disabled="submitting"
                          @click="addWarehouseLine"
                        >
                          Agregar fila
                        </VBtn>
                      </div>

                      <VSheet
                        v-for="(line, idx) in warehouseLines"
                        :key="idx"
                        border
                        rounded="lg"
                        class="pa-4 mb-3"
                      >
                        <div class="d-flex align-center justify-space-between mb-3">
                          <span class="text-body-2 font-weight-medium">Almacén {{ idx + 1 }}</span>
                          <VBtn
                            v-if="warehouseLines.length > 1"
                            icon
                            variant="text"
                            size="small"
                            color="error"
                            :disabled="submitting"
                            @click="removeWarehouseLine(idx)"
                          >
                            <VIcon icon="ri-delete-bin-line" />
                            <VTooltip
                              activator="parent"
                              location="top"
                            >
                              Quitar fila
                            </VTooltip>
                          </VBtn>
                        </div>
                        <VRow dense>
                          <VCol cols="12">
                            <VSelect
                              v-model="line.warehouse_id"
                              :items="warehouseItemsForRow(idx)"
                              label="Almacén"
                              clearable
                              density="comfortable"
                              variant="outlined"
                              hide-details="auto"
                              :disabled="submitting"
                              no-data-text="Sin almacenes. Creá uno con «Nuevo almacén»."
                            />
                          </VCol>
                          <VCol
                            cols="6"
                            sm="3"
                          >
                            <VTextField
                              v-model.number="line.stock"
                              label="Stock"
                              type="number"
                              min="0"
                              density="comfortable"
                              :disabled="submitting"
                            />
                          </VCol>
                          <VCol
                            cols="6"
                            sm="3"
                          >
                            <VTextField
                              v-model.number="line.umbral"
                              label="Umbral"
                              type="number"
                              min="0"
                              density="comfortable"
                              hint="Mín. reposición"
                              persistent-hint
                              :disabled="submitting"
                            />
                          </VCol>
                          <VCol
                            cols="12"
                            sm="3"
                          >
                            <VTextField
                              v-model="line.sale_price"
                              label="Precio venta (este almacén)"
                              type="text"
                              inputmode="decimal"
                              density="comfortable"
                              hint="Vacío = usa precio final"
                              persistent-hint
                              clearable
                              :disabled="submitting"
                            />
                          </VCol>
                          <VCol
                            cols="12"
                            sm="3"
                          >
                            <VTextField
                              v-model="line.purchase_price"
                              label="Precio compra (este almacén)"
                              type="text"
                              inputmode="decimal"
                              density="comfortable"
                              hint="Opcional"
                              persistent-hint
                              clearable
                              :disabled="submitting"
                            />
                          </VCol>
                        </VRow>
                      </VSheet>

                      <VAlert
                        type="info"
                        variant="tonal"
                        density="compact"
                        rounded="lg"
                        class="text-body-2"
                      >
                        Si hay stock o umbral en una fila, tenés que elegir almacén en esa fila. Los precios por almacén son opcionales y sobreescriben solo en esa ubicación.
                      </VAlert>
                    </VCardText>
                  </VCard>

                  <VCard
                    variant="outlined"
                    class="mb-4"
                  >
                    <VCardItem class="pb-2">
                      <template #prepend>
                        <VAvatar
                          color="secondary"
                          variant="tonal"
                          size="40"
                          rounded
                        >
                          <VIcon icon="ri-price-tag-3-line" />
                        </VAvatar>
                      </template>
                      <VCardTitle class="text-h6">
                        Clasificación
                      </VCardTitle>
                      <VCardSubtitle class="text-wrap">
                        Categoría del catálogo.
                      </VCardSubtitle>
                    </VCardItem>
                    <VDivider />
                    <VCardText class="pt-4">
                      <div class="d-flex flex-wrap align-end gap-2">
                        <VSelect
                          v-model="form.category_id"
                          :items="categoryItems"
                          label="Categoría"
                          clearable
                          density="comfortable"
                          variant="outlined"
                          hide-details="auto"
                          class="flex-grow-1"
                          style="min-width: 200px;"
                          :disabled="submitting"
                        />
                        <VBtn
                          color="primary"
                          variant="tonal"
                          prepend-icon="ri-add-line"
                          :disabled="submitting"
                          @click="openCategoryDialog"
                        >
                          Nueva categoría
                        </VBtn>
                      </div>
                    </VCardText>
                  </VCard>

                  <VCard
                    variant="outlined"
                    class="mb-4"
                  >
                    <VCardItem class="pb-2">
                      <template #prepend>
                        <VAvatar
                          color="info"
                          variant="tonal"
                          size="40"
                          rounded
                        >
                          <VIcon icon="ri-barcode-line" />
                        </VAvatar>
                      </template>
                      <VCardTitle class="text-h6">
                        Código de barras
                      </VCardTitle>
                      <VCardSubtitle class="text-wrap">
                        Manual o generación automática EAN-13 interna.
                      </VCardSubtitle>
                    </VCardItem>
                    <VDivider />
                    <VCardText class="pt-4">
                      <VTextField
                        v-model="form.barcode"
                        label="Código de barras (opcional)"
                        density="comfortable"
                        class="mb-2"
                        hint="Si lo dejás vacío y marcás generar, se asigna un EAN-13 interno (prefijo 2)."
                        persistent-hint
                        :disabled="submitting || form.generate_barcode"
                      />
                      <VCheckbox
                        v-model="form.generate_barcode"
                        density="comfortable"
                        hide-details
                        label="Generar código de barras automáticamente si está vacío"
                        :disabled="submitting"
                      />
                    </VCardText>
                  </VCard>

                  <VCard
                    variant="outlined"
                    class="mb-0 mb-lg-4"
                  >
                    <VCardItem class="pb-2">
                      <template #prepend>
                        <VAvatar
                          color="success"
                          variant="tonal"
                          size="40"
                          rounded
                        >
                          <VIcon icon="ri-store-2-line" />
                        </VAvatar>
                      </template>
                      <VCardTitle class="text-h6">
                        Opciones de venta
                      </VCardTitle>
                      <VCardSubtitle class="text-wrap">
                        Visibilidad para cajeros y tipo de producto.
                      </VCardSubtitle>
                    </VCardItem>
                    <VDivider />
                    <VCardText class="pt-4">
                      <VSwitch
                        v-model="form.is_gift_card"
                        inset
                        color="primary"
                        label="Producto tipo gift card"
                        hide-details
                        class="mb-2"
                        :disabled="submitting"
                      />
                      <VSwitch
                        v-model="form.is_active"
                        inset
                        color="primary"
                        label="Activo (visible para cajeros)"
                        hide-details
                        :disabled="submitting"
                      />
                    </VCardText>
                  </VCard>
                </div>
              </VCol>

              <!-- Columna fija: imagen + acciones -->
              <VCol
                cols="12"
                lg="4"
                class="producto-registrar-sticky-col"
              >
                <div class="producto-registrar-sticky">
                  <input
                    ref="fileInputEl"
                    type="file"
                    accept="image/*"
                    class="d-none"
                    @change="onImageInputChange"
                  >

                  <VCard
                    variant="outlined"
                    class="mb-4"
                  >
                    <VCardItem class="pb-2">
                      <VCardTitle class="text-h6">
                        Foto del producto
                      </VCardTitle>
                      <VCardSubtitle>Opcional · JPG, PNG…</VCardSubtitle>
                    </VCardItem>
                    <VDivider />
                    <VCardText class="pt-4">
                      <VSheet
                        rounded="lg"
                        :color="imageDragOver ? 'primary' : undefined"
                        :class="[
                          'producto-registrar-drop pa-4 text-center cursor-pointer',
                          imageDragOver ? 'opacity-90' : '',
                          imagePreviewUrl ? 'bg-surface' : 'border-dashed',
                        ]"
                        border
                        @click="triggerImagePick"
                        @keydown.enter.prevent="triggerImagePick"
                        @dragover.prevent="imageDragOver = true"
                        @dragleave.prevent="imageDragOver = false"
                        @drop.prevent="onImageDrop"
                      >
                        <template v-if="imagePreviewUrl">
                          <div class="producto-registrar-preview-wrap rounded-lg overflow-hidden">
                            <img
                              :src="imagePreviewUrl"
                              alt="Vista previa del producto"
                              class="producto-registrar-preview-img"
                            >
                          </div>
                          <VBtn
                            class="mt-3"
                            size="small"
                            variant="text"
                            color="error"
                            prepend-icon="ri-delete-bin-line"
                            :disabled="submitting"
                            @click.stop="clearProductImage"
                          >
                            Quitar imagen
                          </VBtn>
                        </template>
                        <template v-else>
                          <VAvatar
                            size="64"
                            color="primary"
                            variant="tonal"
                            class="mb-3"
                          >
                            <VIcon
                              icon="ri-image-add-line"
                              size="32"
                            />
                          </VAvatar>
                          <div class="text-body-1 font-weight-medium">
                            Subir imagen
                          </div>
                          <p class="text-body-2 text-medium-emphasis mb-0 px-2">
                            Clic o soltá un archivo aquí
                          </p>
                        </template>
                      </VSheet>
                    </VCardText>
                  </VCard>

                  <VCard
                    variant="flat"
                    class="producto-registrar-actions-card border"
                  >
                    <VCardText class="d-none d-lg-block">
                      <div class="d-flex flex-column align-stretch gap-2">
                        <VBtn
                          type="submit"
                          color="primary"
                          size="large"
                          block
                          :loading="submitting"
                          prepend-icon="ri-check-line"
                        >
                          Crear producto
                        </VBtn>
                        <VBtn
                          variant="text"
                          block
                          :disabled="submitting"
                          prepend-icon="ri-refresh-line"
                          @click="resetMainForm"
                        >
                          Limpiar formulario
                        </VBtn>
                      </div>
                    </VCardText>
                  </VCard>
                </div>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>

      <!-- Barra de acción fija abajo en pantallas chicas (Crear a la derecha) -->
      <VSheet
        border="t"
        rounded="0"
        class="producto-registrar-mobile-bar d-lg-none mt-0"
      >
        <div class="d-flex align-center justify-end gap-2 pa-4">
          <VBtn
            variant="text"
            :disabled="submitting"
            @click="resetMainForm"
          >
            Limpiar
          </VBtn>
          <VBtn
            type="submit"
            color="primary"
            size="large"
            :loading="submitting"
            form="producto-registrar-form"
            prepend-icon="ri-check-line"
          >
            Crear producto
          </VBtn>
        </div>
      </VSheet>

      <VDialog
        v-model="categoryDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 480"
        scrollable
        @after-leave="categoryFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Nueva categoría</VCardTitle>
            <VCardSubtitle>Se guarda y queda seleccionada en el producto.</VCardSubtitle>
            <template #append>
              <VBtn
                icon
                variant="text"
                @click="closeCategoryDialog"
              >
                <VIcon icon="ri-close-line" />
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText class="pt-4">
            <VAlert
              v-if="categoryFormError"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
              rounded="lg"
            >
              {{ categoryFormError }}
            </VAlert>
            <VForm @submit.prevent="submitQuickCategory">
              <VTextField
                v-model="categoryForm.title"
                label="Título *"
                density="comfortable"
                class="mb-4"
                :disabled="categorySubmitting"
              />
              <VSwitch
                v-model="categoryForm.is_active"
                inset
                color="primary"
                label="Activa"
                hide-details
                class="mb-4"
                :disabled="categorySubmitting"
              />
              <VFileInput
                v-model="categoryImageFiles"
                label="Imagen (opcional)"
                prepend-icon="ri-image-add-line"
                accept="image/*"
                show-size
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-6"
                :disabled="categorySubmitting"
              />
              <div class="d-flex justify-end gap-2">
                <VBtn
                  variant="text"
                  :disabled="categorySubmitting"
                  @click="closeCategoryDialog"
                >
                  Cancelar
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="categorySubmitting"
                >
                  Guardar
                </VBtn>
              </div>
            </VForm>
          </VCardText>
        </VCard>
      </VDialog>

      <VDialog
        v-model="warehouseDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 560"
        scrollable
        @after-leave="warehouseFormError = ''"
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
                @click="closeWarehouseDialog"
              >
                <VIcon icon="ri-close-line" />
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText class="pt-4">
            <VAlert
              v-if="warehouseFormError"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
              rounded="lg"
            >
              {{ warehouseFormError }}
            </VAlert>
            <VForm @submit.prevent="submitQuickWarehouse">
              <VTextField
                v-model="warehouseForm.name"
                density="comfortable"
                class="mb-4"
                autocomplete="organization"
                :disabled="warehouseSubmitting"
              >
                <template #label>
                  <span>Nombre <span class="text-error">*</span></span>
                </template>
              </VTextField>

              <VSelect
                v-model="warehouseForm.branch_id"
                :items="branchSelectItems"
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-4"
                :disabled="warehouseSubmitting || !branchSelectItems.length"
                no-data-text="No hay sucursales. Creá una en Configuraciones → Sucursales."
              >
                <template #label>
                  <span>Sucursal <span class="text-error">*</span></span>
                </template>
              </VSelect>

              <VTextarea
                v-model="warehouseForm.address"
                rows="3"
                auto-grow
                density="comfortable"
                class="mb-4"
                :disabled="warehouseSubmitting"
              >
                <template #label>
                  <span>Dirección <span class="text-error">*</span></span>
                </template>
              </VTextarea>

              <VSelect
                v-model="warehouseForm.state"
                :items="boliviaDepartments"
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-2"
                autocomplete="address-level1"
                :disabled="warehouseSubmitting"
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
                  :disabled="warehouseSubmitting"
                  @click="closeWarehouseDialog"
                >
                  Cancelar
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="warehouseSubmitting"
                >
                  Guardar
                </VBtn>
              </div>
            </VForm>
          </VCardText>
        </VCard>
      </VDialog>

      <VSnackbar
        v-model="snackbar.show"
        location="bottom"
        :timeout="3200"
      >
        {{ snackbar.text }}
      </VSnackbar>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.producto-registrar-root {
  padding-block-end: 5.5rem;

  @media (min-width: 1280px) {
    padding-block-end: 0;
  }
}

.producto-registrar-scroll {
  @media (min-width: 1280px) {
    max-height: calc(100vh - 12rem);
    overflow-y: auto;
    padding-inline-end: 6px;
    scrollbar-gutter: stable;
  }
}

.producto-registrar-sticky {
  @media (min-width: 1280px) {
    position: sticky;
    top: 1rem;
    align-self: flex-start;
  }
}

.producto-registrar-mobile-bar {
  position: sticky;
  bottom: 0;
  z-index: 4;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 -4px 24px rgba(0, 0, 0, 0.06);
}

.producto-registrar-drop {
  min-block-size: 200px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.producto-registrar-drop.border-dashed {
  border-style: dashed !important;
}

.producto-registrar-preview-wrap {
  inline-size: 100%;
  max-inline-size: 280px;
  margin-inline: auto;
  aspect-ratio: 1;
  background: rgb(var(--v-theme-surface-variant));
}

.producto-registrar-preview-img {
  display: block;
  inline-size: 100%;
  block-size: 100%;
  max-block-size: 280px;
  object-fit: cover;
  vertical-align: middle;
}

.cursor-pointer {
  cursor: pointer;
}
</style>
