<?php

namespace Invue\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Wires the pieces Getting Started otherwise has you edit by hand:
 *
 * @invue-domain/vite-plugin into vite.config.*, the Vue plugin into the
 * Inertia app entry, and the Tailwind content glob. Best-effort by design,
 * same posture as make:invue-resource — every patch checks it can match the
 * shape it expects first, and prints a manual instruction instead of
 * guessing when it can't. Safe to re-run: already-wired files are skipped,
 * not duplicated.
 */
class InstallCommand extends Command
{
    protected $signature = 'invue:install';

    protected $description = 'Wire the Invue Vite plugin, Vue plugin, and Tailwind content glob into an already-installed Laravel + Inertia + Vue app';

    protected Filesystem $files;

    public function handle(Filesystem $files): int
    {
        $this->files = $files;

        $this->installVitePlugin();
        $this->installVuePlugin();
        $this->installTailwind();

        $this->line('');
        $this->components->info('Run `php artisan make:invue-panel` next if you don\'t have one yet, then `php artisan make:invue-resource {Model}` for a real CRUD screen — see the Creating Resources page.');

        return self::SUCCESS;
    }

    protected function installVitePlugin(): void
    {
        $path = $this->firstExisting(['vite.config.ts', 'vite.config.js']);

        if ($path === null) {
            $this->components->warn('No vite.config.ts/vite.config.js found — add the @invue-domain/vite-plugin plugin yourself.');

            return;
        }

        $relative = $this->relative($path);
        $contents = $this->files->get($path);

        if (str_contains($contents, '@invue-domain/vite-plugin')) {
            $this->components->twoColumnDetail($relative, 'already wired');

            return;
        }

        $updated = preg_replace(
            '/^(import .+;\n)(?!import)/m',
            "\$1import invue from '@invue-domain/vite-plugin';\n",
            $contents,
            1,
        );

        $updated = $updated === null ? null : preg_replace(
            '/(plugins:\s*\[\s*\n)(\s*)/',
            "\$1\$2invue(),\n\$2",
            $updated,
            1,
        );

        if ($updated === null || $updated === $contents) {
            $this->components->warn("Couldn't confidently patch {$relative} — add `import invue from '@invue-domain/vite-plugin'` and `invue()` inside `plugins: [...]` yourself.");

            return;
        }

        $this->files->put($path, $updated);
        $this->components->twoColumnDetail($relative, '<fg=green>wired</>');
    }

    protected function installVuePlugin(): void
    {
        $path = $this->firstExisting(['resources/js/app.ts', 'resources/js/app.js']);

        if ($path === null) {
            $this->components->warn('No resources/js/app.ts or app.js found — register the Invue Vue plugin yourself.');

            return;
        }

        $relative = $this->relative($path);
        $contents = $this->files->get($path);

        if (str_contains($contents, "from 'invue/core'")) {
            $this->components->twoColumnDetail($relative, 'already wired');

            return;
        }

        if (! str_contains($contents, 'createInertiaApp(')) {
            $this->components->warn("No createInertiaApp() call found in {$relative} — register `app.use(createInvue())` yourself once Inertia is set up.");

            return;
        }

        $import = "import { createInvue } from 'invue/core';\n";
        $updated = null;

        // [ \t]*, not \s* — \s also matches the newline of a preceding blank
        // line, which would make "indent" capture a stray \n and double up
        // every blank line in the file once it's spliced back in below.
        if (preg_match('/^([ \t]*)app\.mount\(/m', $contents, $matches)) {
            // Explicit setup({ el, App, props, plugin }) callback style —
            // app.use(plugin) already exists; insert right before app.mount().
            $indent = $matches[1];
            $updated = preg_replace(
                '/^([ \t]*)app\.mount\(/m',
                "{$indent}app.use(createInvue());\n{$indent}app.mount(",
                $contents,
                1,
            );
        } elseif (preg_match('/^([ \t]*)withApp:\s*\(app(?:,[^)]*)?\)\s*=>\s*\{\n/m', $contents, $matches)) {
            // Existing withApp() hook — append the call inside it.
            $indent = $matches[1];
            $updated = preg_replace(
                '/^([ \t]*withApp:\s*\(app(?:,[^)]*)?\)\s*=>\s*\{\n)/m',
                "\$1{$indent}    app.use(createInvue());\n",
                $contents,
                1,
            );
        } elseif (preg_match('/^([ \t]*)createInertiaApp\(\{\n/m', $contents, $matches)) {
            // Neither setup nor withApp — Inertia mounts automatically
            // (v3's default), so add a withApp hook to reach the app instance.
            $indent = $matches[1];
            $updated = preg_replace(
                '/^([ \t]*createInertiaApp\(\{\n)/m',
                "\$1{$indent}    withApp: (app) => {\n{$indent}        app.use(createInvue());\n{$indent}    },\n",
                $contents,
                1,
            );
        }

        if ($updated === null) {
            $this->components->warn("Couldn't confidently patch {$relative} — add `app.use(createInvue())` (import { createInvue } from 'invue/core') yourself.");

            return;
        }

        $this->files->put($path, $import.$updated);
        $this->components->twoColumnDetail($relative, '<fg=green>wired</>');
    }

    protected function installTailwind(): void
    {
        $cssPath = $this->firstExisting(['resources/css/app.css']);

        if ($cssPath !== null) {
            $relative = $this->relative($cssPath);
            $contents = $this->files->get($cssPath);

            if (str_contains($contents, 'vendor/invue')) {
                $this->components->twoColumnDetail($relative, 'already wired');

                return;
            }

            if (str_contains($contents, "@import 'tailwindcss'") || str_contains($contents, '@import "tailwindcss"')) {
                $this->files->append($cssPath, "@source '../../vendor/invue/**/*.vue';\n");
                $this->components->twoColumnDetail($relative, '<fg=green>wired</>');

                return;
            }
        }

        $jsPath = $this->firstExisting(['tailwind.config.js', 'tailwind.config.ts']);

        if ($jsPath !== null) {
            $this->components->warn('Tailwind v3 config detected ('.$this->relative($jsPath).") — spread invue/core's tailwind.content.js into your content array by hand, see Getting Started.");

            return;
        }

        $this->components->warn('No Tailwind config found — add the Invue content glob yourself once Tailwind is set up.');
    }

    /**
     * @param  list<string>  $relativePaths
     */
    protected function firstExisting(array $relativePaths): ?string
    {
        foreach ($relativePaths as $relative) {
            $path = base_path($relative);

            if ($this->files->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
