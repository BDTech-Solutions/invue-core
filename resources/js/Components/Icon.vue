<script setup>
// Deliberately the one component in Invue that skips the usual
// Base + resolving-wrapper split (see the parent skill's "Component
// registry" section for that rule) — there's no single default icon to
// swap, only a name -> component lookup table the registry already
// provides. The app registers whichever icons it actually imports via
// invue.registerIcons({...}) (registry.register('icons.<name>', Component)
// under the hood); no icon library is EAGERLY bundled/imported by
// invue/core itself, on purpose — Lucide's own docs recommend against a
// built-in resolve-any-name-by-string component precisely because it
// defeats tree-shaking (pulls every icon into the build). Explicit
// per-icon registration keeps only the icons an app actually uses in its
// bundle, and isn't tied to Lucide specifically — any icon set (or
// hand-rolled SVG components) can register under the same 'icons.<name>'
// contract.
import { computed, inject, watchEffect } from 'vue'
import { InvueRegistryKey } from '../plugin'

const props = defineProps({
    name: {
        type: String,
        default: null,
    },
})

const registry = inject(InvueRegistryKey, null)

const resolved = computed(() => (props.name ? (registry?.resolve(`icons.${props.name}`, null) ?? null) : null))

// Falls back to a LAZY, code-split `import('@lucide/vue')` for any name
// that was never explicitly registered — e.g. a Resource's own
// $navigationIcon, a free-form string Invue can't predict at generation
// time. Deliberately not a static import here: that would pull the whole
// ~2000-icon set into every page's initial bundle (measured: +164KB
// gzipped), not just the pages that actually render a custom icon. This
// way only a page that hits an unregistered name pays for it, once — the
// resolved component is written into the same registry `resolved` above
// reads from, so every other instance of this name (this render and any
// future one) resolves synchronously from there after, exactly like an
// explicitly registered icon.
watchEffect(async () => {
    if (!props.name || resolved.value || !registry) {
        return
    }

    const pascalName = props.name.replace(/(^|-)([a-z0-9])/g, (_match, _sep, letter) => letter.toUpperCase())

    try {
        const lucide = await import('@lucide/vue')

        if (lucide[pascalName]) {
            registry.register(`icons.${props.name}`, lucide[pascalName])
        } else if (import.meta.env?.DEV) {
            console.warn(
                `[invue] Icon "${props.name}" isn't registered, and no @lucide/vue icon named "${pascalName}" exists — call invue.registerIcons({ '${props.name}': YourIconComponent }) yourself.`,
            )
        }
    } catch {
        // @lucide/vue isn't installed in this app — nothing to fall back
        // to, stays unrendered same as before this existed.
    }
})
</script>

<template>
    <component :is="resolved" v-if="resolved" v-bind="$attrs" aria-hidden="true" />
</template>
