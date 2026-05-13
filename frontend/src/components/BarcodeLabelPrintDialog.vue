<script setup>
import JsBarcode from 'jsbarcode'
import { computed, nextTick, ref, watch } from 'vue'

/**
 * Formatos Brother QL (cinta 62 mm). @page = ancho × avance.
 * 62×20 mm: ajuste típico QL-800; barras calibradas para llenar el ancho útil.
 */
const LABEL_PRESETS = [
  {
    id: '62x20',
    title: '62 × 20 mm',
    pageW: 62,
    pageH: 20,
    sheetPaddingMm: 0.35,
    jsb: {
      width: 1.22,
      height: 24,
      fontSize: 5.5,
      margin: 0,
      textMargin: 1,
    },
    titlePt: 7,
  },
  {
    id: '62x29',
    title: '62 × 29 mm',
    pageW: 62,
    pageH: 29,
    sheetPaddingMm: 0.8,
    jsb: { width: 1.45, height: 22, fontSize: 7, margin: 1, textMargin: 0 },
    titlePt: 6,
  },
  {
    id: '62x38',
    title: '62 × 38 mm',
    pageW: 62,
    pageH: 38,
    sheetPaddingMm: 1,
    jsb: { width: 1.75, height: 30, fontSize: 8, margin: 2, textMargin: 0 },
    titlePt: 6.5,
  },
  {
    id: '62x62',
    title: '62 × 62 mm (cuadrada)',
    pageW: 62,
    pageH: 62,
    sheetPaddingMm: 1,
    jsb: { width: 2.35, height: 44, fontSize: 10, margin: 3, textMargin: 0 },
    titlePt: 8,
  },
  {
    id: '62x100',
    title: '62 × 100 mm (larga)',
    pageW: 62,
    pageH: 100,
    sheetPaddingMm: 1,
    jsb: { width: 2.8, height: 72, fontSize: 13, margin: 6, textMargin: 0 },
    titlePt: 10,
  },
]

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  productName: { type: String, default: '' },
  barcode: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const copies = ref(1)
const labelPreset = ref('62x20')
const errorMsg = ref('')
const idBase = `bc-${Math.random().toString(36).slice(2, 10)}`

const labelPresetItems = LABEL_PRESETS.map(p => ({
  title: p.title,
  value: p.id,
}))

const activePreset = computed(() => {
  return LABEL_PRESETS.find(p => p.id === labelPreset.value) ?? LABEL_PRESETS[0]
})

/** Texto del título (trim); la impresora térmica necesita texto real en DOM, no solo espacios. */
const labelTitleText = computed(() => String(props.productName || '').trim())

const copyCount = computed(() => {
  const n = Number(copies.value)
  if (!Number.isFinite(n))
    return 1

  return Math.min(50, Math.max(1, Math.floor(n)))
})

function barcodeFormat(value) {
  const v = String(value || '').trim()
  if (/^\d{13}$/.test(v))
    return 'EAN13'
  if (/^\d{8}$/.test(v))
    return 'EAN8'
  if (/^\d{12}$/.test(v))
    return 'UPC'

  return 'CODE128'
}

function drawSvg(svgEl, value) {
  if (!svgEl || !value)
    return

  const p = activePreset.value
  const opts = {
    width: p.jsb.width,
    height: p.jsb.height,
    displayValue: true,
    fontSize: p.jsb.fontSize,
    margin: p.jsb.margin,
    textMargin: p.jsb.textMargin ?? 2,
  }
  svgEl.innerHTML = ''
  const fmt = barcodeFormat(value)
  try {
    JsBarcode(svgEl, value, { ...opts, format: fmt })
  }
  catch {
    try {
      JsBarcode(svgEl, value, { ...opts, format: 'CODE128' })
    }
    catch {
      errorMsg.value = 'No se pudo generar el código para impresión.'
    }
  }
}

async function redraw() {
  errorMsg.value = ''
  if (!props.modelValue)
    return

  const value = String(props.barcode || '').trim()
  await nextTick()
  if (!value) {
    errorMsg.value = 'Este producto no tiene código de barras.'

    return
  }

  for (let i = 1; i <= copyCount.value; i++) {
    const el = document.getElementById(`${idBase}-svg-${i}`)
    if (el)
      drawSvg(el, value)
  }
}

watch(
  () => [props.modelValue, props.barcode, copyCount.value, labelPreset.value],
  () => {
    if (props.modelValue)
      redraw()
  },
  { flush: 'post' },
)

watch(() => props.modelValue, open => {
  if (open)
    copies.value = 1
})

function close() {
  emit('update:modelValue', false)
}

const printRootEl = ref(null)

/**
 * Chrome (y otros) a veces no imprimen el SVG de JsBarcode si queda bajo
 * `body * { visibility: hidden }` con solo el hijo en `visible`: ancestros del diálogo
 * siguen ocultos y el raster de impresión pierde las barras.
 * Clonar la etiqueta como hijo directo de `body` evita esa cadena.
 */
function print() {
  const root = printRootEl.value
  const value = String(props.barcode || '').trim()
  if (!root || !value) {
    window.print()

    return
  }

  const clone = root.cloneNode(true)
  clone.classList.add('barcode-print-clone')

  let failSafeTimer = null
  let cleaned = false
  const cleanup = () => {
    if (cleaned)
      return
    cleaned = true
    window.removeEventListener('afterprint', onAfterPrint)
    if (failSafeTimer != null)
      window.clearTimeout(failSafeTimer)
    document.getElementById('barcode-print-page-ql')?.remove()
    clone.remove()
  }

  function onAfterPrint() {
    cleanup()
  }

  const p = activePreset.value
  const pad = p.sheetPaddingMm ?? 1
  const pageStyle = document.createElement('style')
  pageStyle.id = 'barcode-print-page-ql'
  pageStyle.textContent = `
@page {
  size: ${p.pageW}mm ${p.pageH}mm;
  margin: 0;
}
@media print {
  .barcode-print-clone .barcode-label-sheet {
    width: ${p.pageW}mm !important;
    height: ${p.pageH}mm !important;
    max-width: ${p.pageW}mm !important;
    max-height: ${p.pageH}mm !important;
    box-sizing: border-box !important;
    padding: ${pad}mm 0.5mm !important;
    margin: 0 auto !important;
    overflow: hidden !important;
    display: grid !important;
    grid-template-rows: auto minmax(0, 1fr) !important;
    align-items: start !important;
    justify-items: center !important;
    page-break-after: always !important;
    page-break-inside: avoid !important;
  }
  .barcode-print-clone .barcode-label-sheet:last-child {
    page-break-after: auto !important;
  }
  .barcode-print-clone .barcode-label-title {
    grid-row: 1 !important;
    font-size: ${p.titlePt}pt !important;
    line-height: 1.15 !important;
    margin: 0 0 0.35mm 0 !important;
    padding: 0 !important;
    max-width: 60mm !important;
    font-weight: 700 !important;
    font-family: Arial, Helvetica, 'DejaVu Sans', sans-serif !important;
    color: #000000 !important;
    opacity: 1 !important;
    -webkit-text-fill-color: #000000 !important;
    print-color-adjust: exact !important;
    -webkit-print-color-adjust: exact !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
  }
  .barcode-print-clone .barcode-svg {
    grid-row: 2 !important;
    width: 60mm !important;
    max-width: 60mm !important;
    min-height: 0 !important;
    height: auto !important;
    max-height: 100% !important;
    align-self: stretch !important;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
  }
}
`
  document.head.appendChild(pageStyle)

  document.body.appendChild(clone)
  window.addEventListener('afterprint', onAfterPrint)
  failSafeTimer = window.setTimeout(cleanup, 15000)

  window.print()
}
</script>

<template>
  <VDialog
    :model-value="modelValue"
    :width="$vuetify.display.smAndDown ? 'auto' : 440"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <VCard>
      <VCardItem class="d-print-none">
        <VCardTitle class="text-h6">
          Imprimir código de barras
        </VCardTitle>
        <template #append>
          <VBtn
            icon
            variant="text"
            @click="close"
          >
            <VIcon icon="ri-close-line" />
          </VBtn>
        </template>
      </VCardItem>
      <VDivider class="d-print-none" />
      <VCardText class="pt-4">
        <VAlert
          v-if="errorMsg"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4 d-print-none"
        >
          {{ errorMsg }}
        </VAlert>
        <div class="d-flex flex-wrap gap-3 mb-4 align-center d-print-none">
          <VSelect
            v-model="labelPreset"
            :items="labelPresetItems"
            item-title="title"
            item-value="value"
            label="Etiqueta (Brother QL)"
            hint="Por defecto 62×20 mm (QL-800). En Windows elegí la misma medida en la Brother."
            persistent-hint
            density="compact"
            hide-details="auto"
            class="flex-grow-1"
            style="min-width: 220px; max-width: 100%;"
          />
          <VTextField
            v-model.number="copies"
            label="Copias"
            type="number"
            min="1"
            max="50"
            density="compact"
            hide-details
            style="max-width: 120px;"
          />
          <VBtn
            color="primary"
            prepend-icon="ri-printer-line"
            :disabled="!!errorMsg || !(barcode || '').trim()"
            @click="print"
          >
            Imprimir
          </VBtn>
        </div>
        <div
          ref="printRootEl"
          class="barcode-print-root"
          :class="`label-preset--${labelPreset}`"
        >
          <div
            v-for="n in copyCount"
            :key="n"
            class="barcode-label-sheet"
          >
            <div
              v-if="labelTitleText"
              class="barcode-label-title"
            >
              {{ labelTitleText }}
            </div>
            <svg
              :id="`${idBase}-svg-${n}`"
              xmlns="http://www.w3.org/2000/svg"
              class="barcode-svg"
            />
          </div>
        </div>
      </VCardText>
      <VDivider class="d-print-none" />
      <VCardActions class="d-print-none">
        <VSpacer />
        <VBtn
          variant="text"
          @click="close"
        >
          Cerrar
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style lang="scss">
/* Vista previa en pantalla: proporción similar a la etiqueta física */
.barcode-print-root {
  margin-inline: auto;
}

.barcode-label-sheet {
  box-sizing: border-box;
  border: 1px dashed rgba(0, 0, 0, 0.22);
  border-radius: 4px;
  margin-inline: auto;
  background: #fff;
}

.label-preset--62x20 .barcode-label-sheet {
  width: 62mm;
  max-width: 100%;
  min-height: 20mm;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  align-items: start;
  justify-items: center;
  padding: 0.35mm 0.5mm;
}

.label-preset--62x20 .barcode-label-title {
  grid-row: 1;
  width: 100%;
  max-width: 60mm;
  font-size: 0.75rem;
  line-height: 1.2;
  font-weight: 700;
  color: #000;
  margin-block-end: 0.2rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.label-preset--62x20 .barcode-svg {
  grid-row: 2;
  width: 60mm;
  max-width: 100%;
  min-height: 0;
  max-height: 100%;
}

.label-preset--62x29 .barcode-label-sheet {
  width: 62mm;
  max-width: 100%;
  min-height: 29mm;
}

.label-preset--62x38 .barcode-label-sheet {
  width: 62mm;
  max-width: 100%;
  min-height: 38mm;
}

.label-preset--62x62 .barcode-label-sheet {
  width: 62mm;
  max-width: 100%;
  min-height: 62mm;
}

.label-preset--62x100 .barcode-label-sheet {
  width: 62mm;
  max-width: 100%;
  min-height: 100mm;
}

.barcode-label-title {
  font-size: 0.8125rem;
  font-weight: 600;
}

@media print {
  .d-print-none {
    display: none !important;
  }

  .v-overlay__scrim {
    display: none !important;
  }

  /* Ocultar toda la app y overlays; solo el clon pegado a body se imprime. */
  body > *:not(.barcode-print-clone) {
    display: none !important;
  }

  .barcode-print-clone {
    display: block !important;
    position: static !important;
    inline-size: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
  }

  .barcode-print-root {
    position: static;
    inline-size: 100%;
  }

  .barcode-label-sheet {
    text-align: center;
    border: none !important;
    border-radius: 0 !important;
    page-break-after: always;
  }

  .barcode-label-sheet:last-child {
    page-break-after: auto;
  }

  .barcode-label-title {
    font-weight: 700;
    max-inline-size: 100%;
    color: #000 !important;
    opacity: 1 !important;
    -webkit-text-fill-color: #000000 !important;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: Arial, Helvetica, 'DejaVu Sans', sans-serif !important;
  }

  .barcode-svg {
    max-inline-size: 100%;
    block-size: auto;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
  }
}
</style>
