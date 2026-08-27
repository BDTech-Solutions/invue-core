// Spread into your app's tailwind.config.js content array:
//   import invueContent from './vendor/invue/core/tailwind.content.js';
//   export default { content: ['./resources/**/*.vue', ...invueContent] };
//
// Tailwind v3 does NOT merge `content` from presets (only `theme.extend` and `plugins` are
// merged — `content` is fully overridden by the project config), so a preset can't do this
// automatically. Without this glob, Tailwind's JIT scanner never sees the .vue files Invue
// resolves straight from vendor/, and every Tailwind class used inside an invue/* package
// gets silently purged.
export default ['./vendor/invue/**/*.vue']
