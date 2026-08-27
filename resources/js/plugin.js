import { createRegistry } from './registry'

export const InvueRegistryKey = Symbol('invue-registry')

export function createInvue() {
    const registry = createRegistry()

    return {
        registry,
        // Sugar over registry.register('icons.<name>', Component) for every
        // entry — the actual mechanism is still the one registry, this just
        // saves prefixing each key by hand when registering a whole icon
        // set at once. See Components/Icon.vue for the resolve side.
        registerIcons(icons) {
            for (const [name, component] of Object.entries(icons)) {
                registry.register(`icons.${name}`, component)
            }
        },
        install(app) {
            app.provide(InvueRegistryKey, registry)
            app.config.globalProperties.$invue = registry
        },
    }
}
