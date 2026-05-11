<script setup>
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import { clearAccessTokenCookieSync } from '@/utils/api'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const accessToken = useCookie('accessToken')
const authStore = useAuthStore()

/**
 * Usuario autenticado (Pinia `auth` → `GET /api/auth/me`).
 */
const currentUser = computed(() => authStore.user)

const roleLabels = {
  admin: 'Administrador',
  cashier: 'Cajero',
}

const userInitials = computed(() => {
  const n = currentUser.value?.name?.trim()
  if (!n)
    return '?'

  const parts = n.split(/\s+/).filter(Boolean)
  if (parts.length >= 2)
    return `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`.toUpperCase()

  return n.slice(0, 2).toUpperCase()
})

const userRoleLabel = computed(() => {
  const roles = currentUser.value?.roles ?? []
  if (!roles.length)
    return 'Usuario'

  return roles.map(r => roleLabels[r] ?? r).join(', ')
})

function logout() {
  clearAccessTokenCookieSync()
  accessToken.value = null
  authStore.clearUser()
  router.push({ name: 'login' })
}

onMounted(() => {
  authStore.fetchMe()
})

const userProfileList = [
  { type: 'divider' },
  {
    type: 'navItem',
    icon: 'ri-user-line',
    title: 'Perfil',
    href: '#',
  },
  {
    type: 'navItem',
    icon: 'ri-settings-4-line',
    title: 'Ajustes',
    href: '#',
  },
  {
    type: 'navItem',
    icon: 'ri-file-text-line',
    title: 'Plan de facturación',
    href: '#',
    chipsProps: {
      color: 'error',
      text: '4',
      size: 'small',
    },
  },
  { type: 'divider' },
  {
    type: 'navItem',
    icon: 'ri-money-dollar-circle-line',
    title: 'Precios',
    href: '#',
  },
  {
    type: 'navItem',
    icon: 'ri-question-line',
    title: 'Ayuda',
    href: '#',
  },
]
</script>

<template>
  <VBadge
    dot
    bordered
    location="bottom right"
    offset-x="2"
    offset-y="2"
    color="success"
    class="user-profile-badge"
  >
    <VAvatar
      class="cursor-pointer"
      size="38"
      color="primary"
      variant="tonal"
    >
      <span class="text-caption font-weight-bold">{{ userInitials }}</span>

      <!-- SECTION Menu -->
      <VMenu
        activator="parent"
        width="230"
        location="bottom end"
        offset="15px"
      >
        <VList>
          <VListItem class="px-4">
            <div class="d-flex gap-x-2 align-center">
              <VAvatar
                color="primary"
                variant="tonal"
              >
                <span class="text-caption font-weight-bold">{{ userInitials }}</span>
              </VAvatar>

              <div class="min-w-0 flex-grow-1">
                <div class="text-body-2 font-weight-medium text-high-emphasis text-truncate">
                  {{ currentUser?.name ?? '…' }}
                </div>
                <div class="text-caption text-disabled text-truncate">
                  {{ currentUser?.email ?? '' }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ userRoleLabel }}
                </div>
              </div>
            </div>
          </VListItem>

          <PerfectScrollbar :options="{ wheelPropagation: false }">
            <template
              v-for="item in userProfileList"
              :key="item.title"
            >
              <VListItem
                v-if="item.type === 'navItem'"
                :href="item.href"
                class="px-4"
              >
                <template #prepend>
                  <VIcon
                    :icon="item.icon"
                    size="22"
                  />
                </template>

                <VListItemTitle>{{ item.title }}</VListItemTitle>

                <template
                  v-if="item.chipsProps"
                  #append
                >
                  <VChip
                    v-bind="item.chipsProps"
                    variant="elevated"
                  />
                </template>
              </VListItem>

              <VDivider
                v-else
                class="my-1"
              />
            </template>

            <VListItem class="px-4">
              <VBtn
                block
                color="error"
                size="small"
                append-icon="ri-logout-box-r-line"
                @click="logout"
              >
                Cerrar sesión
              </VBtn>
            </VListItem>
          </PerfectScrollbar>
        </VList>
      </VMenu>
      <!-- !SECTION -->
    </VAvatar>
  </VBadge>
</template>

<style lang="scss">
.user-profile-badge {
  &.v-badge--bordered.v-badge--dot .v-badge__badge::after {
    color: rgb(var(--v-theme-background));
  }
}
</style>
