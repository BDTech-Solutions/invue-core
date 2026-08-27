<script setup>
// Deliberately the one component in Invue that skips the usual
// Base + resolving-wrapper split (see the parent skill's "Component
// registry" section for that rule) — there's no single default icon to
// swap, only a name -> component lookup table the registry already
// provides. The app registers whichever icons it actually imports via
// invue.registerIcons({...}) (registry.register('icons.<name>', Component)
// under the hood); no icon library is bundled/imported by invue/core
// itself, on purpose — Lucide's own docs recommend against a built-in
// resolve-any-name-by-string component precisely because it defeats
// tree-shaking (pulls every icon into the build). Explicit per-icon
// registration keeps only the icons an app actually uses in its bundle,
// and isn't tied to Lucide specifically — any icon set (or hand-rolled
// SVG components) can register under the same 'icons.<name>' contract.
import { computed, inject } from 'vue'
import { InvueRegistryKey } from '../plugin'

const props = defineProps({
    name: {
        type: String,
        default: null,
    },
})

const registry = inject(InvueRegistryKey, null)

const resolved = computed(() => (props.name ? (registry?.resolve(`icons.${props.name}`, null) ?? null) : null))

if (import.meta.env?.DEV && props.name && !resolved.value) {
    console.warn(
        `[invue] Icon "${props.name}" isn't registered — call invue.registerIcons({ '${props.name}': YourIconComponent }) before mounting.`,
    )
}
</script>

<template>
    <component :is="resolved" v-if="resolved" v-bind="$attrs" aria-hidden="true" />
</template>
