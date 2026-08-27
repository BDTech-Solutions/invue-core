import { reactive } from 'vue'

export function createRegistry() {
    const overrides = reactive({})

    return {
        register(key, component) {
            overrides[key] = component
        },
        resolve(key, fallback) {
            return overrides[key] ?? fallback
        },
        overrides,
    }
}
