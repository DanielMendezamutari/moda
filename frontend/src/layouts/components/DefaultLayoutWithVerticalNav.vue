<script setup>
import rawNavItems from '@/navigation/vertical'
import { useAuthStore } from '@/stores/auth'
import { storeToRefs } from 'pinia'
import { filterNavigation } from '@/utils/filterNavigation'
import { useConfigStore } from '@core/stores/config'
import { themeConfig } from '@themeConfig'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'
import NavBarI18n from '@core/components/I18n.vue'

// @layouts plugin
import { VerticalNavLayout } from '@layouts'

// SECTION: Loading Indicator (top bar; ya no depende de Suspense — ver RouterView abajo)
const refLoadingIndicator = ref(null)

// !SECTION
const configStore = useConfigStore()

// ℹ️ Provide animation name for vertical nav collapse icon.
const verticalNavHeaderActionAnimationName = ref(null)

watch([
  () => configStore.isVerticalNavCollapsed,
  () => configStore.isAppRTL,
], val => {
  if (configStore.isAppRTL)
    verticalNavHeaderActionAnimationName.value = val[0] ? 'rotate-back-180' : 'rotate-180'
  else
    verticalNavHeaderActionAnimationName.value = val[0] ? 'rotate-180' : 'rotate-back-180'
}, { immediate: true })

const authStore = useAuthStore()
const { canPosSaleView, canPosSaleCreate, canInventoryKardexView } = storeToRefs(authStore)

const navItems = computed(() =>
  filterNavigation(rawNavItems, {
    hasRole: role => authStore.hasRole(role),
    canPosSaleView: () => canPosSaleView.value,
    canPosSaleCreate: () => canPosSaleCreate.value,
    canInventoryKardexView: () => canInventoryKardexView.value,
  }),
)

onMounted(() => {
  authStore.fetchMe()
})
</script>

<template>
  <VerticalNavLayout :nav-items="navItems">
    <!-- 👉 navbar -->
    <template #navbar="{ toggleVerticalOverlayNavActive }">
      <div class="d-flex h-100 align-center">
        <IconBtn
          id="vertical-nav-toggle-btn"
          class="ms-n2 d-lg-none"
          @click="toggleVerticalOverlayNavActive(true)"
        >
          <VIcon icon="ri-menu-line" />
        </IconBtn>

        <NavbarThemeSwitcher />

        <VSpacer />

        <NavBarI18n
          v-if="themeConfig.app.i18n.enable && themeConfig.app.i18n.langConfig?.length"
          :languages="themeConfig.app.i18n.langConfig"
        />
        <UserProfile />
      </div>
    </template>

    <AppLoadingIndicator ref="refLoadingIndicator" />

    <!--
      Sin Suspense: con rutas lazy (`/ventas/registrar`, `/productos/registrar`) Suspense + chunk
      dejó el outlet vacío en algunos navegadores; el listado ya estaba precargado y sí se veía.
    -->
    <RouterView v-slot="{ Component }">
      <div class="layout-page-shell">
        <component
          :is="Component"
          v-if="Component"
        />
        <div
          v-else
          class="layout-route-fallback d-flex flex-column align-center justify-center ga-3 py-16 px-4"
        >
          <VProgressCircular
            indeterminate
            color="primary"
            size="48"
          />
          <span class="text-body-2 text-medium-emphasis">Cargando vista…</span>
        </div>
      </div>
    </RouterView>

    <!-- 👉 Footer -->
    <template #footer>
      <Footer />
    </template>

    <!-- 👉 Customizer -->
    <!-- <TheCustomizer /> -->
  </VerticalNavLayout>
</template>

<style lang="scss">
@keyframes rotate-180 {
  from { transform: rotate(0deg); }
  to { transform: rotate(180deg); }
}

@keyframes rotate-back-180 {
  from { transform: rotate(180deg); }
  to { transform: rotate(0deg); }
}

.layout-vertical-nav {
  .nav-header {
    .header-action {
      animation-duration: 0.35s;
      animation-fill-mode: forwards;
      animation-name: v-bind(verticalNavHeaderActionAnimationName);
      transform: rotate(0deg);
    }
  }
}
</style>
