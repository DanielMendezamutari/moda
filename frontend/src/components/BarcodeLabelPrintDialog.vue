<script setup>
import JsBarcode from 'jsbarcode'
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  productName: { type: String, default: '' },
  barcode: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const copies = ref(1)
const errorMsg = ref('')
const idBase = `bc-${Math.random().toString(36).slice(2, 10)}`

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

  const opts = {
    width: 2.2,
    height: 56,
    displayValue: true,
    fontSize: 13,
    margin: 6,
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
  () => [props.modelValue, props.barcode, copyCount.value],
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

function print() {
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
        <div class="barcode-print-root">
          <div
            v-for="n in copyCount"
            :key="n"
            class="barcode-label-sheet"
          >
            <div
              v-if="productName"
              class="barcode-label-title"
            >
              {{ productName }}
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
@media print {
  .d-print-none {
    display: none !important;
  }

  .v-overlay__scrim {
    display: none !important;
  }

  body * {
    visibility: hidden;
  }

  .barcode-print-root,
  .barcode-print-root * {
    visibility: visible;
  }

  .barcode-print-root {
    position: absolute;
    inset-block-start: 0;
    inset-inline-start: 0;
    inline-size: 100%;
  }

  .barcode-label-sheet {
    padding: 8px;
    text-align: center;
    page-break-after: always;
  }

  .barcode-label-sheet:last-child {
    page-break-after: auto;
  }

  .barcode-label-title {
    font-size: 11pt;
    font-weight: 600;
    margin-block-end: 6px;
    max-inline-size: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .barcode-svg {
    max-inline-size: 100%;
    block-size: auto;
  }
}
</style>
