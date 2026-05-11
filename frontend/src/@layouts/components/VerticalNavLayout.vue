<script>
import { VerticalNav } from '@layouts/components'
import { useLayoutConfigStore } from '@layouts/stores/config'

export default defineComponent({
  props: {
    navItems: {
      type: Array,
      required: true,
    },
    verticalNavAttrs: {
      type: Object,
      default: () => ({}),
    },
  },
  setup(props, { slots }) {
    const { width: windowWidth } = useWindowSize()
    const configStore = useLayoutConfigStore()

    // Pinia omite `_layoutClasses` en storeToRefs; el computed depende del store y fuerza re-render al colapsar.
    const layoutShellClasses = computed(() => ['layout-wrapper', ...configStore._layoutClasses])

    const isOverlayNavActive = ref(false)
    const isLayoutOverlayVisible = ref(false)
    const toggleIsOverlayNavActive = useToggle(isOverlayNavActive)


    // ℹ️ This is alternative to below two commented watcher
    // We want to show overlay if overlay nav is visible and want to hide overlay if overlay is hidden and vice versa.
    syncRef(isOverlayNavActive, isLayoutOverlayVisible)

    // watch(isOverlayNavActive, value => {
    //   // Sync layout overlay with overlay nav
    //   isLayoutOverlayVisible.value = value
    // })
    // watch(isLayoutOverlayVisible, value => {
    //   // If overlay is closed via click, close hide overlay nav
    //   if (!value) isOverlayNavActive.value = false
    // })
    // ℹ️ Hide overlay if user open overlay nav in <md and increase the window width without closing overlay nav
    watch(windowWidth, () => {
      if (!configStore.isLessThanOverlayNavBreakpoint && isLayoutOverlayVisible.value)
        isLayoutOverlayVisible.value = false
    })
    
    return () => {
      const verticalNavAttrs = toRef(props, 'verticalNavAttrs')
      const { wrapper: verticalNavWrapper, wrapperProps: verticalNavWrapperProps, ...additionalVerticalNavAttrs } = verticalNavAttrs.value


      // 👉 Vertical nav
      const verticalNav = h(VerticalNav, { isOverlayNavActive: isOverlayNavActive.value, toggleIsOverlayNavActive, navItems: props.navItems, ...additionalVerticalNavAttrs }, {
        'nav-header': () => slots['vertical-nav-header']?.(),
        'before-nav-items': () => slots['before-vertical-nav-items']?.(),
      })


      // 👉 Navbar
      const navbar = h('header', { class: ['layout-navbar', { 'navbar-blur': configStore.isNavbarBlurEnabled }] }, [
        h('div', { class: 'navbar-content-container' }, slots.navbar?.({
          toggleVerticalOverlayNavActive: toggleIsOverlayNavActive,
        })),
      ])


      // 👉 Content area
      const main = h('main', { class: 'layout-page-content' }, h('div', { class: 'page-content-container' }, slots.default?.()))


      // 👉 Footer
      const footer = h('footer', { class: 'layout-footer' }, [
        h('div', { class: 'footer-content-container' }, slots.footer?.()),
      ])


      // 👉 Overlay
      const layoutOverlay = h('div', {
        class: ['layout-overlay', { visible: isLayoutOverlayVisible.value }],
        onClick: () => { isLayoutOverlayVisible.value = !isLayoutOverlayVisible.value },
      })

      return h('div', { class: layoutShellClasses.value }, [
        verticalNavWrapper ? h(verticalNavWrapper, verticalNavWrapperProps, { default: () => verticalNav }) : verticalNav,
        h('div', { class: 'layout-content-wrapper' }, [
          navbar,
          main,
          footer,
        ]),
        layoutOverlay,
      ])
    }
  },
})
</script>

<style lang="scss">
@use "@configured-variables" as variables;
@use "@layouts/styles/placeholders";
@use "@layouts/styles/mixins";

.layout-wrapper.layout-nav-type-vertical {
  // TODO(v2): Check why we need height in vertical nav & min-height in horizontal nav
  block-size: 100%;

  .layout-content-wrapper {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    /* Permite que el contenido no fuerce ancho mínimo del viewport (tablas, filas flex). */
    min-inline-size: 0;
    min-block-size: 100dvh;
    transition: padding-inline-start 0.2s ease-in-out;
    will-change: padding-inline-start;

    @media screen and (min-width: 1280px) {
      padding-inline-start: variables.$layout-vertical-nav-width;
    }
  }

  .layout-page-content {
    min-inline-size: 0;
    max-inline-size: 100%;
  }

  .page-content-container {
    min-inline-size: 0;
    max-inline-size: 100%;
    overflow-x: auto;
  }

  .layout-navbar {
    z-index: variables.$layout-vertical-nav-layout-navbar-z-index;

    .navbar-content-container {
      block-size: variables.$layout-vertical-nav-navbar-height;
      min-inline-size: 0;
    }

    @at-root {
      .layout-wrapper.layout-nav-type-vertical {
        .layout-navbar {
          @if variables.$layout-vertical-nav-navbar-is-contained {
            @include mixins.boxed-content;
          }

          // else
          @else {
            .navbar-content-container {
              @include mixins.boxed-content;
            }
          }
        }
      }
    }
  }

  &.layout-navbar-sticky .layout-navbar {
    @extend %layout-navbar-sticky;
  }

  &.layout-navbar-hidden .layout-navbar {
    @extend %layout-navbar-hidden;
  }

  // 👉 Footer
  .layout-footer {
    @include mixins.boxed-content;
  }

  // 👉 Layout overlay
  .layout-overlay {
    position: fixed;
    z-index: variables.$layout-overlay-z-index;
    background-color: rgb(0 0 0 / 60%);
    cursor: pointer;
    inset: 0;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease-in-out;
    will-change: transform;

    &.visible {
      opacity: 1;
      pointer-events: auto;
    }
  }

  // Adjust right column pl when vertical nav is collapsed
  &.layout-vertical-nav-collapsed .layout-content-wrapper {
    padding-inline-start: variables.$layout-vertical-nav-collapsed-width;
  }

  // 👉 Content height fixed
  &.layout-content-height-fixed {
    .layout-content-wrapper {
      max-block-size: 100dvh;
    }

    .layout-page-content {
      display: flex;
      overflow: hidden;

      .page-content-container {
        inline-size: 100%;
        min-inline-size: 0;

        > :first-child {
          max-block-size: 100%;
          min-inline-size: 0;
          overflow: auto;
        }
      }
    }
  }
}
</style>
