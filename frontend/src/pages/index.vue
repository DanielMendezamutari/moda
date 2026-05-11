<script setup>
import { computed, onMounted, ref } from 'vue'
import { useTheme } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import { messageFromApiError } from '@/utils/apiErrorMessage'
import { $api } from '@/utils/api'

definePage({
  meta: {
    navActiveLink: 'root',
  },
})

const authStore = useAuthStore()
const theme = useTheme()

const loading = ref(true)
const loadError = ref('')
const analytics = ref(null)
const openCashSessions = ref(null)

async function loadOpenSessions() {
  try {
    const r = await $api('/cash-register-sessions/active')
    const list = Array.isArray(r?.data) ? r.data : []

    return list.length
  }
  catch {
    return null
  }
}

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12)
    return 'Buenos días'
  if (h < 19)
    return 'Buenas tardes'

  return 'Buenas noches'
})

const userName = computed(() => authStore.user?.name || 'Usuario')

const roleLabel = computed(() => {
  if (authStore.isAdmin)
    return 'Administrador'
  if (authStore.hasRole('cashier'))
    return 'Cajero'

  return 'Usuario'
})

const chartForeColor = computed(() =>
  theme.global.current.value.dark ? '#e6e1f7' : '#5d596c',
)

const chartGridColor = computed(() =>
  theme.global.current.value.dark ? 'rgba(255,255,255,0.08)' : 'rgba(47,43,61,0.06)',
)

function formatShortDate(iso) {
  if (!iso)
    return ''
  try {
    return new Intl.DateTimeFormat('es-BO', { day: '2-digit', month: 'short' }).format(new Date(iso + 'T12:00:00'))
  }
  catch {
    return iso
  }
}

function formatMoneyBs(v) {
  const n = Number(v)
  if (!Number.isFinite(n))
    return '—'

  return `${new Intl.NumberFormat('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)} Bs`
}

const periodSummary = computed(() => {
  const days = analytics.value?.sales_by_day
  if (!Array.isArray(days) || days.length === 0) {
    return { totalBs: 0, ops: 0 }
  }
  let totalBs = 0
  let ops = 0
  for (const d of days) {
    totalBs += Number(d.total_sales) || 0
    ops += Number(d.sales_count) || 0
  }

  return { totalBs, ops }
})

const salesChartSeries = computed(() => [{
  name: 'Ventas (Bs)',
  data: (analytics.value?.sales_by_day ?? []).map(d => Number(d.total_sales)),
}])

const salesChartOptions = computed(() => {
  const cats = (analytics.value?.sales_by_day ?? []).map(d => formatShortDate(d.date))

  return {
    chart: {
      type: 'area',
      fontFamily: 'inherit',
      toolbar: { show: false },
      zoom: { enabled: false },
      sparkline: { enabled: false },
    },
    stroke: { curve: 'smooth', width: 2.5 },
    fill: {
      type: 'gradient',
      gradient: {
        shadeIntensity: 0.35,
        opacityFrom: 0.55,
        opacityTo: 0.03,
        stops: [0, 92, 100],
      },
    },
    dataLabels: { enabled: false },
    colors: [theme.global.current.value.colors.primary],
    xaxis: {
      categories: cats,
      labels: { style: { colors: chartForeColor.value } },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: {
      labels: {
        style: { colors: chartForeColor.value },
        formatter: val => `${new Intl.NumberFormat('es-BO', { maximumFractionDigits: 0 }).format(val)}`,
      },
    },
    grid: {
      borderColor: chartGridColor.value,
      strokeDashArray: 4,
      padding: { top: 8, right: 8, bottom: 0, left: 12 },
    },
    tooltip: {
      theme: theme.global.current.value.dark ? 'dark' : 'light',
      y: { formatter: val => formatMoneyBs(val) },
    },
    markers: { size: 0, hover: { size: 5 } },
  }
})

const topProductsChartSeries = computed(() => [{
  name: 'Cantidad vendida',
  data: (analytics.value?.top_products ?? []).map(p => Number(p.qty_sold)),
}])

const topProductsChartOptions = computed(() => {
  const names = (analytics.value?.top_products ?? []).map(p => p.name)
  const c = theme.global.current.value.colors

  return {
    chart: {
      type: 'bar',
      fontFamily: 'inherit',
      toolbar: { show: false },
    },
    plotOptions: {
      bar: {
        horizontal: true,
        borderRadius: 6,
        barHeight: '78%',
        distributed: true,
        dataLabels: { position: 'top' },
      },
    },
    colors: [
      c.primary,
      c.info,
      c.success,
      c.warning,
      c.error,
      '#9c88ff',
      '#26c6da',
      '#66bb6a',
      '#ffa726',
      '#ec407a',
    ],
    dataLabels: {
      enabled: true,
      offsetX: 28,
      style: { fontSize: '11px', colors: [chartForeColor.value] },
      formatter: val => new Intl.NumberFormat('es-BO', { maximumFractionDigits: 2 }).format(Number(val)),
    },
    xaxis: {
      categories: names,
      labels: { style: { colors: chartForeColor.value } },
    },
    yaxis: {
      labels: {
        maxWidth: 220,
        style: { colors: chartForeColor.value, fontSize: '12px' },
      },
    },
    grid: { borderColor: chartGridColor.value, padding: { top: 0, right: 16, bottom: 0, left: 0 } },
    legend: { show: false },
    tooltip: {
      theme: theme.global.current.value.dark ? 'dark' : 'light',
      y: {
        formatter: (val, opts) => {
          const row = analytics.value?.top_products?.[opts.dataPointIndex]
          if (!row)
            return String(val)
          const rev = formatMoneyBs(row.revenue)

          return `${new Intl.NumberFormat('es-BO', { maximumFractionDigits: 4 }).format(Number(val))} u. · ${rev}`
        },
      },
    },
  }
})

async function loadDashboard() {
  loading.value = true
  loadError.value = ''

  try {
    await authStore.fetchMe(true)

    const tasks = []

    if (authStore.canPosSaleView) {
      tasks.push(
        $api('/dashboard/analytics')
          .then(r => {
            analytics.value = r?.data ?? null
          })
          .catch(e => {
            analytics.value = null
            throw e
          }),
      )
    }
    else {
      analytics.value = null
    }

    if (authStore.isAdmin || authStore.hasRole('cashier'))
      tasks.push(loadOpenSessions().then(v => { openCashSessions.value = v }))
    else
      openCashSessions.value = null

    await Promise.all(tasks)
  }
  catch (e) {
    loadError.value = messageFromApiError(e)
  }
  finally {
    loading.value = false
  }
}

onMounted(() => {
  loadDashboard()
})
</script>

<template>
  <div>
    <VCard
      class="mb-6 overflow-hidden"
      color="primary"
      variant="flat"
    >
      <VCardText class="py-8 px-6 px-sm-10">
        <div class="d-flex flex-column flex-md-row align-md-center justify-space-between gap-6">
          <div class="text-high-emphasis">
            <p class="text-body-1 text-white text-opacity-90 mb-1">
              {{ greeting }},
            </p>
            <h1 class="text-h4 text-sm-h3 font-weight-bold text-white mb-2">
              {{ userName }}
            </h1>
            <p
              class="text-body-1 text-white text-opacity-75 mb-0"
              style="max-width: 36rem;"
            >
              Resumen comercial de <strong class="text-white">Moda POS</strong>: tendencia de ventas y ranking de productos en los últimos 14 días.
            </p>
          </div>
          <div class="d-flex flex-wrap gap-2 align-md-end">
            <VChip
              color="white"
              variant="flat"
              size="large"
              class="font-weight-medium"
            >
              <VIcon
                start
                icon="ri-shield-user-line"
              />
              {{ roleLabel }}
            </VChip>
            <VChip
              v-if="openCashSessions !== null && (authStore.isAdmin || authStore.hasRole('cashier'))"
              :color="openCashSessions > 0 ? 'success' : 'warning'"
              variant="elevated"
              size="large"
              class="font-weight-medium"
            >
              <VIcon
                start
                :icon="openCashSessions > 0 ? 'ri-checkbox-circle-line' : 'ri-alarm-warning-line'"
              />
              {{ openCashSessions > 0 ? `${openCashSessions} turno(s) de caja abierto(s)` : 'Sin turno de caja abierto' }}
            </VChip>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VAlert
      v-if="loadError"
      type="error"
      variant="tonal"
      class="mb-6"
      border="start"
      prominent
    >
      {{ loadError }}
      <template #append>
        <VBtn
          variant="text"
          size="small"
          @click="loadDashboard"
        >
          Reintentar
        </VBtn>
      </template>
    </VAlert>

    <VSkeletonLoader
      v-if="loading"
      type="heading, image, image"
      class="mb-6"
    />

    <template v-else-if="authStore.canPosSaleView">
      <VRow class="mb-6">
        <VCol
          cols="12"
          md="4"
        >
          <VCard
            border
            class="h-100 pa-2"
          >
            <VCardText>
              <div class="text-caption text-medium-emphasis text-uppercase">
                Ventas (14 días)
              </div>
              <div class="text-h5 font-weight-bold mt-1">
                {{ formatMoneyBs(periodSummary.totalBs) }}
              </div>
              <div class="text-body-2 text-medium-emphasis mt-2">
                Suma de totales de ventas no anuladas en tu alcance.
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="4"
        >
          <VCard
            border
            class="h-100 pa-2"
          >
            <VCardText>
              <div class="text-caption text-medium-emphasis text-uppercase">
                Operaciones
              </div>
              <div class="text-h5 font-weight-bold mt-1">
                {{ new Intl.NumberFormat('es-BO').format(periodSummary.ops) }}
              </div>
              <div class="text-body-2 text-medium-emphasis mt-2">
                Cantidad de ventas registradas en el mismo período.
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="4"
        >
          <VCard
            border
            class="h-100 pa-2"
          >
            <VCardText>
              <div class="text-caption text-medium-emphasis text-uppercase">
                En ranking
              </div>
              <div class="text-h5 font-weight-bold mt-1">
                {{ (analytics?.top_products ?? []).length }}
              </div>
              <div class="text-body-2 text-medium-emphasis mt-2">
                Productos con movimiento en líneas de venta (top 10).
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VRow>
        <VCol
          cols="12"
          lg="7"
        >
          <VCard
            border
            class="overflow-hidden"
          >
            <VCardItem>
              <VCardTitle>Tendencia de ventas</VCardTitle>
              <VCardSubtitle>
                Total facturado por día · últimos {{ analytics?.range_days ?? 14 }} días
              </VCardSubtitle>
            </VCardItem>
            <VCardText class="pt-0">
              <div
                v-if="(analytics?.sales_by_day ?? []).every(d => Number(d.total_sales) === 0)"
                class="text-medium-emphasis text-body-2 py-12 text-center"
              >
                No hay ventas en este período. Cuando registres ventas, la curva se completará sola.
              </div>
              <VueApexCharts
                v-else
                type="area"
                height="340"
                :options="salesChartOptions"
                :series="salesChartSeries"
              />
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          lg="5"
        >
          <VCard
            border
            class="overflow-hidden h-100"
          >
            <VCardItem>
              <VCardTitle>Productos más vendidos</VCardTitle>
              <VCardSubtitle>
                Por cantidad acumulada en líneas de venta
              </VCardSubtitle>
            </VCardItem>
            <VCardText class="pt-0">
              <div
                v-if="!(analytics?.top_products ?? []).length"
                class="text-medium-emphasis text-body-2 py-12 text-center"
              >
                Aún no hay datos suficientes para un ranking.
              </div>
              <VueApexCharts
                v-else
                type="bar"
                height="420"
                :options="topProductsChartOptions"
                :series="topProductsChartSeries"
              />
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <VAlert
      v-else
      type="info"
      variant="tonal"
      border="start"
    >
      No tenés permisos para ver el resumen de ventas. Pedí acceso de <strong>ventas</strong> a un administrador.
    </VAlert>
  </div>
</template>
