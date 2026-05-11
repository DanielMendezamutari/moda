import { ref } from 'vue'
import { $api } from '@/utils/api'

/** Cache para no repetir peticiones al navegar entre pantallas de admin. */
let cached = null

export function useBoliviaDepartments() {
  const departments = ref(Array.isArray(cached) ? [...cached] : [])
  const loading = ref(false)

  async function load() {
    if (cached?.length) {
      departments.value = [...cached]

      return
    }
    loading.value = true
    try {
      const res = await $api('/meta/bolivia-departments')
      const list = Array.isArray(res?.data) ? res.data : []

      cached = list
      departments.value = [...list]
    }
    catch {
      departments.value = []
    }
    finally {
      loading.value = false
    }
  }

  return { departments, loading, load }
}
