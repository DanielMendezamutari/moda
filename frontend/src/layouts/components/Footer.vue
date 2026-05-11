<template>
  <div
    class="footer-minimal pa-3 px-4 px-md-6"
    data-footer="ribersoft-moda-pos"
  >
    <div class="d-flex flex-column flex-sm-row align-center justify-space-between gap-2 gap-sm-4 text-caption text-medium-emphasis">
      <span class="text-center text-sm-start">
        © {{ year }}
        <span class="text-high-emphasis">Ribersoft</span>
      </span>

      <a
        class="footer-link text-decoration-none d-inline-flex align-center gap-1"
        :href="whatsappUrl"
        target="_blank"
        rel="noopener noreferrer"
      >
        <VIcon
          icon="ri-whatsapp-line"
          size="16"
          class="text-medium-emphasis"
        />
        <span>{{ phoneDisplay }}</span>
      </a>
    </div>
  </div>
</template>

<script setup>
const year = new Date().getFullYear()

const phoneE164 = '59167369293'
const phoneDisplay = '+591 67369293'

const whatsappUrl = `https://wa.me/${phoneE164}?text=${encodeURIComponent('Hola Ribersoft, tengo una consulta sobre Moda POS.')}`
</script>

<style scoped>
.footer-minimal {
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.footer-link {
  color: inherit;
  transition: color 0.15s ease;
}

.footer-link:hover {
  color: rgb(var(--v-theme-primary));
}
</style>
