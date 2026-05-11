<script setup>
import { nextTick, onMounted, watch } from 'vue'
import { useGenerateImageVariant } from '@/@core/composable/useGenerateImageVariant'
import authV2LoginIllustrationBorderedDark from '@images/pages/auth-v2-login-illustration-bordered-dark.png'
import authV2LoginIllustrationBorderedLight from '@images/pages/auth-v2-login-illustration-bordered-light.png'
import authV2LoginIllustrationDark from '@images/pages/auth-v2-login-illustration-dark.png'
import authV2LoginIllustrationLight from '@images/pages/auth-v2-login-illustration-light.png'
import authV2LoginMaskDark from '@images/pages/auth-v2-login-mask-dark.png'
import authV2LoginMaskLight from '@images/pages/auth-v2-login-mask-light.png'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'
import { $api } from '@/utils/api'

definePage({ meta: { layout: 'blank' } })

const router = useRouter()
const route = useRoute()

function pathAfterLogin() {
  const r = route.query.redirect
  if (typeof r === 'string' && r.startsWith('/') && !r.startsWith('//') && !r.includes('://'))
    return r

  return '/'
}

/** null = elegir | 'pin' = solo PIN entra al sistema | 'account' = solo correo + contraseña */
const flowMode = ref(null)

const pinValue = ref('')
const PIN_MIN = 4
const PIN_MAX = 16

const form = ref({
  email: '',
  password: '',
  remember: false,
})

const isPasswordVisible = ref(false)
const loading = ref(false)
const errorMessage = ref('')
const pinPanelRef = ref(null)

const authV2LoginMask = useGenerateImageVariant(authV2LoginMaskLight, authV2LoginMaskDark)
const authV2LoginIllustration = useGenerateImageVariant(authV2LoginIllustrationLight, authV2LoginIllustrationDark, authV2LoginIllustrationBorderedLight, authV2LoginIllustrationBorderedDark, true)

const accessToken = useCookie('accessToken')

const pinDotsCount = computed(() => pinValue.value.length)

const pinValid = computed(() => {
  const n = pinValue.value.length

  return n >= PIN_MIN && n <= PIN_MAX
})

watch(flowMode, async (m) => {
  await nextTick()
  if (m === 'pin')
    pinPanelRef.value?.focus?.()
})

function appendDigit(d) {
  errorMessage.value = ''
  if (pinValue.value.length >= PIN_MAX)
    return
  pinValue.value += String(d)
}

function removeDigit() {
  errorMessage.value = ''
  pinValue.value = pinValue.value.slice(0, -1)
}

function clearPin() {
  errorMessage.value = ''
  pinValue.value = ''
}

function onPinKeydown(e) {
  if (e.key >= '0' && e.key <= '9') {
    e.preventDefault()
    appendDigit(e.key)

    return
  }

  if (e.key === 'Backspace') {
    e.preventDefault()
    removeDigit()

    return
  }

  if (e.key === 'Enter') {
    e.preventDefault()
    if (pinValid.value)
      submitLoginPin()
  }
}

function startPinOnly() {
  errorMessage.value = ''
  pinValue.value = ''
  flowMode.value = 'pin'
}

function startAccountOnly() {
  errorMessage.value = ''
  flowMode.value = 'account'
}

function resetChoice() {
  errorMessage.value = ''
  flowMode.value = null
  pinValue.value = ''
}

function formatApiError(e) {
  const errs = e?.data?.errors
  const flat = errs ? Object.values(errs).flat().filter(Boolean) : []
  if (flat.length)
    return flat.join(' ')

  return e?.data?.message || e?.message || 'No se pudo iniciar sesión. Comprueba los datos e inténtalo de nuevo.'
}

async function submitLoginPin() {
  errorMessage.value = ''
  if (!pinValid.value) {
    errorMessage.value = `El PIN debe tener entre ${PIN_MIN} y ${PIN_MAX} dígitos.`

    return
  }

  loading.value = true

  try {
    const data = await $api('/auth/login-pin', {
      method: 'POST',
      body: { pin: pinValue.value },
    })

    accessToken.value = data.access_token
    await router.push(pathAfterLogin())
  }
  catch (e) {
    errorMessage.value = formatApiError(e)
  }
  finally {
    loading.value = false
  }
}

async function submitLoginAccount() {
  errorMessage.value = ''
  if (!form.value.email?.trim()) {
    errorMessage.value = 'Introduce tu correo electrónico.'

    return
  }
  if (!form.value.password) {
    errorMessage.value = 'Introduce tu contraseña.'

    return
  }

  loading.value = true

  try {
    const data = await $api('/auth/login', {
      method: 'POST',
      body: {
        email: form.value.email,
        password: form.value.password,
      },
    })

    accessToken.value = data.access_token
    await router.push(pathAfterLogin())
  }
  catch (e) {
    errorMessage.value = formatApiError(e)
  }
  finally {
    loading.value = false
  }
}

onMounted(async () => {
  await nextTick()
  if (flowMode.value === 'pin')
    pinPanelRef.value?.focus?.()
})
</script>

<template>
  <RouterLink to="/">
    <div class="app-logo auth-logo">
      <VNodeRenderer :nodes="themeConfig.app.logo" />
      <h1 class="app-logo-title">
        {{ themeConfig.app.title }}
      </h1>
    </div>
  </RouterLink>

  <VRow
    no-gutters
    class="auth-wrapper"
  >
    <VCol
      md="8"
      class="d-none d-md-flex align-center justify-center position-relative"
    >
      <div class="d-flex align-center justify-center pa-10">
        <img
          :src="authV2LoginIllustration"
          class="auth-illustration w-100"
          alt=""
        >
      </div>
      <VImg
        :src="authV2LoginMask"
        class="d-none d-md-flex auth-footer-mask"
        alt=""
      />
    </VCol>

    <VCol
      cols="12"
      md="4"
      class="auth-card-v2 d-flex align-center justify-center"
      style="background-color: rgb(var(--v-theme-surface));"
    >
      <VCard
        flat
        :max-width="480"
        class="mt-12 mt-sm-0 pa-5 pa-lg-7"
      >
        <VCardText class="pb-2">
          <h4 class="text-h4 mb-1">
            {{ themeConfig.app.title }}
          </h4>
          <p class="text-body-2 text-medium-emphasis mb-0">
            Punto de venta · acceso seguro
          </p>
        </VCardText>

        <VCardText>
          <div v-if="flowMode === null">
            <p class="text-body-2 text-medium-emphasis mb-6">
              Elige cómo quieres iniciar sesión (no hace falta usar ambos métodos).
            </p>

            <div class="d-flex flex-column gap-3">
              <VBtn
                block
                size="large"
                color="primary"
                variant="flat"
                prepend-icon="ri-lock-password-line"
                @click="startPinOnly"
              >
                Entrar solo con PIN
              </VBtn>

              <VBtn
                block
                size="large"
                variant="tonal"
                color="primary"
                prepend-icon="ri-mail-line"
                @click="startAccountOnly"
              >
                Entrar con correo y contraseña
              </VBtn>
            </div>
          </div>

          <template v-else>
            <div class="d-flex align-center justify-space-between gap-2 mb-4 flex-wrap">
              <span class="text-caption font-weight-medium text-primary">
                {{ flowMode === 'pin' ? 'Acceso con PIN' : 'Acceso con correo' }}
              </span>
              <VBtn
                variant="text"
                size="small"
                class="text-medium-emphasis"
                @click="resetChoice"
              >
                Cambiar método
              </VBtn>
            </div>

            <VAlert
              v-if="errorMessage"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              {{ errorMessage }}
            </VAlert>

            <div
              v-if="flowMode === 'pin'"
              ref="pinPanelRef"
              class="pin-panel outline-none"
              tabindex="0"
              role="group"
              aria-label="PIN numérico"
              @keydown="onPinKeydown"
            >
              <p class="text-body-2 text-medium-emphasis mb-2">
                PIN numérico (entre {{ PIN_MIN }} y {{ PIN_MAX }} dígitos). Puedes usar el teclado en pantalla o el físico; pulsa <kbd class="text-caption">Intro</kbd> para confirmar.
              </p>

              <p class="text-caption text-medium-emphasis mb-3">
                {{ pinValue.length }} dígito(s)
              </p>

              <div class="d-flex justify-center gap-2 mb-6 pin-dots flex-wrap">
                <span
                  v-for="n in pinDotsCount"
                  :key="n"
                  class="pin-dot pin-dot--filled"
                />
              </div>

              <div class="pin-keypad mb-6">
                <button
                  v-for="n in ['1','2','3','4','5','6','7','8','9']"
                  :key="n"
                  type="button"
                  class="pin-key"
                  @click="appendDigit(n)"
                >
                  {{ n }}
                </button>
                <button
                  type="button"
                  class="pin-key pin-key--muted"
                  title="Borrar todo"
                  @click="clearPin"
                >
                  C
                </button>
                <button
                  type="button"
                  class="pin-key"
                  @click="appendDigit('0')"
                >
                  0
                </button>
                <button
                  type="button"
                  class="pin-key pin-key--muted"
                  title="Borrar último dígito"
                  @click="removeDigit"
                >
                  ⌫
                </button>
              </div>

              <VBtn
                block
                color="primary"
                :loading="loading"
                :disabled="!pinValid"
                @click="submitLoginPin"
              >
                Entrar
              </VBtn>
            </div>

            <VForm
              v-else
              @submit.prevent="submitLoginAccount"
            >
              <p class="text-body-2 text-medium-emphasis mb-4">
                Correo y contraseña de tu cuenta.
              </p>

              <VTextField
                v-model="form.email"
                label="Correo electrónico"
                type="email"
                autocomplete="username"
                class="mb-4"
                density="comfortable"
              />

              <VTextField
                v-model="form.password"
                label="Contraseña"
                autocomplete="current-password"
                class="mb-4"
                density="comfortable"
                :type="isPasswordVisible ? 'text' : 'password'"
                :append-inner-icon="isPasswordVisible ? 'ri-eye-off-line' : 'ri-eye-line'"
                @click:append-inner="isPasswordVisible = !isPasswordVisible"
              />

              <div class="d-flex align-center mb-6">
                <VCheckbox
                  v-model="form.remember"
                  label="Recordarme en este equipo"
                  density="compact"
                  hide-details
                />
              </div>

              <VBtn
                block
                type="submit"
                color="primary"
                :loading="loading"
              >
                Entrar
              </VBtn>
            </VForm>
          </template>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss" scoped>
.pin-panel {
  border-radius: 8px;

  &:focus-visible {
    outline: 2px solid rgba(var(--v-theme-primary), 0.45);
    outline-offset: 2px;
  }
}

kbd {
  padding: 0.1rem 0.35rem;
  border-radius: 4px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.pin-dots {
  min-block-size: 2rem;
}

.pin-dot {
  display: inline-block;
  inline-size: 11px;
  block-size: 11px;
  border-radius: 50%;
  border: 2px solid rgba(var(--v-theme-on-surface), 0.28);
  transition: background 0.15s ease, border-color 0.15s ease;
}

.pin-dot--filled {
  border-color: rgb(var(--v-theme-primary));
  background: rgb(var(--v-theme-primary));
}

.pin-keypad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.5rem;
  max-inline-size: 240px;
  margin-inline: auto;
}

.pin-key {
  display: flex;
  align-items: center;
  justify-content: center;
  min-block-size: 48px;
  border-radius: 10px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  font-size: 1.125rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;

  &:hover {
    background: rgba(var(--v-theme-primary), 0.08);
    border-color: rgba(var(--v-theme-primary), 0.45);
  }

  &:active {
    transform: scale(0.98);
  }
}

.pin-key--muted {
  font-size: 0.875rem;
  font-weight: 500;
}
</style>

<style lang="scss">
@use "@core/scss/template/pages/page-auth.scss";
</style>
