<script setup>
const { injectSkinClasses } = useSkins()

// ℹ️ This will inject classes in body tag for accurate styling
injectSkinClasses()

const refLoadingIndicator = ref(null)
</script>

<template>
  <AppLoadingIndicator ref="refLoadingIndicator" />

  <div class="layout-wrapper layout-blank">
    <RouterView #="{ Component }">
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
  </div>
</template>

<style>
.layout-wrapper.layout-blank {
  flex-direction: column;
}
</style>
