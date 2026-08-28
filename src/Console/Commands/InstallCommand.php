<?php

namespace Invue\Core\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Invue\Forms\FormsServiceProvider;

/**
 * Wires the pieces Getting Started otherwise has you edit by hand:
 *
 * @invue-domain/vite-plugin into vite.config.*, the Vue plugin into the
 * Inertia app entry, and the Tailwind content glob. Best-effort by design,
 * same posture as make:invue-resource — every patch checks it can match the
 * shape it expects first, and prints a manual instruction instead of
 * guessing when it can't. Safe to re-run: already-wired files are skipped,
 * not duplicated.
 *
 * If no Inertia + Vue setup exists at all and the terminal is interactive,
 * offers to bootstrap one (composer require, npm install, middleware, root
 * view, app entry) before wiring Invue into it — see bootstrapInertiaVue().
 * Non-interactive runs (--no-interaction, CI) never do this automatically;
 * they get the same plain warning as before.
 */
class InstallCommand extends Command
{
    protected $signature = 'invue:install';

    protected $description = 'Wire the Invue Vite plugin, Vue plugin, and Tailwind content glob into a Laravel + Inertia + Vue app — offers to bootstrap Inertia + Vue first if it\'s missing';

    protected Filesystem $files;

    public function handle(Filesystem $files): int
    {
        $this->files = $files;

        $this->ensureInertiaVueScaffold();
        $this->ensureAuthScaffold();

        $this->installVitePlugin();
        $this->installVuePlugin();
        $this->installTailwind();

        $this->line('');
        $this->components->info('Run `php artisan make:invue-panel` next if you don\'t have one yet, then `php artisan make:invue-resource {Model}` for a real CRUD screen — see the Creating Resources page.');

        return self::SUCCESS;
    }

    protected function ensureInertiaVueScaffold(): void
    {
        $path = $this->firstExisting(['resources/js/app.ts', 'resources/js/app.js']);
        $contents = $path !== null ? $this->files->get($path) : '';

        if (str_contains($contents, 'createInertiaApp(')) {
            return;
        }

        // Never auto-bootstrap in a non-interactive run (CI, --no-interaction)
        // — that's a real decision (composer/npm installs, new files), not
        // something to default into silently. Falls through to the plain
        // warning installVuePlugin() already prints.
        if (! $this->input->isInteractive()) {
            return;
        }

        $this->components->warn('No Inertia + Vue setup detected.');

        if (! $this->confirm('Install and configure Inertia + Vue now? (composer require, npm install, middleware, root view, app entry)', true)) {
            return;
        }

        $this->bootstrapInertiaVue($path);
    }

    protected function bootstrapInertiaVue(?string $existingEntryPath): void
    {
        $this->line('');

        $entryRelative = $existingEntryPath !== null
            ? $this->relative($existingEntryPath)
            : 'resources/js/app.ts';

        if (! $this->runStreamed('composer require inertiajs/inertia-laravel', ['composer', 'require', 'inertiajs/inertia-laravel'], fn () => $this->composerHasPackage('inertiajs/inertia-laravel'))) {
            $this->components->error('composer require failed — fix that, then re-run `php artisan invue:install`.');

            return;
        }

        if (! $this->runStreamed('npm install @inertiajs/vue3 vue @vitejs/plugin-vue', ['npm', 'install', '@inertiajs/vue3', 'vue', '@vitejs/plugin-vue'], fn () => $this->npmHasPackages(['@inertiajs/vue3', 'vue', '@vitejs/plugin-vue']))) {
            $this->components->error('npm install failed — fix that, then re-run `php artisan invue:install`.');

            return;
        }

        $this->line('');
        $this->components->task('Creating HandleInertiaRequests middleware', fn () => $this->writeInertiaMiddleware());
        $this->components->task('Registering the middleware in bootstrap/app.php', fn () => $this->registerInertiaMiddleware());
        $this->components->task('Creating resources/views/app.blade.php', fn () => $this->writeRootView($entryRelative));
        $this->components->task("Creating {$entryRelative}", fn () => $this->writeAppEntry($existingEntryPath, $entryRelative));
        $this->components->task('Registering the Vue plugin in vite.config', fn () => $this->registerVuePluginInVite());
        $this->line('');
    }

    /**
     * make:invue-panel scaffolds panels with `->middleware(['web', 'auth'])`
     * by default, so a project with no login route at all can create a
     * panel and a resource successfully and still 404/redirect-loop the
     * moment it's visited — this exists so that dead end has a way out.
     */
    protected function ensureAuthScaffold(): void
    {
        if (Route::has('login')) {
            return;
        }

        if (! $this->input->isInteractive()) {
            return;
        }

        $this->components->warn('No auth system detected — make:invue-panel scaffolds panels with an `auth` middleware, so nothing behind one will be reachable without a login route.');

        if (! $this->confirm('Set up a minimal login now (routes/auth.php, a login page, a test user)?', true)) {
            return;
        }

        $this->bootstrapAuth();
    }

    protected function bootstrapAuth(): void
    {
        $this->line('');

        if (! class_exists(User::class)) {
            $this->components->warn("No App\\Models\\User class found — can't scaffold auth without it.");

            return;
        }

        $this->components->task('Creating AuthenticatedSessionController', fn () => $this->writeAuthController());
        $this->components->task('Creating routes/auth.php', fn () => $this->writeAuthRoutes());
        $this->components->task('Requiring routes/auth.php from routes/web.php', fn () => $this->registerAuthRoutes());
        $this->components->task('Creating resources/js/pages/auth/Login.vue', fn () => $this->writeLoginPage());

        $credentials = $this->createTestUser();

        $this->line('');

        if ($credentials !== null) {
            $this->components->info("Test user ready — email: {$credentials['email']} / password: {$credentials['password']}");
        }
    }

    protected function writeAuthController(): bool
    {
        $path = app_path('Http/Controllers/Auth/AuthenticatedSessionController.php');

        if ($this->files->exists($path)) {
            return true;
        }

        $stub = <<<'PHP'
        <?php

        namespace App\Http\Controllers\Auth;

        use App\Http\Controllers\Controller;
        use Illuminate\Http\RedirectResponse;
        use Illuminate\Http\Request;
        use Illuminate\Support\Facades\Auth;
        use Inertia\Inertia;
        use Inertia\Response;

        class AuthenticatedSessionController extends Controller
        {
            public function create(): Response
            {
                return Inertia::render('auth/Login');
            }

            public function store(Request $request): RedirectResponse
            {
                $credentials = $request->validate([
                    'email' => ['required', 'string', 'email'],
                    'password' => ['required', 'string'],
                ]);

                if (! Auth::attempt($credentials, $request->boolean('remember'))) {
                    return back()->withErrors([
                        'email' => 'These credentials do not match our records.',
                    ]);
                }

                $request->session()->regenerate();

                return redirect()->intended('/');
            }

            public function destroy(Request $request): RedirectResponse
            {
                Auth::guard('web')->logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/');
            }
        }

        PHP;

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $stub);

        return true;
    }

    protected function writeAuthRoutes(): bool
    {
        $path = base_path('routes/auth.php');

        if ($this->files->exists($path)) {
            return true;
        }

        $stub = <<<'PHP'
        <?php

        use App\Http\Controllers\Auth\AuthenticatedSessionController;
        use Illuminate\Support\Facades\Route;

        Route::middleware('guest')->group(function () {
            Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
            Route::post('login', [AuthenticatedSessionController::class, 'store']);
        });

        Route::middleware('auth')->group(function () {
            Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        });

        PHP;

        $this->files->put($path, $stub);

        return true;
    }

    protected function registerAuthRoutes(): bool
    {
        $path = base_path('routes/web.php');

        if (! $this->files->exists($path)) {
            return false;
        }

        $contents = $this->files->get($path);

        if (str_contains($contents, "require __DIR__.'/auth.php'")) {
            return true;
        }

        $this->files->append($path, "\nrequire __DIR__.'/auth.php';\n");

        return true;
    }

    protected function writeLoginPage(): bool
    {
        $path = resource_path('js/pages/auth/Login.vue');

        if ($this->files->exists($path)) {
            return true;
        }

        // invue/core has no composer dependency on invue/forms — the
        // direction only ever goes the other way — but the *recommended*
        // install path (composer require invue/invue) pulls forms in
        // too, same as filament/filament bundling its own form components
        // for the panel builder. Detect it at runtime rather than assume
        // either way: real invue/forms fields when it's there, a plain
        // Tailwind-only fallback (still styled, just no field components)
        // when it isn't.
        $stub = class_exists(FormsServiceProvider::class)
            ? $this->loginPageWithInvueForms()
            : $this->loginPageWithPlainInputs();

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $stub);

        return true;
    }

    protected function loginPageWithInvueForms(): string
    {
        return <<<'VUE'
        <script setup>
        import { useForm } from '@inertiajs/vue3'
        import { TextInput, Checkbox, useInvueField } from 'invue/forms'

        const form = useForm({
            email: '',
            password: '',
            remember: false,
        })

        const { modelValue: email, error: emailError } = useInvueField(form, 'email')
        const { modelValue: password, error: passwordError } = useInvueField(form, 'password')

        function submit() {
            form.post('/login')
        }
        </script>

        <template>
            <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4">
                <form
                    class="w-full max-w-sm space-y-4 rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
                    novalidate
                    @submit.prevent="submit"
                >
                    <h1 class="text-lg font-semibold text-gray-900">Log in</h1>

                    <TextInput v-model="email" :error="emailError" type="email" label="Email" required />
                    <TextInput v-model="password" :error="passwordError" type="password" label="Password" required />
                    <Checkbox v-model="form.remember" label="Remember me" />

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        Log in
                    </button>
                </form>
            </div>
        </template>

        VUE;
    }

    protected function loginPageWithPlainInputs(): string
    {
        return <<<'VUE'
        <script setup>
        import { useForm } from '@inertiajs/vue3'

        const form = useForm({
            email: '',
            password: '',
            remember: false,
        })

        function submit() {
            form.post('/login')
        }
        </script>

        <template>
            <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4">
                <form
                    class="w-full max-w-sm space-y-4 rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
                    novalidate
                    @submit.prevent="submit"
                >
                    <h1 class="text-lg font-semibold text-gray-900">Log in</h1>

                    <div>
                        <label for="email" class="mb-1 block text-sm font-medium text-gray-700">Email</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autofocus
                            class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:border-green-500 focus:ring-green-500"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label for="password" class="mb-1 block text-sm font-medium text-gray-700">Password</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:border-green-500 focus:ring-green-500"
                        />
                        <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input v-model="form.remember" type="checkbox" class="rounded border-gray-300" />
                        Remember me
                    </label>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        Log in
                    </button>
                </form>
            </div>
        </template>

        VUE;
    }

    /**
     * @return array{email: string, password: string}|null null if a user
     *                                                     with this email already exists — never overwrites real data.
     */
    protected function createTestUser(): ?array
    {
        $email = 'admin@example.com';

        if (User::query()->where('email', $email)->exists()) {
            return null;
        }

        $password = Str::password(12, symbols: false);

        // Plain, not Hash::make()'d — the default User model casts
        // 'password' => 'hashed', which would hash an already-hashed
        // value a second time and silently break login.
        User::query()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => $password,
        ]);

        return ['email' => $email, 'password' => $password];
    }

    /**
     * Runs a composer/npm command with its real output streamed to the
     * console (a task() spinner would hide install progress for what can be
     * a 30+ second step) — then verifies the package actually landed rather
     * than trusting the process exit code alone.
     *
     * @param  list<string>  $command
     */
    protected function runStreamed(string $label, array $command, callable $verify): bool
    {
        $this->components->info($label);

        $result = Process::path(base_path())->timeout(300)->run($command, function (string $type, string $output): void {
            $this->output->write($output);
        });

        return $result->successful() && $verify();
    }

    protected function composerHasPackage(string $package): bool
    {
        $composerJson = json_decode($this->files->get(base_path('composer.json')), true);

        return isset($composerJson['require'][$package]);
    }

    /**
     * @param  list<string>  $packages
     */
    protected function npmHasPackages(array $packages): bool
    {
        $packageJson = json_decode($this->files->get(base_path('package.json')), true);

        foreach ($packages as $package) {
            if (! isset($packageJson['dependencies'][$package]) && ! isset($packageJson['devDependencies'][$package])) {
                return false;
            }
        }

        return true;
    }

    protected function writeInertiaMiddleware(): bool
    {
        $path = app_path('Http/Middleware/HandleInertiaRequests.php');

        if ($this->files->exists($path)) {
            return true;
        }

        $stub = <<<'PHP'
        <?php

        namespace App\Http\Middleware;

        use Illuminate\Http\Request;
        use Inertia\Middleware;

        class HandleInertiaRequests extends Middleware
        {
            /**
             * The root template that's loaded on the first page visit.
             */
            protected $rootView = 'app';

            public function version(Request $request): ?string
            {
                return parent::version($request);
            }

            /**
             * @return array<string, mixed>
             */
            public function share(Request $request): array
            {
                return [
                    ...parent::share($request),
                ];
            }
        }

        PHP;

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $stub);

        return true;
    }

    protected function registerInertiaMiddleware(): bool
    {
        $path = base_path('bootstrap/app.php');

        if (! $this->files->exists($path)) {
            return false;
        }

        $contents = $this->files->get($path);

        if (str_contains($contents, 'HandleInertiaRequests')) {
            return true;
        }

        // Targets the exact shape of a fresh Laravel skeleton's
        // ->withMiddleware(function (Middleware $middleware): void { // })
        // block. A project that already customized this block won't match
        // — that's the honest gap, not a guess.
        $updated = preg_replace(
            '/(->withMiddleware\(function \(Middleware \$middleware\)(?::\s*void)?\s*\{\n)(\s*)\/\/\s*\n/',
            "\$1\$2\$middleware->web(append: [\\App\\Http\\Middleware\\HandleInertiaRequests::class]);\n",
            $contents,
            1,
        );

        if ($updated === null || $updated === $contents) {
            return false;
        }

        $this->files->put($path, $updated);

        return true;
    }

    protected function writeRootView(string $entryRelative): bool
    {
        $path = base_path('resources/views/app.blade.php');

        if ($this->files->exists($path)) {
            return true;
        }

        $stub = <<<'BLADE'
        <!DOCTYPE html>
        <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">

                @vite(['resources/css/app.css', '%%ENTRY%%', "resources/js/pages/{$page['component']}.vue"])
                <x-inertia::head>
                    <title>{{ config('app.name', 'Laravel') }}</title>
                </x-inertia::head>
            </head>
            <body class="antialiased">
                <x-inertia::app />
            </body>
        </html>

        BLADE;

        $stub = str_replace('%%ENTRY%%', $entryRelative, $stub);

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $stub);

        return true;
    }

    protected function writeAppEntry(?string $existingEntryPath, string $entryRelative): bool
    {
        $targetPath = base_path($entryRelative);

        if ($existingEntryPath !== null) {
            if (! $this->isTriviallyEmpty($this->files->get($existingEntryPath))) {
                // Has real content but no createInertiaApp() — not ours to
                // overwrite. installVuePlugin() prints its own warning for
                // this once it runs.
                return false;
            }

            $targetPath = $existingEntryPath;
        }

        // The classic explicit setup() shape, not Inertia v3's auto-mount —
        // deliberately, so installVuePlugin()'s existing app.mount() branch
        // (already written and tested for that shape) is what wires
        // createInvue() into this file, right after this method returns.
        $stub = <<<'JS'
        import { createApp, h } from 'vue'
        import { createInertiaApp } from '@inertiajs/vue3'
        import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'

        createInertiaApp({
            resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob('./pages/**/*.vue')),
            setup({ el, App, props, plugin }) {
                const app = createApp({ render: () => h(App, props) })
                app.use(plugin)
                app.mount(el)
            },
        })
        JS;

        $this->files->ensureDirectoryExists(dirname($targetPath));
        $this->files->put($targetPath, $stub."\n");

        return true;
    }

    protected function registerVuePluginInVite(): bool
    {
        $path = $this->firstExisting(['vite.config.ts', 'vite.config.js']);

        if ($path === null) {
            return false;
        }

        $contents = $this->files->get($path);

        if (str_contains($contents, "from '@vitejs/plugin-vue'")) {
            return true;
        }

        $updated = $this->patchPluginsArray($contents, "import vue from '@vitejs/plugin-vue';", 'vue(),');

        if ($updated === null) {
            return false;
        }

        $this->files->put($path, $updated);

        return true;
    }

    protected function isTriviallyEmpty(string $contents): bool
    {
        $stripped = preg_replace('#//[^\n]*#', '', $contents);
        $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);

        return trim((string) $stripped) === '';
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

        $updated = $this->patchPluginsArray($contents, "import invue from '@invue-domain/vite-plugin';", 'invue(),');

        if ($updated === null) {
            $this->components->warn("Couldn't confidently patch {$relative} — add `import invue from '@invue-domain/vite-plugin'` and `invue()` inside `plugins: [...]` yourself.");

            return;
        }

        $this->files->put($path, $updated);
        $this->components->twoColumnDetail($relative, '<fg=green>wired</>');
    }

    /**
     * Shared by installVitePlugin() and registerVuePluginInVite() — adds an
     * import after the last top-level import line, and the matching plugin
     * call as the first entry in plugins: [...]. Returns null rather than
     * guessing when either regex doesn't match.
     */
    protected function patchPluginsArray(string $contents, string $importStatement, string $pluginCall): ?string
    {
        $updated = preg_replace(
            '/^(import .+;\n)(?!import)/m',
            "\$1{$importStatement}\n",
            $contents,
            1,
        );

        $updated = $updated === null ? null : preg_replace(
            '/(plugins:\s*\[\s*\n)(\s*)/',
            "\$1\$2{$pluginCall}\n\$2",
            $updated,
            1,
        );

        return ($updated === null || $updated === $contents) ? null : $updated;
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
