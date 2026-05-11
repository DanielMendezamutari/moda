<script setup>
import BarcodeLabelPrintDialog from '@/components/BarcodeLabelPrintDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'productos',
  },
})

const authStore = useAuthStore()

const canView = computed(() =>
  authStore.isAdmin || authStore.hasRole('cashier'),
)

const canManage = computed(() => authStore.isAdmin)

const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const errorMsg = ref('')
const products = ref([])
const categories = ref([])
const warehouses = ref([])

const snackbar = reactive({
  show: false,
  text: '',
})

const detailOpen = ref(false)
const detailProduct = ref(null)

const editDialogOpen = ref(false)
const editSubmitting = ref(false)
const editFormError = ref('')
const editImageFiles = ref([])
const editRemoveImage = ref(false)
const editHadImage = ref(false)
const editForm = reactive({
  id: null,
  name: '',
  sku: '',
  price: '',
  wholesale_price: '',
  description: '',
  cost_price: '',
  discount_percent: 0,
  warranty_days: 0,
  stock: 0,
  umbral: 0,
  is_active: true,
  is_gift_card: false,
  category_id: null,
  warehouse_id: null,
  barcode: '',
  generate_barcode: false,
})

const editWarehouseLines = ref([])

function emptyEditWarehouseLine() {
  return {
    warehouse_id: null,
    stock: 0,
    umbral: 0,
    sale_price: '',
    purchase_price: '',
  }
}

function editWarehouseItemsForRow(rowIndex) {
  const taken = new Set(
    editWarehouseLines.value
      .map((l, i) => (i !== rowIndex && l.warehouse_id != null ? l.warehouse_id : null))
      .filter(v => v != null),
  )

  return warehouses.value.map(w => ({
    title: w.branch?.name ? `${w.name} — ${w.branch.name}` : w.name,
    value: w.id,
    props: { disabled: taken.has(w.id) },
  }))
}

function addEditWarehouseLine() {
  editWarehouseLines.value.push(emptyEditWarehouseLine())
}

function removeEditWarehouseLine(index) {
  editWarehouseLines.value.splice(index, 1)
  if (!editWarehouseLines.value.length)
    editWarehouseLines.value.push(emptyEditWarehouseLine())
}

function appendWarehouseLinesToFormData(fd, lines) {
  const filtered = lines.filter(l => l.warehouse_id != null && l.warehouse_id !== '')
  const payload = filtered.map(line => {
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
  fd.append('sync_warehouse_lines', '1')
  fd.append('warehouse_lines_json', JSON.stringify(payload))
}

const deleteDialogOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref(null)

const barcodePrintOpen = ref(false)
const barcodePrintName = ref('')
const barcodePrintValue = ref('')

const exportBusy = ref(false)
const exportBusyKind = ref(null)

const importDialogOpen = ref(false)
const importBusy = ref(false)
const importTemplateBusy = ref(null)
const importFile = ref(null)
const importSummary = ref(null)

/** Ayuda en el modal: misma semántica que la plantilla del backend */
const importColumnHelp = [
  { title: 'SKU', hint: 'Opcional. Si está vacío se genera automático (PRD-…). Debe ser único en el sistema y en el archivo.' },
  { title: 'Nombre', hint: 'Obligatorio.' },
  { title: 'Descripción', hint: 'Opcional.' },
  { title: 'Categoría', hint: 'Nombre exacto de una categoría existente (como en el menú Categorías). Vacío = sin categoría.' },
  { title: 'Precio final', hint: 'Obligatorio. Usá punto o coma decimal (ej. 25,50).' },
  { title: 'Precio mayoreo / Precio costo', hint: 'Opcionales.' },
  { title: 'Dto. %', hint: '0–100. Vacío = 0.' },
  { title: 'ID almacén', hint: 'Número de almacén (ver listado Almacenes). Si Stock o Umbral > 0, es obligatorio.' },
  { title: 'Stock / Umbral', hint: 'Enteros ≥ 0 para ese almacén.' },
  { title: 'Activo / Gift card / Generar código barras', hint: 'Sí o No (también 1/0). Si «Generar código barras» = Sí, se ignora la columna Código barras.' },
  { title: 'Código barras', hint: 'Opcional; debe ser único si lo informás.' },
  { title: 'Garantía (días)', hint: 'Entero; vacío = 0.' },
]

const dataExportFormats = [
  { value: 'csv', title: 'Descargar CSV', icon: 'ri-file-text-line' },
  { value: 'xlsx', title: 'Descargar Excel', icon: 'ri-file-excel-line' },
  { value: 'docx', title: 'Descargar Word', icon: 'ri-file-word-line' },
]

const pdfVariants = [
  { value: 'full', title: 'Informe completo', caption: 'Listado + stock + códigos de barras' },
  { value: 'list', title: 'Solo listado general', caption: 'Tabla principal' },
  { value: 'stock', title: 'Solo stock', caption: 'Óptimo y atención' },
  { value: 'barcodes', title: 'Solo códigos de barras', caption: 'Gráficos para imprimir' },
]

function isExportBusy(kind) {
  return exportBusy.value && exportBusyKind.value === kind
}

function isPdfVariantBusy(variant) {
  return exportBusy.value && exportBusyKind.value === `pdf:${variant}`
}

const headers = [
  { title: '', key: 'thumb', sortable: false, width: 56 },
  { title: 'SKU', key: 'sku', sortable: true, minWidth: '92px' },
  { title: 'Título', key: 'name', sortable: true, minWidth: '160px', cellProps: { class: 'productos-td-name' } },
  { title: 'Categoría', key: 'category', sortable: false, minWidth: '148px' },
  { title: 'Descripción', key: 'description', sortable: false, minWidth: '200px', cellProps: { class: 'productos-td-desc' } },
  { title: 'Precio final', key: 'price', sortable: true },
  { title: 'Mayoreo', key: 'wholesale_price', sortable: true },
  { title: 'Dto. %', key: 'discount_percent', sortable: false },
  { title: 'Stock', key: 'stock', sortable: true },
  { title: 'Umbral', key: 'umbral', sortable: false },
  { title: 'Almacén', key: 'warehouse', sortable: false },
  { title: 'Activo', key: 'is_active', sortable: false },
  { title: 'Código barras', key: 'barcode', sortable: false, minWidth: '132px' },
  { title: 'Acciones', key: 'actions', sortable: false, align: 'end', width: 200 },
]

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

function formatMoney(v) {
  if (v == null || v === '')
    return '—'

  const n = Number(v)
  if (!Number.isFinite(n))
    return '—'

  return `${new Intl.NumberFormat('es', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)} Bs.`
}

/** Texto plano para tooltip / listado (sin HTML) */
function plainDescriptionText(raw) {
  if (raw == null || String(raw).trim() === '')
    return ''

  return String(raw)
    .replace(/\r\n/g, '\n')
    .replace(/\s+/g, ' ')
    .trim()
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

async function fetchProducts() {
  errorMsg.value = ''
  loading.value = true

  try {
    const q = new URLSearchParams()

    q.set('page', String(page.value))
    const s = search.value.trim()
    if (s)
      q.set('search', s)

    const res = await $api(`/products?${q.toString()}`)

    products.value = Array.isArray(res?.data) ? res.data : []
    lastPage.value = Number(res?.last_page) || 1
    total.value = Number(res?.total) ?? products.value.length
  }
  catch (e) {
    products.value = []
    errorMsg.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para ver productos.',
      fallback: 'No se pudieron cargar los productos.',
    })
  }
  finally {
    loading.value = false
  }
}

function appendProductFormFields(fd, form, warehouseLines = undefined) {
  fd.append('sku', String(form.sku || '').trim())
  fd.append('name', String(form.name || '').trim())
  const desc = String(form.description ?? '').trim()
  if (desc !== '')
    fd.append('description', desc)

  fd.append('price', String(form.price ?? '').replace(',', '.'))
  const wp = String(form.wholesale_price ?? '').trim().replace(',', '.')
  fd.append('wholesale_price', wp)

  fd.append('discount_percent', String(Number(form.discount_percent) || 0))
  fd.append('warranty_days', String(Math.max(0, parseInt(String(form.warranty_days), 10) || 0)))
  fd.append('is_active', form.is_active ? '1' : '0')
  fd.append('is_gift_card', form.is_gift_card ? '1' : '0')
  fd.append('generate_barcode', form.generate_barcode ? '1' : '0')

  const bc = String(form.barcode || '').trim()
  if (bc)
    fd.append('barcode', bc)

  const cp = String(form.cost_price ?? '').trim()
  if (cp !== '')
    fd.append('cost_price', cp.replace(',', '.'))

  if (form.category_id != null && form.category_id !== '')
    fd.append('category_id', String(form.category_id))

  if (Array.isArray(warehouseLines)) {
    appendWarehouseLinesToFormData(fd, warehouseLines)
  }
  else {
    fd.append('stock', String(Number(form.stock) || 0))
    fd.append('umbral', String(Math.max(0, parseInt(String(form.umbral), 10) || 0)))
    if (form.warehouse_id != null && form.warehouse_id !== '')
      fd.append('warehouse_id', String(form.warehouse_id))
  }
}

function openEdit(row) {
  editForm.id = row.id
  editForm.name = row.name || ''
  editForm.sku = row.sku || ''
  editForm.price = row.price ?? ''
  editForm.wholesale_price = row.wholesale_price ?? ''
  editForm.description = row.description || ''
  editForm.cost_price = row.cost_price ?? ''
  editForm.discount_percent = Number(row.discount_percent) || 0
  editForm.warranty_days = row.warranty_days ?? 0
  editForm.stock = row.stock ?? 0
  editForm.umbral = row.umbral ?? 0
  editForm.is_active = !!row.is_active
  editForm.is_gift_card = !!row.is_gift_card
  editForm.category_id = row.category_id ?? null
  editForm.warehouse_id = row.warehouse_id ?? null
  editForm.barcode = row.barcode || ''
  editForm.generate_barcode = false
  editImageFiles.value = []
  editRemoveImage.value = false
  editHadImage.value = !!row.image_url
  editFormError.value = ''

  if (Array.isArray(row.warehouse_lines) && row.warehouse_lines.length) {
    editWarehouseLines.value = row.warehouse_lines.map(l => ({
      warehouse_id: l.warehouse_id,
      stock: l.stock ?? 0,
      umbral: l.umbral ?? 0,
      sale_price: l.sale_price != null ? String(l.sale_price) : '',
      purchase_price: l.purchase_price != null ? String(l.purchase_price) : '',
    }))
  }
  else {
    editWarehouseLines.value = [emptyEditWarehouseLine()]
    editWarehouseLines.value[0].warehouse_id = row.warehouse_id ?? null
    editWarehouseLines.value[0].stock = row.stock ?? 0
    editWarehouseLines.value[0].umbral = row.umbral ?? 0
  }

  editDialogOpen.value = true
}

function closeEditDialog() {
  editDialogOpen.value = false
}

async function submitEditProduct() {
  editFormError.value = ''
  const name = editForm.name.trim()
  const sku = editForm.sku.trim()
  const price = String(editForm.price || '').replace(',', '.')

  if (!name || !sku || price === '' || Number(price) < 0) {
    editFormError.value = 'Completá título, SKU y precio final válido.'

    return
  }

  const lines = editWarehouseLines.value.filter(l => l.warehouse_id != null && l.warehouse_id !== '')
  for (const line of lines) {
    const st = Number(line.stock) || 0
    const um = Math.max(0, parseInt(String(line.umbral), 10) || 0)
    if ((st > 0 || um > 0) && (line.warehouse_id == null || line.warehouse_id === '')) {
      editFormError.value = 'Indicá almacén en cada fila donde hay stock o umbral.'

      return
    }
  }

  editSubmitting.value = true

  try {
    const fd = new FormData()

    appendProductFormFields(fd, editForm, lines)

    if (editRemoveImage.value)
      fd.append('remove_image', '1')

    const files = editImageFiles.value
    if (files?.length && files[0])
      fd.append('image', files[0])

    await $api(`/products/${editForm.id}`, {
      method: 'POST',
      body: fd,
    })

    snackbar.text = 'Producto actualizado correctamente.'
    snackbar.show = true
    closeEditDialog()
    await fetchProducts()
  }
  catch (e) {
    editFormError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para editar productos.',
      fallback: 'No se pudo actualizar el producto.',
    })
  }
  finally {
    editSubmitting.value = false
  }
}

function openDetail(row) {
  detailProduct.value = row
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
}

function openBarcodePrint(row) {
  barcodePrintName.value = row?.name || ''
  barcodePrintValue.value = row?.barcode || ''
  barcodePrintOpen.value = true
}

watch(detailOpen, open => {
  if (!open)
    detailProduct.value = null
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

async function confirmDeleteProduct() {
  if (!deleteTarget.value)
    return

  deleteSubmitting.value = true

  try {
    await $api(`/products/${deleteTarget.value.id}`, {
      method: 'DELETE',
    })

    snackbar.text = 'Producto eliminado.'
    snackbar.show = true
    closeDeleteDialog()
    await fetchProducts()
  }
  catch (e) {
    snackbar.text = messageFromApiError(e, {
      forbidden: 'No tenés permiso para eliminar productos.',
      fallback: 'No se pudo eliminar el producto.',
    })
    snackbar.show = true
  }
  finally {
    deleteSubmitting.value = false
  }
}

let searchDebounce = null
watch(search, () => {
  page.value = 1
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => {
    if (canView.value)
      fetchProducts()
  }, 320)
})

async function goPage(delta) {
  const next = page.value + delta
  if (next < 1 || next > lastPage.value)
    return
  page.value = next
  await fetchProducts()
}

async function exportProductList(format) {
  exportBusy.value = true
  exportBusyKind.value = format
  try {
    const params = new URLSearchParams()
    params.set('format', format)
    const q = search.value.trim()
    if (q)
      params.set('search', q)

    const res = await $apiRaw(`products/export?${params.toString()}`, {
      responseType: 'blob',
    })

    const blob = blobFromOfetchRawResponse(res)
    if (!blob) {
      snackbar.text = 'No se pudo leer el archivo de exportación.'
      snackbar.show = true

      return
    }

    const dispo = res.headers.get('Content-Disposition') || ''
    let filename = `productos.${format}`
    const m = /filename="?([^";\n]+)"?/i.exec(dispo)
    if (m?.[1])
      filename = m[1].trim()

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)

    snackbar.text = 'Descarga iniciada.'
    snackbar.show = true
  }
  catch (e) {
    snackbar.text = await exportErrorMessage(e, 'No se pudo exportar el listado.')
    snackbar.show = true
  }
  finally {
    exportBusy.value = false
    exportBusyKind.value = null
  }
}

async function exportErrorMessage(e, fallback) {
  if (e?.data instanceof Blob) {
    try {
      const t = await e.data.text()
      const j = JSON.parse(t)

      return j.message || j.error || fallback
    }
    catch {
      return fallback
    }
  }

  return messageFromApiError(e, { fallback })
}

function isImportTemplateBusy(format) {
  return importTemplateBusy.value === format
}

function openImportDialog() {
  importSummary.value = null
  importFile.value = null
  importDialogOpen.value = true
}

async function downloadImportTemplate(format) {
  importTemplateBusy.value = format
  try {
    const res = await $apiRaw(`products/import/template?format=${format}`, {
      method: 'GET',
      responseType: 'blob',
    })
    const blob = blobFromOfetchRawResponse(res)
    if (!blob) {
      snackbar.text = 'No se pudo descargar la plantilla.'
      snackbar.show = true

      return
    }
    const ext = format === 'csv' ? 'csv' : 'xlsx'
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `plantilla-importar-productos.${ext}`
    a.click()
    URL.revokeObjectURL(url)
    snackbar.text = 'Plantilla descargada.'
    snackbar.show = true
  }
  catch (e) {
    snackbar.text = await exportErrorMessage(e, 'No se pudo descargar la plantilla.')
    snackbar.show = true
  }
  finally {
    importTemplateBusy.value = null
  }
}

async function submitProductImport() {
  const raw = importFile.value
  const file = raw instanceof File
    ? raw
    : Array.isArray(raw) && raw[0] instanceof File
      ? raw[0]
      : null
  if (!file) {
    snackbar.text = 'Elegí un archivo CSV o Excel (.xlsx).'
    snackbar.show = true

    return
  }
  importBusy.value = true
  try {
    const fd = new FormData()
    fd.append('file', file)
    const res = await $api('products/import', { method: 'POST', body: fd })
    importSummary.value = res
    if (res.imported > 0) {
      await fetchProducts()
      snackbar.text = res.failed > 0
        ? `Importados: ${res.imported}. Filas con error: ${res.failed}.`
        : `Listo: ${res.imported} producto(s) importado(s).`
      snackbar.show = true
    }
    else {
      snackbar.text = 'No se importó ninguna fila. Revisá los errores en el cuadro de abajo.'
      snackbar.show = true
    }
  }
  catch (e) {
    importSummary.value = null
    snackbar.text = await exportErrorMessage(e, 'No se pudo importar.')
    snackbar.show = true
  }
  finally {
    importBusy.value = false
  }
}

async function downloadProductReportPdf(variant) {
  exportBusy.value = true
  exportBusyKind.value = `pdf:${variant}`
  try {
    const params = new URLSearchParams()
    params.set('variant', variant)
    const q = search.value.trim()
    if (q)
      params.set('search', q)
    const res = await $apiRaw(`products/export/pdf?${params.toString()}`, {
      responseType: 'blob',
    })

    const blob = blobFromOfetchRawResponse(res)
    if (!blob) {
      snackbar.text = 'No se pudo leer el PDF.'
      snackbar.show = true

      return
    }

    const pdfBlob = blob.type === 'application/pdf'
      ? blob
      : new Blob([blob], { type: 'application/pdf' })

    const url = URL.createObjectURL(pdfBlob)
    const win = window.open(url, '_blank', 'noopener,noreferrer')
    if (!win) {
      URL.revokeObjectURL(url)
      snackbar.text = 'No se pudo abrir la pestaña. Permití ventanas emergentes para este sitio.'
      snackbar.show = true

      return
    }

    setTimeout(() => URL.revokeObjectURL(url), 120_000)

    snackbar.text = 'PDF abierto en una nueva pestaña.'
    snackbar.show = true
  }
  catch (e) {
    snackbar.text = await exportErrorMessage(e, 'No se pudo generar el informe PDF.')
    snackbar.show = true
  }
  finally {
    exportBusy.value = false
    exportBusyKind.value = null
  }
}

onMounted(async () => {
  await authStore.fetchMe(true)
  if (!canView.value)
    return

  await fetchCategories()
  if (canManage.value)
    await fetchWarehouses()

  await fetchProducts()
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
      v-else-if="!canView"
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
          No tenés permisos para ver el catálogo de productos.
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
      <VCard
        class="productos-list-card"
        rounded="lg"
        elevation="1"
      >
        <VCardText class="pb-4">
          <VRow align="center">
            <VCol cols="12" lg="7">
              <VCardTitle class="text-h5 pa-0 pb-1 d-flex align-center gap-2 flex-wrap">
                <VIcon
                  icon="ri-store-2-line"
                  color="primary"
                  size="28"
                />
                Productos
              </VCardTitle>
              <VCardSubtitle class="text-wrap pa-0 text-body-2">
                Catálogo con descripción resumida, categoría destacada y columnas fijas (foto y acciones). Buscá también por texto en la descripción. Alta: Productos → Registrar.
              </VCardSubtitle>
            </VCol>
            <VCol
              cols="12"
              lg="5"
              class="d-flex flex-column flex-sm-row flex-wrap gap-3 align-stretch align-sm-center justify-lg-end"
            >
              <VBtn
                v-if="canManage"
                color="primary"
                prepend-icon="ri-add-line"
                :to="{ name: 'productos-registrar' }"
              >
                Nuevo producto
              </VBtn>
              <VBtn
                v-if="canManage"
                color="secondary"
                variant="tonal"
                prepend-icon="ri-upload-2-line"
                class="text-none"
                @click="openImportDialog"
              >
                Importar
              </VBtn>
              <VTextField
                v-model="search"
                density="compact"
                variant="solo-filled"
                flat
                placeholder="Nombre, SKU, código o descripción…"
                prepend-inner-icon="ri-search-line"
                hide-details
                clearable
                single-line
                class="flex-grow-1 productos-search-field"
                style="min-width: 0;"
              />
              <VBtn
                color="primary"
                variant="tonal"
                icon
                size="small"
                :loading="loading"
                @click="fetchProducts"
              >
                <VIcon icon="ri-refresh-line" />
                <VTooltip
                  activator="parent"
                  location="bottom"
                >
                  Actualizar
                </VTooltip>
              </VBtn>

              <div class="d-flex flex-wrap align-center gap-1 ms-sm-1">
                <VBtn
                  v-for="f in dataExportFormats"
                  :key="f.value"
                  icon
                  variant="text"
                  size="small"
                  density="comfortable"
                  :loading="isExportBusy(f.value)"
                  :disabled="exportBusy || loading"
                  @click="exportProductList(f.value)"
                >
                  <VIcon :icon="f.icon" />
                  <VTooltip
                    activator="parent"
                    location="bottom"
                  >
                    {{ f.title }}
                  </VTooltip>
                </VBtn>

                <VMenu location="bottom end">
                  <template #activator="{ props: menuProps }">
                    <VBtn
                      v-bind="menuProps"
                      size="small"
                      variant="tonal"
                      color="error"
                      class="text-none px-2"
                      :disabled="exportBusy || loading"
                    >
                      <VIcon
                        icon="ri-file-pdf-line"
                        size="18"
                        class="me-1"
                      />
                      PDF
                      <VIcon
                        icon="ri-arrow-down-s-line"
                        size="16"
                        class="ms-n1"
                      />
                    </VBtn>
                  </template>
                  <VList
                    density="compact"
                    class="py-1 productos-pdf-menu"
                    min-width="260"
                  >
                    <VListSubheader class="text-caption text-medium-emphasis">
                      Elegí el informe (mismo buscador · todos los resultados)
                    </VListSubheader>
                    <VListItem
                      v-for="v in pdfVariants"
                      :key="v.value"
                      :title="v.title"
                      :subtitle="v.caption"
                      prepend-icon="ri-file-pdf-line"
                      :active="false"
                      :disabled="exportBusy || loading"
                      @click="downloadProductReportPdf(v.value)"
                    >
                      <template #append>
                        <VProgressCircular
                          v-if="isPdfVariantBusy(v.value)"
                          indeterminate
                          size="18"
                          width="2"
                          color="error"
                        />
                      </template>
                    </VListItem>
                  </VList>
                </VMenu>
              </div>
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
            rounded="lg"
            color="surface"
            class="overflow-x-auto productos-table-sheet"
          >
            <VDataTable
              :headers="headers"
              :items="products"
              :loading="loading"
              item-value="id"
              class="productos-table"
              density="comfortable"
              hover
              hide-default-footer
            >
            <template #item.thumb="{ item }">
              <VAvatar
                v-if="item.image_url"
                size="44"
                rounded="lg"
                class="productos-thumb-avatar"
              >
                <VImg
                  :src="item.image_url"
                  cover
                  eager
                />
              </VAvatar>
              <VAvatar
                v-else
                size="44"
                rounded="lg"
                color="secondary"
                variant="tonal"
                class="productos-thumb-avatar"
              >
                <VIcon
                  icon="ri-image-line"
                  size="22"
                />
              </VAvatar>
            </template>

            <template #item.sku="{ item }">
              <span class="text-body-2 font-mono text-medium-emphasis">{{ item.sku }}</span>
            </template>

            <template #item.name="{ item }">
              <div class="productos-name-cell py-1">
                <div class="d-flex align-center gap-2 flex-wrap">
                  <span class="font-weight-medium text-body-1 text-high-emphasis">{{ item.name }}</span>
                  <VChip
                    v-if="item.is_gift_card"
                    size="x-small"
                    label
                    color="secondary"
                    variant="tonal"
                    class="text-uppercase"
                  >
                    Gift
                  </VChip>
                </div>
              </div>
            </template>

            <template #item.description="{ item }">
              <div
                v-if="plainDescriptionText(item.description)"
                class="productos-desc-wrap"
              >
                <VTooltip
                  location="top"
                  max-width="360"
                  :text="plainDescriptionText(item.description)"
                >
                  <template #activator="{ props: tipProps }">
                    <span
                      v-bind="tipProps"
                      class="productos-desc-preview text-body-2 text-medium-emphasis"
                    >{{ plainDescriptionText(item.description) }}</span>
                  </template>
                </VTooltip>
              </div>
              <span
                v-else
                class="text-medium-emphasis text-body-2"
              >—</span>
            </template>

            <template #item.barcode="{ item }">
              <span v-if="item.barcode" class="text-body-2">{{ item.barcode }}</span>
              <span
                v-else
                class="text-medium-emphasis text-body-2"
              >—</span>
            </template>

            <template #item.price="{ item }">
              <span class="text-body-2 font-weight-medium tabular-nums">{{ formatMoney(item.price) }}</span>
            </template>

            <template #item.wholesale_price="{ item }">
              <span class="text-body-2 tabular-nums">{{ formatMoney(item.wholesale_price) }}</span>
            </template>

            <template #item.discount_percent="{ item }">
              {{ item.discount_percent }}%
            </template>

            <template #item.category="{ item }">
              <VChip
                v-if="item.category?.title"
                size="small"
                label
                color="primary"
                variant="tonal"
                class="font-weight-medium"
              >
                {{ item.category.title }}
              </VChip>
              <span
                v-else
                class="text-medium-emphasis"
              >—</span>
            </template>

            <template #item.stock="{ item }">
              <span
                v-if="Number(item.umbral) > 0 && Number(item.stock) <= Number(item.umbral)"
                class="d-inline-flex align-center gap-1 text-warning font-weight-medium tabular-nums"
              >
                {{ item.stock }}
                <VIcon
                  icon="ri-alert-line"
                  size="18"
                />
              </span>
              <span
                v-else
                class="tabular-nums text-body-2"
              >{{ item.stock }}</span>
            </template>

            <template #item.warehouse="{ item }">
              <span
                v-if="item.warehouse?.name"
                class="text-body-2"
              >{{ item.warehouse.name }}</span>
              <span
                v-else
                class="text-medium-emphasis"
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
                Sí
              </VChip>
              <VChip
                v-else
                size="small"
                label
                color="error"
                variant="tonal"
              >
                No
              </VChip>
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
                    Ver
                  </VTooltip>
                </VBtn>
                <VBtn
                  v-if="item.barcode"
                  icon
                  variant="text"
                  size="small"
                  color="medium-emphasis"
                  @click="openBarcodePrint(item)"
                >
                  <VIcon icon="ri-printer-line" />
                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    Imprimir código de barras
                  </VTooltip>
                </VBtn>
                <template v-if="canManage">
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
                </template>
              </div>
            </template>

            <template #no-data>
              <div class="py-8 text-center text-medium-emphasis">
                No hay productos en esta página.
              </div>
            </template>
            </VDataTable>
          </VSheet>

          <div
            v-if="lastPage > 1"
            class="d-flex align-center justify-space-between flex-wrap gap-3 mt-4"
          >
            <span class="text-body-2 text-medium-emphasis">Total: {{ total }} · Página {{ page }} de {{ lastPage }}</span>
            <div class="d-flex gap-2">
              <VBtn
                variant="tonal"
                size="small"
                :disabled="page <= 1 || loading"
                @click="goPage(-1)"
              >
                Anterior
              </VBtn>
              <VBtn
                variant="tonal"
                size="small"
                :disabled="page >= lastPage || loading"
                @click="goPage(1)"
              >
                Siguiente
              </VBtn>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- Editar -->
      <VDialog
        v-model="editDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 880"
        scrollable
        @after-leave="editFormError = ''"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Editar producto</VCardTitle>
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

            <VForm @submit.prevent="submitEditProduct">
              <VTextField
                v-model="editForm.name"
                label="Título / nombre *"
                density="comfortable"
                class="mb-3"
                :disabled="editSubmitting"
              />
              <VTextField
                v-model="editForm.sku"
                label="SKU *"
                density="comfortable"
                class="mb-3"
                :disabled="editSubmitting"
              />

              <VRow dense>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="editForm.price"
                    label="Precio de venta final *"
                    type="text"
                    inputmode="decimal"
                    density="comfortable"
                    :disabled="editSubmitting"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="editForm.wholesale_price"
                    label="Precio de venta empresa"
                    type="text"
                    inputmode="decimal"
                    density="comfortable"
                    hint="Opcional."
                    persistent-hint
                    clearable
                    :disabled="editSubmitting"
                  />
                </VCol>
              </VRow>

              <VTextarea
                v-model="editForm.description"
                label="Descripción"
                rows="3"
                auto-grow
                density="comfortable"
                variant="outlined"
                class="mb-3 mt-2"
                :disabled="editSubmitting"
              />

              <VRow
                dense
                class="mt-1"
              >
                <VCol
                  cols="12"
                  sm="6"
                >
                  <VTextField
                    v-model.number="editForm.discount_percent"
                    label="Descuento %"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    density="comfortable"
                    :disabled="editSubmitting"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <VTextField
                    v-model.number="editForm.warranty_days"
                    label="Días garantía"
                    type="number"
                    min="0"
                    density="comfortable"
                    :disabled="editSubmitting"
                  />
                </VCol>
              </VRow>

              <VDivider class="my-4" />
              <p class="text-subtitle-2 mb-2">
                Compra y almacenes
              </p>
              <VTextField
                v-model="editForm.cost_price"
                label="Precio de compra (referencia general)"
                type="text"
                inputmode="decimal"
                density="comfortable"
                class="mb-3"
                clearable
                :disabled="editSubmitting"
              />

              <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
                <span class="text-body-2 text-medium-emphasis">Por almacén</span>
                <VBtn
                  size="small"
                  variant="tonal"
                  prepend-icon="ri-add-line"
                  :disabled="editSubmitting"
                  @click="addEditWarehouseLine"
                >
                  Agregar
                </VBtn>
              </div>

              <VSheet
                v-for="(line, idx) in editWarehouseLines"
                :key="idx"
                border
                rounded="lg"
                class="pa-3 mb-3"
              >
                <div class="d-flex align-center justify-space-between mb-2">
                  <span class="text-caption font-weight-medium">Fila {{ idx + 1 }}</span>
                  <VBtn
                    v-if="editWarehouseLines.length > 1"
                    icon
                    size="x-small"
                    variant="text"
                    color="error"
                    :disabled="editSubmitting"
                    @click="removeEditWarehouseLine(idx)"
                  >
                    <VIcon icon="ri-close-line" />
                  </VBtn>
                </div>
                <VRow dense>
                  <VCol cols="12">
                    <VSelect
                      v-model="line.warehouse_id"
                      :items="editWarehouseItemsForRow(idx)"
                      label="Almacén"
                      clearable
                      density="compact"
                      variant="outlined"
                      hide-details="auto"
                      :disabled="editSubmitting"
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
                      density="compact"
                      :disabled="editSubmitting"
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
                      density="compact"
                      :disabled="editSubmitting"
                    />
                  </VCol>
                  <VCol
                    cols="12"
                    sm="3"
                  >
                    <VTextField
                      v-model="line.sale_price"
                      label="Precio venta (alm.)"
                      type="text"
                      inputmode="decimal"
                      density="compact"
                      clearable
                      :disabled="editSubmitting"
                    />
                  </VCol>
                  <VCol
                    cols="12"
                    sm="3"
                  >
                    <VTextField
                      v-model="line.purchase_price"
                      label="Precio compra (alm.)"
                      type="text"
                      inputmode="decimal"
                      density="compact"
                      clearable
                      :disabled="editSubmitting"
                    />
                  </VCol>
                </VRow>
              </VSheet>

              <VSelect
                v-model="editForm.category_id"
                :items="categoryItems"
                label="Categoría"
                clearable
                density="comfortable"
                variant="outlined"
                hide-details="auto"
                class="mb-3 mt-2"
                :disabled="editSubmitting"
              />

              <VTextField
                v-model="editForm.barcode"
                label="Código de barras"
                density="comfortable"
                class="mb-2"
                :disabled="editSubmitting || editForm.generate_barcode"
              />
              <VCheckbox
                v-model="editForm.generate_barcode"
                density="comfortable"
                hide-details
                class="mb-4"
                label="Regenerar código de barras (EAN-13 nuevo)"
                :disabled="editSubmitting"
              />

              <VSwitch
                v-model="editForm.is_gift_card"
                inset
                color="primary"
                label="Gift card"
                hide-details
                class="mb-2"
                :disabled="editSubmitting"
              />
              <VSwitch
                v-model="editForm.is_active"
                inset
                color="primary"
                label="Activo"
                hide-details
                class="mb-4"
                :disabled="editSubmitting"
              />

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
                class="mb-6"
                label="Quitar imagen actual"
                :disabled="editSubmitting"
              />
              <div
                v-else
                class="mb-6"
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
                  Guardar
                </VBtn>
              </div>
            </VForm>
          </VCardText>
        </VCard>
      </VDialog>

      <!-- Detalle -->
      <VDialog
        v-model="detailOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 560"
        scrollable
      >
        <VCard v-if="detailProduct">
          <VCardItem>
            <VCardTitle>{{ detailProduct.name }}</VCardTitle>
            <VCardSubtitle>SKU: {{ detailProduct.sku }}</VCardSubtitle>
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
              v-if="detailProduct.image_url"
              class="mb-4 rounded-lg overflow-hidden border"
              style="max-height: 220px;"
            >
              <VImg
                :src="detailProduct.image_url"
                cover
                max-height="220"
              />
            </div>
            <div
              class="mb-4 pa-3 rounded-lg"
              style="background: rgba(var(--v-theme-on-surface), 0.04);"
            >
              <div class="text-caption text-medium-emphasis text-uppercase mb-1 productos-detail-desc-label">
                Descripción
              </div>
              <p
                v-if="detailProduct.description != null && String(detailProduct.description).trim() !== ''"
                class="text-body-2 mb-0 text-pre-wrap"
              >
                {{ detailProduct.description }}
              </p>
              <p
                v-else
                class="text-body-2 text-medium-emphasis mb-0"
              >
                Sin descripción cargada.
              </p>
            </div>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium">Precio venta final:</span> {{ formatMoney(detailProduct.price) }}
            </p>
            <p
              v-if="detailProduct.wholesale_price"
              class="text-body-2 mb-2"
            >
              <span class="font-weight-medium">Precio venta empresa:</span> {{ formatMoney(detailProduct.wholesale_price) }}
            </p>
            <p
              v-if="detailProduct.cost_price"
              class="text-body-2 mb-2"
            >
              <span class="font-weight-medium">Precio de compra:</span> {{ formatMoney(detailProduct.cost_price) }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium">Descuento:</span> {{ detailProduct.discount_percent }}%
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium">Stock:</span> {{ detailProduct.stock }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium">Umbral (almacén):</span> {{ detailProduct.umbral ?? 0 }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium">Garantía:</span> {{ detailProduct.warranty_days }} días
            </p>
            <div class="text-body-2 mb-2 d-flex align-center flex-wrap gap-2">
              <span>
                <span class="font-weight-medium">Código de barras:</span> {{ detailProduct.barcode || '—' }}
              </span>
              <VBtn
                v-if="detailProduct.barcode"
                size="small"
                variant="tonal"
                prepend-icon="ri-printer-line"
                class="d-print-none"
                @click="openBarcodePrint(detailProduct)"
              >
                Imprimir etiqueta
              </VBtn>
            </div>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium">Gift card:</span> {{ detailProduct.is_gift_card ? 'Sí' : 'No' }}
            </p>
            <p class="text-body-2 mb-2">
              <span class="font-weight-medium">Categoría:</span> {{ detailProduct.category?.title ?? '—' }}
            </p>
            <div
              v-if="detailProduct.warehouse_lines?.length"
              class="mb-3"
            >
              <p class="text-body-2 font-weight-medium mb-1">
                Almacenes y precios
              </p>
              <VTable
                density="compact"
                class="border rounded"
              >
                <thead>
                  <tr>
                    <th class="text-start text-caption">
                      Almacén
                    </th>
                    <th class="text-end text-caption">
                      Stock
                    </th>
                    <th class="text-end text-caption">
                      Venta alm.
                    </th>
                    <th class="text-end text-caption">
                      Compra alm.
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="wl in detailProduct.warehouse_lines"
                    :key="wl.warehouse_id"
                  >
                    <td class="text-body-2">
                      {{ wl.warehouse?.name ?? '—' }}
                    </td>
                    <td class="text-end text-body-2">
                      {{ wl.stock }}
                    </td>
                    <td class="text-end text-body-2">
                      {{ wl.sale_price != null ? formatMoney(wl.sale_price) : '—' }}
                    </td>
                    <td class="text-end text-body-2">
                      {{ wl.purchase_price != null ? formatMoney(wl.purchase_price) : '—' }}
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
            <p
              v-else
              class="text-body-2 mb-2"
            >
              <span class="font-weight-medium">Almacén:</span> {{ detailProduct.warehouse?.name ?? '—' }}
            </p>
            <p class="text-body-2 mb-0">
              <span class="font-weight-medium">Activo:</span> {{ detailProduct.is_active ? 'Sí' : 'No' }}
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

      <!-- Eliminar -->
      <VDialog
        v-model="deleteDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 440"
      >
        <VCard v-if="deleteTarget">
          <VCardItem>
            <VCardTitle>Eliminar producto</VCardTitle>
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
              @click="confirmDeleteProduct"
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

      <!-- Importar desde Excel / CSV -->
      <VDialog
        v-model="importDialogOpen"
        :width="$vuetify.display.smAndDown ? 'auto' : 560"
        scrollable
      >
        <VCard>
          <VCardItem>
            <VCardTitle class="d-flex align-center gap-2">
              <VIcon
                icon="ri-upload-2-line"
                color="primary"
              />
              Importar productos
            </VCardTitle>
            <VCardSubtitle class="text-wrap">
              <strong>Excel (.xlsx)</strong>: además de la hoja <strong>Importar</strong>, la plantilla incluye
              <strong>Categorías</strong> y <strong>Almacenes</strong> con los datos actuales del sistema
              (para copiar el título o el ID de almacén). Solo se importa la hoja Importar.
              <strong>CSV</strong> es una sola tabla (sin esas hojas de referencia); usá UTF-8.
              La <strong>primera fila</strong> del archivo a subir debe coincidir con los encabezados de la plantilla.
            </VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText class="pt-4">
            <p class="text-body-2 text-medium-emphasis mb-3">
              Descargá la plantilla vacía, completá filas desde la segunda y subí el archivo. Máximo 500 filas por archivo.
              Las filas con error se omiten; el resto se importa.
            </p>
            <div class="d-flex flex-wrap gap-2 mb-4">
              <VBtn
                size="small"
                variant="tonal"
                color="primary"
                prepend-icon="ri-file-excel-line"
                class="text-none"
                :loading="isImportTemplateBusy('xlsx')"
                :disabled="importTemplateBusy != null || importBusy"
                @click="downloadImportTemplate('xlsx')"
              >
                Plantilla Excel
              </VBtn>
              <VBtn
                size="small"
                variant="tonal"
                prepend-icon="ri-file-text-line"
                class="text-none"
                :loading="isImportTemplateBusy('csv')"
                :disabled="importTemplateBusy != null || importBusy"
                @click="downloadImportTemplate('csv')"
              >
                Plantilla CSV
              </VBtn>
            </div>

            <p class="text-caption text-uppercase text-medium-emphasis mb-1">
              Columnas
            </p>
            <VList
              density="compact"
              class="rounded-lg border import-help-list mb-4"
              max-height="220"
            >
              <VListItem
                v-for="row in importColumnHelp"
                :key="row.title"
                class="py-1"
              >
                <VListItemTitle class="text-body-2 font-weight-medium">
                  {{ row.title }}
                </VListItemTitle>
                <VListItemSubtitle class="text-wrap opacity-100 mt-0">
                  {{ row.hint }}
                </VListItemSubtitle>
              </VListItem>
            </VList>

            <VFileInput
              v-model="importFile"
              label="Archivo a importar"
              density="comfortable"
              variant="outlined"
              hide-details="auto"
              :disabled="importBusy"
              accept=".csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
              prepend-icon="ri-attachment-2"
            />

            <VAlert
              v-if="importSummary && (importSummary.imported > 0 || importSummary.failed > 0)"
              :type="importSummary.failed ? 'warning' : 'success'"
              variant="tonal"
              density="compact"
              class="mt-4"
              rounded="lg"
            >
              Importados: {{ importSummary.imported }}.
              <span v-if="importSummary.failed">Filas con error: {{ importSummary.failed }}.</span>
            </VAlert>
            <VList
              v-if="importSummary?.errors?.length"
              density="compact"
              class="mt-2 rounded-lg border overflow-y-auto"
              style="max-height: 200px;"
            >
              <VListSubheader class="text-error">
                Errores por fila (nº de fila en el archivo)
              </VListSubheader>
              <VListItem
                v-for="(err, i) in importSummary.errors"
                :key="i"
                class="text-body-2"
              >
                Fila {{ err.row }}: {{ err.message }}
              </VListItem>
            </VList>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-4">
            <VSpacer />
            <VBtn
              variant="text"
              :disabled="importBusy"
              @click="importDialogOpen = false"
            >
              Cerrar
            </VBtn>
            <VBtn
              color="primary"
              variant="flat"
              prepend-icon="ri-upload-2-line"
              :loading="importBusy"
              :disabled="importTemplateBusy != null"
              @click="submitProductImport"
            >
              Importar
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <BarcodeLabelPrintDialog
        v-model="barcodePrintOpen"
        :product-name="barcodePrintName"
        :barcode="barcodePrintValue"
      />
    </template>
  </div>
</template>

<style scoped lang="scss">
.productos-list-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.productos-detail-desc-label {
  letter-spacing: 0.06em;
}

.productos-search-field {
  border-radius: 10px;
}

.productos-table :deep(th) {
  font-weight: 600;
  font-size: 0.75rem;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.productos-table :deep(.v-data-table__td) {
  vertical-align: middle;
  white-space: nowrap;
}

.productos-table :deep(.v-data-table__td.productos-td-name),
.productos-table :deep(.v-data-table__td.productos-td-desc) {
  white-space: normal;
}

.productos-desc-wrap {
  max-width: min(280px, 36vw);
}

.productos-desc-preview {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  line-height: 1.4;
  cursor: help;
}

.productos-thumb-avatar {
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

.productos-name-cell {
  max-width: 280px;
  white-space: normal;
}

.font-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 0.8125rem;
}

/* Columnas fijas al scroll horizontal: miniatura a la izquierda, acciones a la derecha */
.productos-table :deep(.v-data-table__th:first-child),
.productos-table :deep(.v-data-table__td:first-child) {
  position: sticky;
  left: 0;
  z-index: 2;
  background: rgb(var(--v-theme-surface));
  box-shadow: 6px 0 10px -6px rgba(0, 0, 0, 0.18);
}

.productos-table :deep(.v-data-table__th:last-child),
.productos-table :deep(.v-data-table__td:last-child) {
  position: sticky;
  right: 0;
  z-index: 2;
  background: rgb(var(--v-theme-surface));
  box-shadow: -6px 0 10px -6px rgba(0, 0, 0, 0.18);
}

.productos-table :deep(thead .v-data-table__th:first-child),
.productos-table :deep(thead .v-data-table__th:last-child) {
  z-index: 3;
}

.productos-table :deep(tbody .v-data-table__tr:hover .v-data-table__td:first-child),
.productos-table :deep(tbody .v-data-table__tr:hover .v-data-table__td:last-child) {
  background: rgb(var(--v-theme-surface));
}

.productos-pdf-menu :deep(.v-list-subheader) {
  min-block-size: auto;
  padding-block: 4px 2px;
}
</style>
