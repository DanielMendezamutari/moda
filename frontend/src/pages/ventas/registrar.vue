<script setup>
/* eslint-disable camelcase -- payload API Laravel (snake_case). */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api, $apiRaw, blobFromOfetchRawResponse } from '@/utils/api'
import { formatBsAmount } from '@/utils/formatNumbers'

const router = useRouter()

definePage({
  meta: {
    navActiveLink: 'ventas-registrar',
  },
})

const authStore = useAuthStore()

const canCreate = computed(() => authStore.canPosSaleCreate)

const needsBranchForSale = computed(() => {
  const u = authStore.user
  if (!u || authStore.isAdmin)
    return false

  return u.branch_id == null || u.branch_id === ''
})

/**
 * Cajero: siempre exige turno de caja abierto para vender.
 * Admin sin sucursal (central): puede omitir sesión.
 * Admin con sucursal u otros con sucursal: exige sesión.
 */
const needsCashSessionForSale = computed(() => {
  const u = authStore.user
  if (!u)
    return false
  if (authStore.isAdmin && (u.branch_id == null || u.branch_id === ''))
    return false
  if (authStore.hasRole('cashier'))
    return true

  return u.branch_id != null && u.branch_id !== ''
})

const cashActiveSessions = ref([])
const cashSessionId = ref(null)
const cashSessionsLoading = ref(false)

const cashSaleBlocked = computed(() => {
  if (!needsCashSessionForSale.value)
    return false
  if (cashSessionsLoading.value)
    return true
  if (!cashActiveSessions.value.length)
    return true

  return cashSessionId.value == null
})

const posSaleBlocked = computed(() => needsBranchForSale.value || cashSaleBlocked.value)

const cashSessionSelectItems = computed(() =>
  cashActiveSessions.value.map(s => ({
    title: `${s.cash_register?.name ?? 'Caja'} · #${s.id}`,
    value: s.id,
  })),
)

const submitError = ref('')
const submitting = ref(false)

const reference = ref('')
const description = ref('')
const igv = ref('0')
const clientId = ref(null)

const clients = ref([])
const clientsLoading = ref(false)
const branches = ref([])

/** POS: entrada rápida */
const barcodeInput = ref('')
const barcodeInputEl = ref(null)
const productQuickQuery = ref('')
/** Catálogo cargado para el monitor (con imagen vía API). */
const catalogProducts = ref([])
const catalogLoading = ref(false)
/** Si el escáner devuelve varios resultados, mostramos solo esos hasta que se limpie el filtro. */
const monitorPickOverride = ref([])

const paymentsDialogOpen = ref(false)

/** Líneas del carrito (solo ítems cargados) */
const lines = ref([])

const paymentLines = ref([
  { method_payment: 'efectivo', amount: '', n_transaction: '' },
])

const snackbar = reactive({
  show: false,
  text: '',
})

const pageReady = ref(false)

/** Modal cliente rápido */
const createClientOpen = ref(false)
const createClientSubmitting = ref(false)
const createClientError = ref('')
const createForm = reactive({
  name: '',
  surname: '',
  n_document: '',
  phone: '',
  type_document: 'CI',
  type_client: 'natural',
  branch_id: null,
})

const showAdvanced = ref(false)

function lineSubtotal(line) {
  const q = Number(line.quantity) || 0
  const pu = Number(String(line.unit_price || '').replace(',', '.')) || 0
  const d = Number(String(line.discount || '').replace(',', '.')) || 0

  return Math.round((q * pu - d) * 100) / 100
}

const saleSubtotal = computed(() =>
  lines.value.reduce((acc, l) => acc + lineSubtotal(l), 0),
)

const saleIgv = computed(() => {
  const v = Number(String(igv.value || '').replace(',', '.'))

  return Number.isFinite(v) ? Math.round(v * 100) / 100 : 0
})

const saleTotal = computed(() =>
  Math.round((saleSubtotal.value + saleIgv.value) * 100) / 100,
)

const paymentsSum = computed(() => {
  let s = 0
  for (const p of paymentLines.value) {
    const a = Number(String(p.amount || '').replace(',', '.'))
    if (Number.isFinite(a) && a > 0)
      s += a
  }

  return Math.round(s * 100) / 100
})

const saleDebtPreview = computed(() =>
  Math.max(0, Math.round((saleTotal.value - paymentsSum.value) * 100) / 100),
)

const formatBs = formatBsAmount

const paymentMethodItems = [
  { title: 'Efectivo', value: 'efectivo' },
  { title: 'Transferencia', value: 'transferencia' },
  { title: 'Tarjeta', value: 'tarjeta' },
  { title: 'QR / billetera', value: 'qr' },
  { title: 'Crédito en cuenta', value: 'credito' },
  { title: 'Otro', value: 'otro' },
]

/** Bolivianos: billetes y monedas comunes para cuadrar caja. */
const BS_DENOMINATIONS = Object.freeze([200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1])

function makeEmptyCashDenomState() {
  return Object.fromEntries(BS_DENOMINATIONS.map(d => [String(d), '']))
}

const cashDenomQty = ref(makeEmptyCashDenomState())

function cashDenomLineTotal(denom) {
  const raw = cashDenomQty.value[String(denom)] ?? ''
  const q = Number(String(raw).replace(',', '.'))
  if (!Number.isFinite(q) || q <= 0)
    return 0

  return Math.round(denom * Math.floor(q) * 100) / 100
}

const cashCalcReceived = computed(() =>
  Math.round(BS_DENOMINATIONS.reduce((acc, d) => acc + cashDenomLineTotal(d), 0) * 100) / 100,
)

/** Suma de pagos que no son efectivo (tarjeta, transfer, QR, crédito). */
const nonCashPaymentsSum = computed(() => {
  let s = 0
  for (const p of paymentLines.value) {
    const m = String(p.method_payment || '').toLowerCase().trim()
    if (m === 'efectivo' || m === 'otro')
      continue
    const a = Number(String(p.amount || '').replace(',', '.'))
    if (Number.isFinite(a) && a > 0)
      s += a
  }

  return Math.round(s * 100) / 100
})

/**
 * Monto de la venta que debe cubrirse en efectivo (para vuelto al contar billetes).
 * Ej.: total 150 solo efectivo → 150; si ya cargaste 150 en la línea, sigue siendo 150 para el vuelto físico.
 */
const cashDueForPhysicalChange = computed(() =>
  Math.max(0, Math.round((saleTotal.value - nonCashPaymentsSum.value) * 100) / 100),
)

/** Vuelto = recibido − lo que debe cubrirse en efectivo (ej. 200 − 150 = 50). */
const cashCalcVuelto = computed(() => {
  const due = cashDueForPhysicalChange.value
  if (due <= 0.0001)
    return 0

  return Math.max(0, Math.round((cashCalcReceived.value - due) * 100) / 100)
})

/** Billetes que faltan para cubrir la parte en efectivo. */
const cashCalcFalta = computed(() => {
  const due = cashDueForPhysicalChange.value
  if (due <= 0.0001)
    return 0

  return Math.max(0, Math.round((due - cashCalcReceived.value) * 100) / 100)
})

function resetCashDenomCounts() {
  cashDenomQty.value = makeEmptyCashDenomState()
}

/** Deja el monto pendiente en la primera línea en efectivo (o crea una) para cuadrar el cobro. */
function applyPendingCashToCashLine() {
  const pend = saleDebtPreview.value
  if (pend <= 0.0001)
    return

  const amt = pend.toFixed(2)
  const idx = paymentLines.value.findIndex(p => String(p.method_payment || '').toLowerCase().trim() === 'efectivo')
  if (idx >= 0) {
    paymentLines.value[idx].amount = amt
  }
  else {
    paymentLines.value.push({
      method_payment: 'efectivo',
      amount: amt,
      n_transaction: '',
    })
  }
}

const saleProgress = computed(() => {
  const linesOk = lines.value.some(l =>
    l.product_id && l.warehouse_id && Number(l.quantity) > 0,
  )

  const paymentsOk = paymentsSum.value <= saleTotal.value + 0.0001

  return { linesOk, paymentsOk }
})

/** Calculadora de billetes solo si hay línea en efectivo (QR/tarjeta/transferencia → modal más limpio). */
const showCashCalculator = computed(() =>
  paymentLines.value.some(p => {
    const m = String(p.method_payment || '').toLowerCase().trim()

    return m === 'efectivo' || m === 'otro'
  }),
)

const branchItems = computed(() =>
  branches.value.map(b => ({
    title: b.code ? `${b.name} (${b.code})` : b.name,
    value: b.id,
  })),
)

const selectedClientLabel = computed(() => {
  const id = clientId.value
  if (id == null)
    return null
  const c = clients.value.find(x => Number(x.id) === Number(id))

  return c?.full_name || null
})

/** Cliente seleccionado (para crédito en cuenta). */
const selectedClient = computed(() => {
  const id = clientId.value
  if (id == null)
    return null

  return clients.value.find(x => Number(x.id) === Number(id)) ?? null
})

/** Saldo, límite y cupo disponible si el cliente tiene crédito habilitado. */
const creditSummary = computed(() => {
  const c = selectedClient.value
  if (!c?.credit_enabled)
    return null

  const bal = Number(String(c.credit_balance ?? '0').replace(',', '.')) || 0
  const limRaw = c.credit_limit
  const lim = limRaw != null && limRaw !== ''
    ? Number(String(limRaw).replace(',', '.'))
    : null

  let chargeThisSale = 0
  for (const p of paymentLines.value) {
    if (String(p.method_payment || '').toLowerCase().trim() !== 'credito')
      continue
    const a = Number(String(p.amount || '').replace(',', '.'))
    if (Number.isFinite(a) && a > 0)
      chargeThisSale += a
  }
  chargeThisSale = Math.round(chargeThisSale * 100) / 100

  const balanceAfterThisSale = Math.round((bal + chargeThisSale) * 100) / 100

  let disponible = null
  if (lim != null && Number.isFinite(lim))
    disponible = Math.max(0, Math.round((lim - bal) * 100) / 100)

  return {
    balance: bal,
    limit: lim,
    disponible,
    chargeThisSale,
    balanceAfterThisSale,
  }
})

/** Suma cargada como crédito en cuenta en esta venta. */
const paymentsCreditSum = computed(() => {
  let s = 0
  for (const p of paymentLines.value) {
    if (String(p.method_payment || '').toLowerCase().trim() !== 'credito')
      continue
    const a = Number(String(p.amount || '').replace(',', '.'))
    if (Number.isFinite(a) && a > 0)
      s += a
  }

  return Math.round(s * 100) / 100
})

/** Con línea de pago a crédito con monto > 0 (no cuenta el método si el monto está vacío). */
const hasCreditoPaymentLine = computed(() => {
  for (const p of paymentLines.value) {
    const a = Number(String(p.amount || '').replace(',', '.'))
    if (!Number.isFinite(a) || a <= 0.0001)
      continue
    if (String(p.method_payment || '').toLowerCase().trim() === 'credito')
      return true
  }

  return false
})

/** Con crédito en cuenta, el buscador de cliente muestra solo quienes tienen línea (podés ampliar con el interruptor). */
const showAllClientsInPos = ref(false)

const clientsForClientPicker = computed(() => {
  let base = clients.value
  if (hasCreditoPaymentLine.value && !showAllClientsInPos.value)
    base = base.filter(c => c.credit_enabled)

  const id = clientId.value
  if (id != null) {
    const has = base.some(c => Number(c.id) === Number(id))
    if (!has) {
      const hit = clients.value.find(c => Number(c.id) === Number(id))
      if (hit)
        base = [...base, hit]
    }
  }

  return base
})

const clientItems = computed(() =>
  clientsForClientPicker.value.map(c => ({
    title: `${c.full_name} (${c.n_document || 's/doc'})${c.credit_enabled ? '' : ' · sin cuenta'}`,
    value: c.id,
  })),
)

const posCreditSaleBlocked = computed(() => {
  if (!hasCreditoPaymentLine.value)
    return false
  if (clientId.value == null)
    return true
  if (!selectedClient.value?.credit_enabled)
    return true

  return false
})

function mergeClientRowFromApi(row) {
  if (!row || row.id == null)
    return
  const id = Number(row.id)
  const idx = clients.value.findIndex(x => Number(x.id) === id)
  if (idx >= 0)
    clients.value[idx] = { ...clients.value[idx], ...row }
  else
    clients.value.push(row)
}

async function refreshSelectedClientFromApi() {
  const id = clientId.value
  if (id == null)
    return
  try {
    const res = await $api(`/clients/${Number(id)}`)
    if (res?.data)
      mergeClientRowFromApi(res.data)
  }
  catch {
    /* listado puede estar desactualizado; no bloqueamos la venta */
  }
}

watch(clientId, () => {
  void refreshSelectedClientFromApi()
})

watch(paymentsDialogOpen, (open) => {
  if (open)
    void refreshSelectedClientFromApi()
})

function fillRemainingAsCredit() {
  const rem = saleDebtPreview.value
  if (rem <= 0.0001)
    return

  if (clientId.value == null) {
    snackbar.text = 'Elegí un cliente para cargar el saldo a cuenta corriente.'
    snackbar.show = true

    return
  }

  if (!selectedClient.value?.credit_enabled) {
    snackbar.text = 'Este cliente no tiene crédito en cuenta habilitado.'
    snackbar.show = true

    return
  }

  paymentLines.value.push({
    method_payment: 'credito',
    amount: String(Number(rem.toFixed(2))),
    n_transaction: '',
  })
}

function filterWarehousesForUser(warehouseLines) {
  const bid = authStore.user?.branch_id
  const isAdmin = authStore.isAdmin
  const raw = Array.isArray(warehouseLines) ? warehouseLines : []

  return raw
    .filter(wl => Number(wl.stock) > 0 && (isAdmin || !bid || Number(wl.warehouse?.branch?.id) === Number(bid)))
    .map(wl => ({
      title: `${wl.warehouse?.name || 'Almacén'} · ${wl.stock} u.`,
      value: wl.warehouse_id,
      stock: Number(wl.stock) || 0,
    }))
}

/** Filtra el catálogo localmente por texto (nombre, SKU, código). */
const filteredCatalogProducts = computed(() => {
  const raw = catalogProducts.value
  const q = String(productQuickQuery.value || '').trim().toLowerCase()
  if (!q.length)
    return raw

  return raw.filter(p => {
    const n = String(p.name || '').toLowerCase()
    const s = String(p.sku || '').toLowerCase()
    const b = String(p.barcode || '').toLowerCase()

    return n.includes(q) || s.includes(q) || b.includes(q)
  })
})

/** Lo que muestra la grilla: override por búsqueda múltiple o catálogo filtrado. */
const monitorGridProducts = computed(() => {
  if (monitorPickOverride.value.length)
    return monitorPickOverride.value

  return filteredCatalogProducts.value
})

function onProductQuickQueryUpdate(val) {
  productQuickQuery.value = val
  monitorPickOverride.value = []
}

async function loadCatalogProducts() {
  catalogLoading.value = true
  catalogProducts.value = []
  try {
    let page = 1
    let lastPage = 1
    do {
      const res = await $api(`/products?per_page=100&page=${page}`)
      const chunk = Array.isArray(res?.data) ? res.data : []
      catalogProducts.value.push(...chunk)
      lastPage = Number(res?.last_page) || 1
      page++
    } while (page <= lastPage && page <= 25)
  }
  catch {
    catalogProducts.value = []
  }
  finally {
    catalogLoading.value = false
  }
}

/**
 * Busca por código de barras / texto y agrega o muestra grilla.
 */
async function resolveProductAndAdd(rawQuery) {
  const q = String(rawQuery || '').trim()
  if (!q)
    return

  submitError.value = ''
  try {
    const res = await $api(`/products?search=${encodeURIComponent(q)}&per_page=20`)
    const rows = Array.isArray(res?.data) ? res.data : []

    if (!rows.length) {
      snackbar.text = 'No encontramos productos con ese código o texto.'
      snackbar.show = true

      return
    }

    if (rows.length === 1) {
      await mergeOrAddProductById(rows[0].id)

      return
    }

    const qLower = q.toLowerCase()
    const byBarcode = rows.find(r => String(r.barcode || '').trim().toLowerCase() === qLower)
    if (byBarcode) {
      await mergeOrAddProductById(byBarcode.id)

      return
    }

    monitorPickOverride.value = rows
    productQuickQuery.value = q
    snackbar.text = 'Varios resultados: elegí en el monitor de productos.'
    snackbar.show = true
  }
  catch {
    submitError.value = 'No se pudo buscar el producto.'
  }
}

/** Clic en tarjeta del monitor (capa dedicada; evita que VImg coma el evento). */
async function onMonitorTileClick(p) {
  const id = p?.id
  if (id == null)
    return
  await mergeOrAddProductById(id)
}

async function fetchActiveCashSessions() {
  if (!needsCashSessionForSale.value) {
    cashActiveSessions.value = []
    cashSessionId.value = null

    return
  }
  cashSessionsLoading.value = true
  try {
    const res = await $api('/cash-register-sessions/active')
    cashActiveSessions.value = Array.isArray(res?.data) ? res.data : []
    if (cashActiveSessions.value.length === 1)
      cashSessionId.value = cashActiveSessions.value[0].id
    else if (cashActiveSessions.value.length && cashSessionId.value == null)
      cashSessionId.value = cashActiveSessions.value[0].id
  }
  catch {
    cashActiveSessions.value = []
  }
  finally {
    cashSessionsLoading.value = false
  }
}

async function mergeOrAddProductById(productId) {
  submitError.value = ''
  try {
    const res = await $api(`/products/${productId}`)
    const p = res?.data
    const whOptions = filterWarehousesForUser(p?.warehouse_lines)

    if (!whOptions.length) {
      const msg = needsBranchForSale.value
        ? 'Sin sucursal asignada no podés usar almacén.'
        : 'Sin stock en tu sucursal para este producto.'
      snackbar.text = msg
      snackbar.show = true

      return
    }

    const wid = whOptions[0].value
    const existing = lines.value.find(
      l => Number(l.product_id) === Number(p.id) && Number(l.warehouse_id) === Number(wid),
    )

    if (existing) {
      existing.quantity = Math.round(Number(existing.quantity) || 0) + 1

      return
    }

    lines.value.push({
      product_id: p.id,
      product_name: p.name,
      product_sku: p.sku,
      image_url: p.image_url ?? null,
      warehouse_id: wid,
      warehouseItems: whOptions,
      warehouseHint: '',
      quantity: 1,
      unit_price: p.price != null ? String(p.price) : '',
      discount: '0',
    })
  }
  catch {
    snackbar.text = 'No se pudo cargar el producto.'
    snackbar.show = true
  }
}

function onWarehouseChange(lineIndex) {
  const line = lines.value[lineIndex]
  if (!line)
    return
  const wid = line.warehouse_id
  const pid = line.product_id
  const other = lines.value.find((l, i) =>
    i !== lineIndex && Number(l.product_id) === Number(pid) && Number(l.warehouse_id) === Number(wid),
  )
  if (other) {
    other.quantity = Math.round(Number(other.quantity) || 0) + Math.round(Number(line.quantity) || 0)
    lines.value.splice(lineIndex, 1)
  }
}

function bumpQty(i, delta) {
  const line = lines.value[i]
  if (!line)
    return
  const n = Math.round(Number(line.quantity) || 0) + delta
  if (n < 1) {
    lines.value.splice(i, 1)

    return
  }
  line.quantity = n
}

function removeLine(i) {
  lines.value.splice(i, 1)
}

function onBarcodeEnter() {
  if (posSaleBlocked.value)
    return
  const v = String(barcodeInput.value || '').trim()
  barcodeInput.value = ''
  resolveProductAndAdd(v)
}

function addPaymentRow() {
  paymentLines.value.push({ method_payment: 'efectivo', amount: '', n_transaction: '' })
}

function removePaymentRow(i) {
  paymentLines.value.splice(i, 1)

  if (!paymentLines.value.length)
    paymentLines.value.push({ method_payment: 'efectivo', amount: '', n_transaction: '' })
}

async function fetchClients() {
  clientsLoading.value = true
  try {
    const res = await $api('/clients')

    clients.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    clients.value = []
  }
  finally {
    clientsLoading.value = false
  }
}

async function fetchBranches() {
  if (!authStore.isAdmin)
    return

  try {
    const res = await $api('/branches')

    branches.value = Array.isArray(res?.data) ? res.data : []
  }
  catch {
    branches.value = []
  }
}

function openCreateClient() {
  createClientError.value = ''
  createForm.name = ''
  createForm.surname = ''
  createForm.n_document = ''
  createForm.phone = ''
  createForm.type_document = 'CI'
  createForm.type_client = 'natural'
  createForm.branch_id = authStore.user?.branch_id ?? branches.value[0]?.id ?? null
  createClientOpen.value = true
}

function closeCreateClient() {
  createClientOpen.value = false
}

async function submitCreateClient() {
  createClientError.value = ''
  const name = createForm.name.trim()
  const doc = createForm.n_document.trim()

  if (!name || !doc) {
    createClientError.value = 'Nombre y número de documento son obligatorios.'

    return
  }

  const bid = createForm.branch_id != null ? Number(createForm.branch_id) : Number(authStore.user?.branch_id)
  if (!bid) {
    createClientError.value = 'Indicá sucursal (o pedí que te asignen una).'

    return
  }

  createClientSubmitting.value = true
  try {
    const res = await $api('/clients', {
      method: 'POST',
      body: {
        name,
        surname: createForm.surname?.trim() || null,
        phone: createForm.phone?.trim() || null,
        email: null,
        type_client: createForm.type_client,
        type_document: createForm.type_document,
        n_document: doc,
        branch_id: bid,
        is_active: true,
        birthdate: null,
        gender: null,
        ubigeo: null,
        address: null,
        credit_enabled: false,
        credit_limit: null,
      },
    })

    const newId = res?.data?.id
    await fetchClients()
    if (newId != null)
      clientId.value = newId

    snackbar.text = 'Cliente registrado.'
    snackbar.show = true
    closeCreateClient()
  }
  catch (e) {
    createClientError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para crear clientes (se requiere gestión de clientes).',
      fallback: 'No se pudo crear el cliente.',
    })
  }
  finally {
    createClientSubmitting.value = false
  }
}

function clearClient() {
  clientId.value = null
}

function buildPayload() {
  const items = []
  for (const line of lines.value) {
    if (!line.product_id || !line.warehouse_id)
      continue
    const qRaw = Number(line.quantity)
    const q = Math.round(qRaw)
    const pu = Number(String(line.unit_price || '').replace(',', '.'))
    const d = Number(String(line.discount || '').replace(',', '.')) || 0
    if (!Number.isFinite(qRaw) || qRaw <= 0 || !Number.isFinite(pu) || pu < 0)
      continue
    items.push({
      product_id: line.product_id,
      warehouse_id: line.warehouse_id,
      quantity: q,
      unit_price: pu,
      discount: d,
    })
  }

  const payments = []
  for (const p of paymentLines.value) {
    const a = Number(String(p.amount || '').replace(',', '.'))
    if (!Number.isFinite(a) || a <= 0)
      continue
    payments.push({
      method_payment: String(p.method_payment || '').trim() || 'efectivo',
      amount: a,
      n_transaction: String(p.n_transaction || '').trim() || null,
    })
  }

  const ref = String(reference.value || '').trim()
  const desc = String(description.value || '').trim()

  const payload = {
    reference: ref || null,
    description: desc || null,
    client_id: clientId.value != null ? Number(clientId.value) : null,
    igv: saleIgv.value,
    items,
    payments: payments.length ? payments : undefined,
  }

  if (needsCashSessionForSale.value && cashSessionId.value != null)
    payload.cash_register_session_id = Number(cashSessionId.value)

  return payload
}

/** Abre el PDF del ticket en una pestaña nueva (vista previa / impresión térmica). */
async function openSaleTicketPdf(saleId) {
  const id = Number(saleId)
  if (!Number.isFinite(id) || id <= 0)
    return

  try {
    const raw = await $apiRaw(`/sales/${id}/ticket`, { responseType: 'blob' })
    const blob = blobFromOfetchRawResponse(raw)
    if (!blob || blob.size === 0)
      return

    const url = URL.createObjectURL(blob)
    const win = window.open(url, '_blank', 'noopener,noreferrer')
    if (!win) {
      snackbar.text = 'Permití ventanas emergentes para ver el ticket de la venta.'
      snackbar.show = true
    }
    setTimeout(() => URL.revokeObjectURL(url), 90_000)
  }
  catch {
    snackbar.text = 'Venta registrada, pero no se pudo abrir el PDF del ticket.'
    snackbar.show = true
  }
}

function resetSaleForm() {
  submitError.value = ''
  reference.value = ''
  description.value = ''
  igv.value = '0'
  clientId.value = null
  lines.value = []
  monitorPickOverride.value = []
  productQuickQuery.value = ''
  barcodeInput.value = ''
  paymentLines.value = [{ method_payment: 'efectivo', amount: '', n_transaction: '' }]
  paymentsDialogOpen.value = false
  resetCashDenomCounts()
}

async function submitSale() {
  submitError.value = ''

  if (needsBranchForSale.value) {
    submitError.value = 'Necesitás sucursal asignada para vender desde almacén.'

    return
  }

  if (needsCashSessionForSale.value) {
    if (!cashActiveSessions.value.length) {
      submitError.value = 'No hay turno de caja abierto. Abrí uno en el menú Caja → Apertura / turnos.'

      return
    }
    if (cashSessionId.value == null) {
      submitError.value = 'Seleccioná el turno de caja activo.'

      return
    }
  }

  for (const line of lines.value) {
    if (!line.product_id || !line.warehouse_id)
      continue
    const qRaw = Number(line.quantity)
    const qInt = Math.round(qRaw)
    if (!Number.isFinite(qRaw) || qRaw <= 0) {
      submitError.value = 'Revisá cantidades en tu venta.'

      return
    }
    if (Math.abs(qRaw - qInt) > 1e-6) {
      submitError.value = 'La cantidad debe ser entera (unidades).'

      return
    }
  }

  const payload = buildPayload()

  if (!payload.items.length) {
    submitError.value = 'Agregá productos con el escáner o el monitor de productos.'

    return
  }
  if (paymentsSum.value > saleTotal.value + 0.0001) {
    submitError.value = 'Los pagos no pueden superar el total.'

    return
  }

  let hasCredito = false
  for (const p of paymentLines.value) {
    const a = Number(String(p.amount || '').replace(',', '.'))
    if (!Number.isFinite(a) || a <= 0)
      continue
    if (String(p.method_payment || '').toLowerCase().trim() === 'credito') {
      hasCredito = true
      break
    }
  }

  if (hasCredito) {
    if (clientId.value == null) {
      submitError.value = 'Para usar crédito en cuenta tenés que elegir un cliente.'

      return
    }

    const c = clients.value.find(x => Number(x.id) === Number(clientId.value))
    if (!c?.credit_enabled) {
      submitError.value = 'Ese cliente no tiene crédito en cuenta habilitado.'

      return
    }

    const cs = creditSummary.value
    if (cs && cs.disponible != null && paymentsCreditSum.value > cs.disponible + 0.0001) {
      submitError.value = 'El monto a crédito supera la línea disponible del cliente.'

      return
    }
  }

  submitting.value = true
  try {
    const res = await $api('/sales', { method: 'POST', body: payload })
    const newId = res?.data?.id
    if (newId != null)
      await openSaleTicketPdf(newId)

    snackbar.text = '¡Venta registrada!'
    snackbar.show = true
    await router.push({ name: 'ventas' })
  }
  catch (e) {
    submitError.value = messageFromApiError(e, {
      forbidden: 'No tenés permiso para registrar ventas.',
      fallback: 'No se pudo registrar la venta.',
    })
  }
  finally {
    submitting.value = false
  }
}

onMounted(async () => {
  try {
    await authStore.fetchMe(true)
  }
  finally {
    pageReady.value = true
  }

  if (!canCreate.value)
    return

  await fetchBranches()
  await fetchActiveCashSessions()

  try {
    await fetchClients()
  }
  catch {
    /* vacío */
  }

  try {
    await loadCatalogProducts()
  }
  catch {
    /* vacío */
  }
})
</script>

<template>
  <div class="ventas-registrar-root">
    <div
      v-if="!pageReady || authStore.loading"
      class="d-flex justify-center align-center py-16"
    >
      <VProgressCircular
        indeterminate
        color="primary"
        size="48"
      />
    </div>

    <VCard
      v-else-if="!canCreate"
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
          Sin acceso al POS
        </h2>
        <p
          class="text-body-1 text-medium-emphasis mb-8 mx-auto"
          style="max-width: 420px;"
        >
          Tu cuenta no tiene permiso para registrar ventas.
        </p>
        <VBtn
          color="primary"
          prepend-icon="ri-arrow-left-line"
          :to="{ name: 'ventas' }"
        >
          Ir al listado
        </VBtn>
      </VCardText>
    </VCard>

    <template v-else>
      <div class="pos-venta-wrap">
        <!-- Cliente + turno de caja (misma fila) -->
        <VCard
          variant="outlined"
          class="mb-4"
        >
          <VCardText class="py-3">
            <VRow
              dense
              align="center"
              class="gy-3"
            >
              <VCol
                cols="12"
                :md="needsCashSessionForSale && cashActiveSessions.length ? 7 : 12"
              >
                <div class="d-flex flex-wrap align-center gap-2 gap-md-3">
                  <VIcon
                    icon="ri-user-heart-line"
                    color="primary"
                    size="22"
                    class="flex-shrink-0"
                  />
                  <VAutocomplete
                    v-model="clientId"
                    :items="clientItems"
                    :loading="clientsLoading"
                    label="Cliente"
                    placeholder="Mostrador — buscar…"
                    clearable
                    hide-details
                    density="compact"
                    variant="solo-filled"
                    flat
                    class="pos-client-field flex-grow-1"
                    style="max-width: 420px;"
                    :disabled="submitting"
                    prepend-inner-icon="ri-search-line"
                  />
                  <VChip
                    v-if="selectedClientLabel"
                    closable
                    color="primary"
                    variant="tonal"
                    @click:close="clearClient"
                  >
                    {{ selectedClientLabel }}
                  </VChip>
                  <VBtn
                    size="small"
                    variant="text"
                    color="secondary"
                    @click="clearClient"
                  >
                    Solo mostrador
                  </VBtn>
                  <VBtn
                    color="primary"
                    variant="tonal"
                    size="small"
                    prepend-icon="ri-user-add-line"
                    :disabled="submitting"
                    @click="openCreateClient"
                  >
                    Nuevo cliente
                  </VBtn>
                  <VSwitch
                    v-if="hasCreditoPaymentLine"
                    v-model="showAllClientsInPos"
                    hide-details
                    density="compact"
                    color="primary"
                    class="ms-1 flex-shrink-0"
                    label="Ver todos"
                  />
                </div>
                <p
                  v-if="hasCreditoPaymentLine"
                  class="text-caption text-medium-emphasis mt-1 mb-0"
                >
                  Con crédito en cuenta solo se listan clientes con línea habilitada. Activá «Ver todos» si necesitás buscar otro y asignar crédito después.
                </p>
              </VCol>
              <VCol
                v-if="needsCashSessionForSale && cashActiveSessions.length"
                cols="12"
                md="5"
                class="pos-registrar-caja-col"
              >
                <div class="d-flex flex-wrap align-center gap-2 gap-md-3">
                  <VIcon
                    icon="ri-safe-line"
                    color="primary"
                    size="22"
                    class="flex-shrink-0"
                  />
                  <VSelect
                    v-model="cashSessionId"
                    :items="cashSessionSelectItems"
                    label="Turno de caja activo"
                    item-title="title"
                    item-value="value"
                    density="compact"
                    hide-details
                    variant="outlined"
                    class="flex-grow-1 pos-cash-session-field"
                    style="max-width: 420px;"
                  />
                  <VBtn
                    size="small"
                    variant="text"
                    color="primary"
                    :to="{ name: 'caja-sesiones' }"
                  >
                    Gestionar caja
                  </VBtn>
                </div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <VAlert
          v-if="posCreditSaleBlocked"
          type="warning"
          variant="tonal"
          density="comfortable"
          class="mb-4"
          rounded="lg"
          border="start"
        >
          Hay pago a <strong>crédito en cuenta</strong>: elegí un cliente con cuenta corriente habilitada antes de cobrar.
        </VAlert>

        <VAlert
          v-if="submitError"
          type="error"
          variant="tonal"
          density="comfortable"
          class="mb-4"
          rounded="lg"
          prominent
        >
          {{ submitError }}
        </VAlert>

        <VAlert
          v-if="needsBranchForSale"
          type="warning"
          variant="tonal"
          class="mb-4"
          rounded="lg"
          border="start"
        >
          Falta sucursal en tu usuario. Pedilo en <strong>Usuarios</strong>.
        </VAlert>

        <VAlert
          v-if="needsCashSessionForSale && !cashSessionsLoading && !cashActiveSessions.length"
          type="warning"
          variant="tonal"
          class="mb-4"
          rounded="lg"
          border="start"
        >
          No hay turno de caja abierto.
          <RouterLink class="text-primary font-weight-medium ms-1" :to="{ name: 'caja-sesiones' }">
            Abrí un turno en Caja
          </RouterLink>
        </VAlert>

        <VForm
          id="venta-registrar-form"
          class="pos-form"
          @submit.prevent="submitSale"
        >
          <VRow class="pos-main-row flex-lg-nowrap">
            <!-- Izquierda: escáner + monitor productos + carrito -->
            <VCol
              cols="12"
              lg="8"
              class="pos-col-shop"
            >
              <VCard
                variant="outlined"
                class="mb-3"
              >
                <VCardText class="pa-3 pa-md-4">
                  <VRow dense>
                    <VCol
                      cols="12"
                      md="5"
                    >
                      <div class="d-flex align-center gap-2 mb-2 flex-wrap">
                        <VIcon
                          icon="ri-barcode-box-line"
                          color="primary"
                          size="22"
                        />
                        <span class="text-subtitle-2 font-weight-bold text-high-emphasis">Código de barras</span>
                        <VChip
                          size="x-small"
                          color="primary"
                          variant="tonal"
                        >
                          Enter
                        </VChip>
                      </div>
                      <VTextField
                        ref="barcodeInputEl"
                        v-model="barcodeInput"
                        placeholder="Escaneá o escribí el código…"
                        variant="solo-filled"
                        rounded="lg"
                        hide-details
                        density="comfortable"
                        class="pos-barcode-field"
                        :disabled="submitting || posSaleBlocked"
                        prepend-inner-icon="ri-barcode-fill"
                        autocomplete="off"
                        @keyup.enter="onBarcodeEnter"
                      />
                      <p class="text-caption text-medium-emphasis mt-2 mb-0">
                        Lector USB: foco aquí; Enter suma a la venta.
                      </p>
                    </VCol>
                    <VCol
                      cols="12"
                      md="7"
                    >
                      <VDivider class="my-3 d-md-none" />
                      <div class="d-flex align-center gap-2 mb-2">
                        <VIcon
                          color="primary"
                          icon="ri-search-eye-line"
                          size="22"
                        />
                        <span class="text-subtitle-2 font-weight-bold text-high-emphasis">Buscar en catálogo</span>
                      </div>
                      <VTextField
                        :model-value="productQuickQuery"
                        placeholder="Nombre, SKU, código… (filtra el monitor)"
                        variant="outlined"
                        density="compact"
                        hide-details
                        clearable
                        prepend-inner-icon="ri-search-line"
                        :disabled="submitting || posSaleBlocked"
                        @update:model-value="onProductQuickQueryUpdate"
                      />
                      <p class="text-caption text-medium-emphasis mt-2 mb-0">
                        Filtra los productos del monitor sin recargar.
                      </p>
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>

              <!-- Monitor = productos disponibles para vender -->
              <VCard
                variant="outlined"
                class="pos-monitor pos-monitor-products mb-3"
              >
                <VCardText class="pa-3">
                <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
                  <div class="d-flex align-center gap-2">
                    <VIcon
                      icon="ri-store-2-line"
                      color="primary"
                      size="24"
                    />
                    <div>
                      <div class="text-subtitle-1 font-weight-bold leading-tight text-high-emphasis">
                        Monitor de productos
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Catálogo · tocá una tarjeta para sumar a la venta
                      </div>
                    </div>
                  </div>
                  <VChip
                    v-if="monitorGridProducts.length"
                    color="primary"
                    variant="tonal"
                    size="small"
                  >
                    {{ monitorGridProducts.length }} mostrados
                  </VChip>
                </div>

                <div
                  v-if="catalogLoading"
                  class="d-flex justify-center align-center py-10"
                >
                  <VProgressCircular
                    indeterminate
                    color="primary"
                    size="40"
                  />
                </div>

                <VSheet
                  v-else-if="!catalogProducts.length"
                  rounded="lg"
                  border="dashed"
                  class="pa-6 text-center bg-surface"
                >
                  <VIcon
                    icon="ri-store-off-line"
                    size="40"
                    class="mb-2 text-medium-emphasis"
                  />
                  <div class="text-body-2 text-medium-emphasis">
                    No se cargaron productos.
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    Revisá permisos y que existan productos activos.
                  </div>
                </VSheet>

                <VSheet
                  v-else-if="!monitorGridProducts.length"
                  rounded="lg"
                  border="dashed"
                  class="pa-6 text-center bg-surface"
                >
                  <VIcon
                    icon="ri-filter-off-line"
                    size="40"
                    class="mb-2 text-medium-emphasis"
                  />
                  <div class="text-body-2 text-medium-emphasis">
                    Ningún producto coincide con el filtro.
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    Limpiá el campo «Buscar en catálogo» o el resultado del escáner.
                  </div>
                </VSheet>

                <div
                  v-else
                  class="pos-monitor-grid"
                >
                  <VRow dense>
                    <VCol
                      v-for="p in monitorGridProducts"
                      :key="p.id"
                      cols="6"
                      sm="6"
                      md="4"
                      lg="3"
                    >
                      <VSheet
                        rounded="lg"
                        border
                        class="pos-product-tile position-relative h-100 bg-surface d-flex flex-column overflow-hidden"
                      >
                        <div class="pos-product-thumb flex-shrink-0">
                          <VImg
                            v-if="p.image_url"
                            :src="p.image_url"
                            cover
                            aspect-ratio="1"
                            class="bg-surface-variant"
                          />
                          <div
                            v-else
                            class="pos-product-thumb-placeholder d-flex align-center justify-center bg-surface-variant"
                          >
                            <VIcon
                              icon="ri-image-line"
                              size="40"
                              class="text-medium-emphasis"
                            />
                          </div>
                        </div>
                        <div class="pa-3 flex-grow-1 d-flex flex-column">
                          <div class="text-caption text-medium-emphasis text-truncate">
                            {{ p.sku }}
                          </div>
                          <div class="text-body-2 font-weight-bold text-truncate mb-1">
                            {{ p.name }}
                          </div>
                          <div class="text-primary font-weight-bold mt-auto">
                            {{ formatBs(p.price) }} Bs.
                          </div>
                        </div>
                        <!-- Capa que recibe el clic en toda la tarjeta -->
                        <button
                          type="button"
                          class="pos-product-tile-hitbox"
                          tabindex="0"
                          :disabled="submitting || posSaleBlocked"
                          :aria-label="`Agregar ${p.name} a la venta`"
                          @click.stop="onMonitorTileClick(p)"
                          @keydown.enter.prevent="onMonitorTileClick(p)"
                        />
                      </VSheet>
                    </VCol>
                  </VRow>
                </div>
                </VCardText>
              </VCard>

              <!-- Carrito / líneas -->
              <VCard
                variant="outlined"
                flat
                class="pos-cart mb-3"
              >
                <VCardText class="pa-3">
                <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                  <div class="d-flex align-center gap-2">
                    <VIcon
                      icon="ri-shopping-cart-2-line"
                      color="primary"
                      size="24"
                    />
                    <div>
                      <div class="text-subtitle-1 font-weight-bold leading-tight text-high-emphasis">
                        Tu venta
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Líneas que vas a cobrar
                      </div>
                    </div>
                  </div>
                  <VChip
                    v-if="lines.length"
                    color="primary"
                    variant="tonal"
                    size="small"
                  >
                    {{ lines.length }} ítem(es)
                  </VChip>
                </div>

                <VSheet
                  v-if="!lines.length"
                  rounded="lg"
                  border="dashed"
                  class="pa-6 text-center bg-surface"
                >
                  <VIcon
                    icon="ri-inbox-line"
                    size="40"
                    class="mb-2 text-medium-emphasis"
                  />
                  <div class="text-body-2 text-medium-emphasis">
                    El carrito está vacío.
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    Escaneá, tocá el monitor de productos o buscá en catálogo.
                  </div>
                </VSheet>

                <div
                  v-else
                  class="d-flex flex-column gap-3"
                >
                  <VSheet
                    v-for="(line, idx) in lines"
                    :key="`${line.product_id}-${line.warehouse_id}-${idx}`"
                    rounded="lg"
                    border
                    class="pos-line-row pa-4"
                  >
                    <VRow align="center">
                      <VCol cols="12" md="5">
                        <div class="d-flex align-start gap-3">
                          <div class="pos-cart-line-thumb flex-shrink-0">
                            <VImg
                              v-if="line.image_url"
                              :src="line.image_url"
                              cover
                              aspect-ratio="1"
                              class="bg-surface-variant rounded-lg"
                            />
                            <div
                              v-else
                              class="pos-cart-line-thumb-placeholder d-flex align-center justify-center bg-surface-variant rounded-lg"
                            >
                              <VIcon
                                icon="ri-image-line"
                                size="28"
                                class="text-medium-emphasis"
                              />
                            </div>
                          </div>
                          <div class="pos-cart-line-info flex-grow-1">
                            <div class="text-overline text-medium-emphasis">
                              {{ line.product_sku }}
                            </div>
                            <div class="text-subtitle-1 font-weight-bold text-truncate">
                              {{ line.product_name }}
                            </div>
                          </div>
                        </div>
                      </VCol>
                      <VCol cols="12" sm="6" md="3">
                        <VSelect
                          v-model="line.warehouse_id"
                          :items="line.warehouseItems"
                          label="Almacén"
                          density="compact"
                          hide-details
                          variant="outlined"
                          :disabled="submitting || (line.warehouseItems?.length || 0) < 2"
                          @update:model-value="onWarehouseChange(idx)"
                        />
                      </VCol>
                      <VCol cols="6" sm="3" md="2">
                        <div class="text-caption text-medium-emphasis mb-1">
                          Cantidad
                        </div>
                        <div class="d-flex align-center gap-1">
                          <VBtn
                            icon
                            size="small"
                            variant="tonal"
                            :disabled="submitting"
                            @click="bumpQty(idx, -1)"
                          >
                            <VIcon icon="ri-subtract-line" />
                          </VBtn>
                          <VTextField
                            v-model.number="line.quantity"
                            type="number"
                            min="1"
                            step="1"
                            hide-details
                            density="compact"
                            variant="outlined"
                            class="pos-qty-input"
                            :disabled="submitting"
                          />
                          <VBtn
                            icon
                            size="small"
                            variant="tonal"
                            color="primary"
                            :disabled="submitting"
                            @click="bumpQty(idx, 1)"
                          >
                            <VIcon icon="ri-add-line" />
                          </VBtn>
                        </div>
                      </VCol>
                      <VCol cols="6" sm="3" md="2">
                        <VTextField
                          v-model="line.unit_price"
                          label="P. unit."
                          density="compact"
                          hide-details
                          variant="outlined"
                          :disabled="submitting"
                        />
                        <div class="text-caption text-end mt-1 font-weight-bold text-high-emphasis">
                          {{ formatBs(lineSubtotal(line)) }} Bs.
                        </div>
                      </VCol>
                    </VRow>
                    <div class="d-flex justify-end mt-2">
                      <VBtn
                        size="small"
                        variant="text"
                        color="error"
                        prepend-icon="ri-delete-bin-line"
                        :disabled="submitting"
                        @click="removeLine(idx)"
                      >
                        Quitar
                      </VBtn>
                    </div>
                  </VSheet>
                </div>
                </VCardText>
              </VCard>

              <div class="mt-4">
                <VBtn
                  variant="tonal"
                  color="secondary"
                  size="small"
                  prepend-icon="ri-settings-3-line"
                  class="mb-2"
                  @click="showAdvanced = !showAdvanced"
                >
                  {{ showAdvanced ? 'Ocultar' : 'Mostrar' }} referencia, IGV y notas
                </VBtn>
                <VExpandTransition>
                  <VSheet
                    v-if="showAdvanced"
                    border
                    rounded="lg"
                    class="pa-4"
                  >
                    <VRow dense>
                      <VCol cols="12" md="4">
                        <VTextField
                          v-model="reference"
                          label="Referencia"
                          density="comfortable"
                          :disabled="submitting"
                        />
                      </VCol>
                      <VCol cols="12" md="4">
                        <VTextField
                          v-model="igv"
                          label="IGV (Bs.)"
                          density="comfortable"
                          :disabled="submitting"
                        />
                      </VCol>
                      <VCol cols="12">
                        <VTextarea
                          v-model="description"
                          label="Notas"
                          rows="2"
                          density="comfortable"
                          :disabled="submitting"
                        />
                      </VCol>
                    </VRow>
                  </VSheet>
                </VExpandTransition>
              </div>
            </VCol>

            <!-- Cobro: resumen fijo + botón siempre visible (desktop) -->
            <VCol
              cols="12"
              lg="4"
              class="pos-col-pay"
            >
              <div class="pos-pay-sticky">
                <VCard
                  elevation="6"
                  class="pos-pay-card d-flex flex-column"
                >
                  <div class="pos-pay-scroll pa-4 pb-2">
                    <div class="d-flex align-center justify-space-between gap-2 mb-1">
                      <span class="text-overline text-medium-emphasis">Cobro</span>
                      <div class="d-flex align-center gap-1 flex-wrap justify-end">
                        <VIcon
                          :icon="saleProgress.linesOk ? 'ri-checkbox-circle-fill' : 'ri-checkbox-blank-circle-line'"
                          size="18"
                          :color="saleProgress.linesOk ? 'success' : undefined"
                          :class="saleProgress.linesOk ? undefined : 'text-medium-emphasis'"
                        />
                        <VIcon
                          :icon="saleProgress.paymentsOk ? 'ri-checkbox-circle-fill' : 'ri-alert-line'"
                          size="18"
                          :color="saleProgress.paymentsOk ? 'success' : 'warning'"
                        />
                      </div>
                    </div>
                    <div class="text-h4 font-weight-black mb-1 text-high-emphasis">
                      {{ formatBs(saleTotal) }}
                      <span class="text-h6 font-weight-medium text-medium-emphasis">Bs.</span>
                    </div>
                    <div class="pos-pay-totals-grid mb-3">
                      <div class="pos-pay-total-cell">
                        <span class="text-caption text-medium-emphasis">Subtotal</span>
                        <span class="text-body-2 font-weight-medium text-high-emphasis">{{ formatBs(saleSubtotal) }}</span>
                      </div>
                      <div class="pos-pay-total-cell">
                        <span class="text-caption text-medium-emphasis">IGV</span>
                        <span class="text-body-2 font-weight-medium text-high-emphasis">{{ formatBs(saleIgv) }}</span>
                      </div>
                      <div class="pos-pay-total-cell">
                        <span class="text-caption text-medium-emphasis">Pagos</span>
                        <span class="text-body-2 font-weight-medium text-high-emphasis">{{ formatBs(paymentsSum) }}</span>
                      </div>
                      <div class="pos-pay-total-cell pos-pay-total-cell--accent">
                        <span class="text-caption text-medium-emphasis">Saldo venta</span>
                        <span class="text-subtitle-2 font-weight-bold text-high-emphasis">{{ formatBs(saleDebtPreview) }}</span>
                      </div>
                      <div
                        v-if="paymentsCreditSum > 0"
                        class="pos-pay-total-cell pos-pay-total-cell--accent"
                      >
                        <span class="text-caption text-medium-emphasis">A cuenta (fiado)</span>
                        <span class="text-subtitle-2 font-weight-bold text-high-emphasis">{{ formatBs(paymentsCreditSum) }}</span>
                      </div>
                    </div>

                    <VSheet
                      v-if="showCashCalculator && cashDueForPhysicalChange > 0.0001"
                      border
                      rounded="lg"
                      class="pa-3 mb-3 pos-pay-vuelto-preview"
                    >
                      <div class="text-caption text-medium-emphasis mb-1">
                        Vuelto (efectivo contado)
                      </div>
                      <div class="text-h5 font-weight-bold text-high-emphasis">
                        {{ formatBs(cashCalcVuelto) }}
                        <span class="text-body-2 font-weight-medium text-medium-emphasis">Bs.</span>
                      </div>
                      <div class="text-caption text-medium-emphasis mt-2">
                        Cobrar en efectivo {{ formatBs(cashDueForPhysicalChange) }}
                        · Recibido {{ formatBs(cashCalcReceived) }}
                      </div>
                    </VSheet>

                    <VAlert
                      v-if="creditSummary"
                      type="info"
                      variant="tonal"
                      density="compact"
                      class="mb-3 text-start"
                      rounded="lg"
                    >
                      <div class="text-caption font-weight-medium mb-1">
                        Línea de crédito
                      </div>
                      <div class="text-body-2">
                        <span class="text-medium-emphasis">Saldo actual</span>
                        {{ formatBs(creditSummary.balance) }}
                        <span class="text-medium-emphasis"> · </span>
                        Límite {{ creditSummary.limit != null ? formatBs(creditSummary.limit) : '—' }}
                        <span class="text-medium-emphasis"> · </span>
                        Disponible {{ creditSummary.disponible != null ? formatBs(creditSummary.disponible) : 'sin tope' }}
                      </div>
                      <div
                        v-if="creditSummary.chargeThisSale > 0.0001"
                        class="text-body-2 font-weight-medium mt-2"
                      >
                        Con esta venta quedaría en {{ formatBs(creditSummary.balanceAfterThisSale) }} Bs.
                        <span class="text-caption text-medium-emphasis"> (fiado {{ formatBs(creditSummary.chargeThisSale) }})</span>
                      </div>
                      <VBtn
                        v-if="saleDebtPreview > 0.0001"
                        block
                        size="small"
                        variant="tonal"
                        color="primary"
                        class="mt-3"
                        prepend-icon="ri-hand-coin-line"
                        :disabled="submitting"
                        @click="fillRemainingAsCredit"
                      >
                        Fiar saldo pendiente ({{ formatBs(saleDebtPreview) }})
                      </VBtn>
                    </VAlert>

                    <div class="mb-1">
                      <div class="text-caption text-medium-emphasis mb-2">
                        Medios de pago
                      </div>
                      <div class="text-body-2 text-high-emphasis mb-3">
                        {{ paymentLines.length }} línea(s) · suman {{ formatBs(paymentsSum) }} Bs.
                      </div>
                      <VBtn
                        block
                        variant="outlined"
                        color="primary"
                        size="default"
                        prepend-icon="ri-bank-card-line"
                        :disabled="submitting"
                        @click="paymentsDialogOpen = true"
                      >
                        Editar medios de pago
                      </VBtn>
                    </div>
                  </div>

                  <div class="pos-pay-footer pa-4 pt-2">
                    <VDivider class="mb-4" />
                    <VBtn
                      type="submit"
                      block
                      size="large"
                      color="primary"
                      class="font-weight-bold mb-2"
                      :loading="submitting"
                      :disabled="posSaleBlocked || posCreditSaleBlocked"
                      prepend-icon="ri-checkbox-circle-fill"
                    >
                      Confirmar venta
                    </VBtn>
                    <VBtn
                      block
                      variant="text"
                      color="secondary"
                      size="small"
                      :disabled="submitting"
                      prepend-icon="ri-refresh-line"
                      @click="resetSaleForm"
                    >
                      Limpiar
                    </VBtn>
                  </div>
                </VCard>
              </div>
            </VCol>
          </VRow>
        </VForm>

        <VSheet
          border="t"
          rounded="0"
          class="pos-mobile-bar d-lg-none"
        >
          <div class="d-flex align-center justify-space-between gap-2 pa-3 flex-wrap">
            <VBtn
              variant="text"
              :disabled="submitting"
              @click="resetSaleForm"
            >
              Limpiar
            </VBtn>
            <VBtn
              icon
              variant="tonal"
              color="primary"
              :disabled="submitting"
              aria-label="Medios de pago"
              @click="paymentsDialogOpen = true"
            >
              <VIcon icon="ri-bank-card-line" />
            </VBtn>
            <div class="text-end">
              <div class="text-caption text-medium-emphasis">
                Total
              </div>
              <div class="text-h6 font-weight-bold text-primary">
                {{ formatBs(saleTotal) }} Bs.
              </div>
            </div>
            <VBtn
              type="submit"
              color="primary"
              size="large"
              form="venta-registrar-form"
              :loading="submitting"
              :disabled="posSaleBlocked || posCreditSaleBlocked"
              prepend-icon="ri-checkbox-circle-fill"
            >
              Cobrar
            </VBtn>
          </div>
        </VSheet>

        <VSnackbar
          v-model="snackbar.show"
          location="bottom"
          color="primary"
          :timeout="2600"
        >
          {{ snackbar.text }}
        </VSnackbar>

        <VDialog
          v-model="paymentsDialogOpen"
          max-width="720"
          scrollable
          scrim-class="pos-payments-dialog-scrim"
          class="pos-payments-dialog"
        >
          <VCard
            rounded="lg"
            elevation="0"
            variant="flat"
            class="pos-payments-dialog-card"
          >
            <VCardItem class="pt-5 pb-2">
              <VCardTitle class="text-h6 font-weight-bold">
                Medios de pago
              </VCardTitle>
              <VCardSubtitle class="text-body-2">
                <span v-if="hasCreditoPaymentLine && !showCashCalculator">Crédito en cuenta: montos sin contador de billetes (solo medios digitales / fiado).</span>
                <span v-else>Efectivo, QR, tarjeta, transferencia o crédito en cuenta.</span>
              </VCardSubtitle>
              <template #append>
                <VBtn
                  icon
                  variant="text"
                  @click="paymentsDialogOpen = false"
                >
                  <VIcon icon="ri-close-line" />
                </VBtn>
              </template>
            </VCardItem>
            <VDivider />
            <VCardText class="pt-5">
              <VAlert
                v-if="hasCreditoPaymentLine"
                type="info"
                variant="tonal"
                density="compact"
                class="mb-4"
                rounded="lg"
              >
                <span v-if="!clientId">Elegí un cliente con cuenta corriente en la barra superior.</span>
                <span v-else-if="!selectedClient?.credit_enabled">Este cliente no tiene fiado habilitado: cambiá de cliente o quitá la línea «Crédito en cuenta».</span>
                <span v-else>El monto en «Crédito en cuenta» se suma al saldo del cliente al confirmar la venta.</span>
              </VAlert>
              <p class="text-body-2 mb-4">
                <span class="text-medium-emphasis">Total venta:</span>
                <span class="font-weight-bold text-high-emphasis ms-1">{{ formatBs(saleTotal) }} Bs.</span>
                <span class="text-medium-emphasis ms-3">Pendiente:</span>
                <span class="font-weight-bold text-high-emphasis ms-1">{{ formatBs(saleDebtPreview) }} Bs.</span>
              </p>

              <VSheet
                v-if="showCashCalculator"
                rounded="lg"
                border
                class="pa-3 pa-sm-4 mb-5 pos-cash-calc-shell"
              >
                <div class="text-caption text-medium-emphasis mb-3">
                  <span class="font-weight-medium text-high-emphasis">Efectivo —</span>
                  contá lo que te dio el cliente.
                  <span class="d-block mt-1">
                    A cobrar en efectivo: <strong>{{ formatBs(cashDueForPhysicalChange) }}</strong> Bs.
                    (total venta menos tarjeta/transfer/QR/crédito).
                  </span>
                </div>

                <div class="pos-cash-calc-table mb-3">
                  <div
                    v-for="d in BS_DENOMINATIONS"
                    :key="'den-' + d"
                    class="pos-cash-calc-row d-flex align-center flex-wrap gap-2 py-1"
                  >
                    <div class="text-body-2 pos-cash-calc-denom">
                      {{ formatBs(d) }}
                    </div>
                    <VTextField
                      v-model="cashDenomQty[String(d)]"
                      label="Cant."
                      density="compact"
                      hide-details
                      variant="outlined"
                      type="text"
                      inputmode="numeric"
                      class="pos-cash-calc-qty"
                      :disabled="submitting"
                    />
                    <div class="text-caption text-medium-emphasis ms-auto tabular-nums">
                      {{ formatBs(cashDenomLineTotal(d)) }}
                    </div>
                  </div>
                </div>

                <div class="pos-cash-calc-summary mb-4">
                  <div class="pos-cash-calc-sum-line">
                    <span>Recibido (conteo)</span>
                    <span class="font-weight-bold tabular-nums">{{ formatBs(cashCalcReceived) }}</span>
                  </div>
                  <div class="pos-cash-calc-sum-line pos-cash-calc-vuelto-line">
                    <span>Vuelto a entregar</span>
                    <span class="tabular-nums">{{ formatBs(cashCalcVuelto) }}</span>
                  </div>
                  <div class="pos-cash-calc-sum-line">
                    <span>Falta para cubrir</span>
                    <span class="font-weight-medium tabular-nums">{{ formatBs(cashCalcFalta) }}</span>
                  </div>
                </div>

                <div class="d-flex flex-column flex-sm-row gap-2 flex-wrap">
                  <VBtn
                    color="primary"
                    variant="outlined"
                    prepend-icon="ri-cash-line"
                    :disabled="submitting || saleDebtPreview <= 0.0001"
                    @click="applyPendingCashToCashLine"
                  >
                    Registrar efectivo {{ formatBs(saleDebtPreview) }}
                  </VBtn>
                  <VBtn
                    variant="text"
                    color="secondary"
                    size="small"
                    prepend-icon="ri-restart-line"
                    :disabled="submitting"
                    @click="resetCashDenomCounts"
                  >
                    Limpiar
                  </VBtn>
                </div>
              </VSheet>

              <div
                v-for="(p, pidx) in paymentLines"
                :key="'paydlg-' + pidx"
                class="mb-5"
              >
                <VSheet
                  rounded="lg"
                  border
                  class="pa-4 pos-pay-line-sheet"
                >
                  <div class="text-caption text-medium-emphasis mb-3">
                    Pago {{ pidx + 1 }}
                  </div>
                  <VSelect
                    v-model="p.method_payment"
                    :items="paymentMethodItems"
                    item-title="title"
                    item-value="value"
                    label="Medio de pago"
                    density="comfortable"
                    hide-details
                    variant="outlined"
                    class="mb-3"
                    :disabled="submitting"
                  />
                  <VRow dense>
                    <VCol
                      cols="12"
                      sm="6"
                    >
                      <VTextField
                        v-model="p.amount"
                        label="Monto (Bs.)"
                        density="comfortable"
                        hide-details
                        variant="outlined"
                        type="text"
                        inputmode="decimal"
                        :disabled="submitting"
                      />
                    </VCol>
                    <VCol
                      v-if="String(p.method_payment || '').toLowerCase().trim() !== 'credito'"
                      cols="12"
                      sm="6"
                    >
                      <VTextField
                        v-model="p.n_transaction"
                        label="Referencia / Nº operación"
                        density="comfortable"
                        hide-details
                        variant="outlined"
                        :disabled="submitting"
                      />
                    </VCol>
                  </VRow>
                  <div
                    v-if="paymentLines.length > 1"
                    class="text-end mt-2"
                  >
                    <VBtn
                      size="small"
                      variant="text"
                      color="error"
                      prepend-icon="ri-delete-bin-line"
                      @click="removePaymentRow(pidx)"
                    >
                      Quitar esta línea
                    </VBtn>
                  </div>
                </VSheet>
              </div>

              <VBtn
                block
                variant="text"
                color="primary"
                size="default"
                prepend-icon="ri-add-line"
                class="mb-2"
                :disabled="submitting"
                @click="addPaymentRow"
              >
                Agregar medio de pago
              </VBtn>
            </VCardText>
            <VDivider />
            <VCardActions class="pa-4 justify-end">
              <VBtn
                color="primary"
                size="large"
                variant="flat"
                prepend-icon="ri-check-line"
                @click="paymentsDialogOpen = false"
              >
                Listo
              </VBtn>
            </VCardActions>
          </VCard>
        </VDialog>

        <VDialog
          v-model="createClientOpen"
          max-width="480"
          scrollable
          @after-leave="createClientError = ''"
        >
          <VCard rounded="xl">
            <VCardItem>
              <VCardTitle>Cliente nuevo</VCardTitle>
              <VCardSubtitle>Datos mínimos para facturar después.</VCardSubtitle>
              <template #append>
                <VBtn
                  icon
                  variant="text"
                  @click="closeCreateClient"
                >
                  <VIcon icon="ri-close-line" />
                </VBtn>
              </template>
            </VCardItem>
            <VDivider />
            <VCardText class="pt-4">
              <VAlert
                v-if="createClientError"
                type="error"
                variant="tonal"
                density="compact"
                class="mb-4"
              >
                {{ createClientError }}
              </VAlert>
              <VTextField
                v-model="createForm.name"
                label="Nombre *"
                density="comfortable"
                class="mb-3"
                :disabled="createClientSubmitting"
              />
              <VTextField
                v-model="createForm.surname"
                label="Apellidos"
                density="comfortable"
                class="mb-3"
                :disabled="createClientSubmitting"
              />
              <VRow dense>
                <VCol cols="6">
                  <VSelect
                    v-model="createForm.type_document"
                    :items="[{ title: 'CI', value: 'CI' }, { title: 'NIT', value: 'NIT' }]"
                    label="Doc."
                    density="comfortable"
                    :disabled="createClientSubmitting"
                  />
                </VCol>
                <VCol cols="6">
                  <VTextField
                    v-model="createForm.n_document"
                    label="Nº documento *"
                    density="comfortable"
                    :disabled="createClientSubmitting"
                  />
                </VCol>
              </VRow>
              <VTextField
                v-model="createForm.phone"
                label="Teléfono"
                density="comfortable"
                class="mb-3 mt-2"
                :disabled="createClientSubmitting"
              />
              <VSelect
                v-if="authStore.isAdmin && branchItems.length"
                v-model="createForm.branch_id"
                :items="branchItems"
                label="Sucursal *"
                density="comfortable"
                class="mb-4"
                :disabled="createClientSubmitting"
              />
              <VBtn
                block
                color="primary"
                size="large"
                :loading="createClientSubmitting"
                prepend-icon="ri-save-3-line"
                @click="submitCreateClient"
              >
                Guardar y usar
              </VBtn>
            </VCardText>
          </VCard>
        </VDialog>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.ventas-registrar-root {
  inline-size: 100%;
  min-block-size: 12rem;
}

.pos-venta-wrap {
  padding-block-end: 5.5rem;

  @media (min-width: 1280px) {
    padding-block-end: 1rem;
  }
}

.pos-main-row {
  @media (min-width: 1280px) {
    align-items: flex-start;
  }
}

.pos-col-shop {
  @media (min-width: 1280px) {
    max-block-size: calc(100dvh - 9.5rem);
    overflow-y: auto;
    padding-inline-end: 2px;
  }
}

.pos-client-field :deep(.v-field) {
  border-radius: 12px;
}

.pos-barcode-field :deep(.v-field) {
  font-size: 1.15rem;
  letter-spacing: 0.04em;
}

.pos-product-tile {
  transition: transform 0.15s ease, box-shadow 0.15s ease;

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
  }

  &:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: 2px;
  }
}

.pos-product-tile-hitbox {
  position: absolute;
  inset: 0;
  z-index: 2;
  margin: 0;
  padding: 0;
  border: none;
  border-radius: inherit;
  cursor: pointer;
  background: transparent;
}

.cursor-pointer {
  cursor: pointer;
}

.pos-monitor-products .pos-monitor-grid {
  max-block-size: min(42vh, 22rem);
  overflow-y: auto;
  padding-inline-end: 2px;
}

.pos-line-row {
  background: rgb(var(--v-theme-surface));
  box-shadow: none;
}

.pos-qty-input {
  max-width: 72px;
}

.pos-pay-sticky {
  @media (min-width: 1280px) {
    position: sticky;
    inset-block-start: 0.75rem;
    align-self: flex-start;
    inline-size: 100%;
  }
}

.pos-pay-card {
  min-block-size: 0;

  @media (min-width: 1280px) {
    max-block-size: calc(100dvh - 5.5rem);
  }
}

.pos-pay-scroll {
  flex: 1 1 auto;
  min-block-size: 0;
  overflow-x: hidden;
  overflow-y: auto;
}

.pos-pay-footer {
  flex: 0 0 auto;
  background: rgb(var(--v-theme-surface));
}

.pos-pay-totals-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.375rem 0.5rem;
}

.pos-pay-total-cell {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  padding: 0.25rem 0;
  background: transparent;
}

.pos-pay-total-cell--accent {
  grid-column: 1 / -1;
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  padding-block-start: 0.5rem;
  margin-block-start: 0.25rem;
  border-block-start: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: transparent;
}

.pos-mobile-bar {
  position: sticky;
  bottom: 0;
  z-index: 6;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 -4px 24px rgba(0, 0, 0, 0.08);
}

.pos-product-thumb {
  inline-size: 100%;
}

.pos-product-thumb-placeholder {
  aspect-ratio: 1;
  min-block-size: 112px;
}

.pos-cart-line-thumb {
  inline-size: 56px;
  block-size: 56px;
  overflow: hidden;
}

.pos-cart-line-thumb-placeholder {
  inline-size: 56px;
  block-size: 56px;
}

.pos-cart-line-info {
  min-width: 0;
}

.pos-cash-calc-denom {
  min-width: 5.5rem;
}

.pos-cash-calc-qty {
  max-width: 6.5rem;
  min-width: 5rem;
}

.pos-cash-calc-summary {
  border-block-start: thin dashed rgba(var(--v-border-color), var(--v-border-opacity));
  padding-block-start: 0.75rem;
}

.pos-cash-calc-sum-line {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 0.5rem;
  font-size: 0.8125rem;
  padding: 0.2rem 0;
}

.pos-cash-calc-vuelto-line {
  font-weight: 700;
  padding-block: 0.35rem;
}

.pos-cash-calc-vuelto-line span:last-child {
  font-size: 1.2rem;
  color: rgb(var(--v-theme-success));
}

.pos-pay-vuelto-preview {
  border-style: dashed !important;
}

/* Separador visual entre cliente y turno de caja en la misma fila (md+) */
.pos-registrar-caja-col {
  @media (min-width: 960px) {
    padding-inline-start: 1rem !important;
    border-inline-start: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  }
}
</style>
