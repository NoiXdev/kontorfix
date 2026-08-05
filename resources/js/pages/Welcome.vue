<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Boxes, RefreshCw, ShieldCheck, Webhook } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const registrationEnabled = computed(() => page.props.registrationEnabled === true);

const features = [
    {
        icon: Boxes,
        title: 'Mandantenfähig',
        body: 'Jeder Kunde bekommt eigene Registries unter eigener Domain und eine schlanke Portal-Sicht mit fertigen Setup-Anleitungen.',
    },
    {
        icon: ShieldCheck,
        title: 'Moderne Anmeldung',
        body: 'Passwort mit Zwei-Faktor, Passkeys und Single-Sign-On — dazu Rollen und feingranulare Zugriffsrechte.',
    },
    {
        icon: RefreshCw,
        title: 'Spiegeln & Cachen',
        body: 'Öffentliche Quellen werden bei Bedarf gespiegelt und zwischengespeichert — schnell und auch offline verfügbar.',
    },
    {
        icon: Webhook,
        title: 'Automatisch aktuell',
        body: 'Ein Push hält deine Pakete aktuell; Ereignisse gehen signiert an deine eigenen Systeme.',
    },
];
</script>

<template>
    <Head title="Kontorfix — ein Kontor für deine Pakete" />

    <div class="relative min-h-screen overflow-hidden bg-ink text-paper">
        <!-- Atmosphere: copper glow + giant, pale warehouse gable -->
        <div class="kf-glow" aria-hidden="true" />
        <svg class="kf-gable" viewBox="0 0 96 96" aria-hidden="true">
            <path d="M18 78V54h12V42h12V30h12v12h12v12h12v24H18Z" fill="#D07A45" />
        </svg>

        <div class="relative mx-auto flex min-h-screen max-w-6xl flex-col px-6">
            <!-- Head -->
            <header class="flex items-center justify-between py-6">
                <div class="flex items-center gap-2.5">
                    <svg viewBox="0 0 96 96" class="size-9" role="img" aria-label="Kontorfix">
                        <rect width="96" height="96" rx="20" fill="#151F2E" />
                        <circle cx="48" cy="21.5" r="3" fill="#7FB5A2" />
                        <path d="M18 78V54h12V42h12V30h12v12h12v12h12v24H18Z" fill="#D07A45" />
                        <rect x="43" y="60" width="10" height="18" fill="#151F2E" />
                    </svg>
                    <span class="font-display text-xl font-extrabold lowercase tracking-tight">
                        kontor<span class="text-copper">fix</span>
                    </span>
                </div>

                <nav class="flex items-center gap-2 text-sm">
                    <Link
                        v-if="user"
                        :href="route('dashboard')"
                        class="rounded-md bg-copper px-4 py-2 font-medium text-ink transition-colors hover:bg-copper-hi"
                    >
                        Zum Dashboard
                    </Link>
                    <template v-else>
                        <Link :href="route('login')" class="rounded-md px-4 py-2 font-medium text-paper/80 transition-colors hover:text-paper"> Anmelden </Link>
                        <Link
                            v-if="registrationEnabled"
                            :href="route('register')"
                            class="rounded-md bg-copper px-4 py-2 font-medium text-ink transition-colors hover:bg-copper-hi"
                        >
                            Konto anlegen
                        </Link>
                    </template>
                </nav>
            </header>

            <!-- Hero -->
            <main class="grid flex-1 items-center gap-12 py-10 lg:grid-cols-[1.05fr_0.95fr] lg:py-16">
                <div>
                    <p class="kf-rise font-mono text-xs uppercase tracking-[0.2em] text-verdigris" style="animation-delay: 60ms">
                        Selbst gehostete Paket-Registry
                    </p>
                    <h1 class="kf-rise mt-5 text-balance font-display text-5xl font-extrabold leading-[1.02] tracking-tight sm:text-6xl" style="animation-delay: 120ms">
                        Ein Kontor für<br />deine Pakete.
                    </h1>
                    <p class="kf-rise mt-6 max-w-xl text-lg leading-relaxed text-paper/70" style="animation-delay: 200ms">
                        Composer- und npm-Pakete zentral verwalten, an Kunden ausliefern und hinter der eigenen Domain
                        absichern — mit modernem Login und feiner Rechteverwaltung.
                    </p>
                    <div class="kf-rise mt-9 flex flex-wrap items-center gap-3" style="animation-delay: 280ms">
                        <Link
                            :href="user ? route('dashboard') : route('login')"
                            class="rounded-lg bg-copper px-6 py-3 font-semibold text-ink shadow-lg shadow-copper/20 transition-colors hover:bg-copper-hi"
                        >
                            {{ user ? 'Zum Dashboard' : 'Anmelden' }}
                        </Link>
                        <a
                            href="#features"
                            class="rounded-lg border border-white/10 px-6 py-3 font-medium text-paper/80 transition-colors hover:border-white/25 hover:text-paper"
                        >
                            Was drinsteckt
                        </a>
                    </div>
                </div>

                <!-- Code vignette -->
                <div class="kf-rise" style="animation-delay: 360ms">
                    <div class="overflow-hidden rounded-xl border border-white/[0.08] bg-panel shadow-2xl shadow-black/40">
                        <div class="flex items-center gap-2 border-b border-white/[0.08] px-4 py-3">
                            <span class="size-3 rounded-full bg-[#D25B52]/70" />
                            <span class="size-3 rounded-full bg-[#D9A441]/70" />
                            <span class="size-3 rounded-full bg-[#6CBF8B]/70" />
                            <span class="ml-2 font-mono text-xs text-paper/50">composer.json</span>
                        </div>
                        <pre class="overflow-x-auto px-5 py-4 font-mono text-[13px] leading-relaxed text-paper/85"><code>{
  <span class="text-verdigris">"repositories"</span>: [
    { <span class="text-verdigris">"type"</span>: <span class="text-copper">"composer"</span>,
      <span class="text-verdigris">"url"</span>: <span class="text-copper">"https://packages.deinkunde.de"</span> }
  ]
}</code></pre>
                        <div class="border-t border-white/[0.08] px-5 py-3 font-mono text-xs text-paper/50">
                            <span class="text-paper/70">~ $</span> composer require <span class="text-paper/85">deinkunde/paket</span>
                        </div>
                    </div>
                </div>
            </main>

            <!-- Features -->
            <section id="features" class="grid gap-4 pb-16 pt-4 sm:grid-cols-2 lg:grid-cols-4">
                <article
                    v-for="f in features"
                    :key="f.title"
                    class="rounded-xl border border-white/[0.08] bg-panel/60 p-5 transition-colors hover:border-copper/40"
                >
                    <component :is="f.icon" class="size-6 text-copper" :stroke-width="1.75" />
                    <h3 class="mt-4 font-display text-lg font-bold">{{ f.title }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-paper/65">{{ f.body }}</p>
                </article>
            </section>

            <!-- Foot -->
            <footer class="flex flex-col items-center justify-between gap-2 border-t border-white/[0.08] py-6 text-sm text-paper/45 sm:flex-row">
                <span class="font-mono lowercase">kontorfix — ein kontor für deine pakete</span>
                <span>Selbst gehostet. Deine Daten bleiben bei dir.</span>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.kf-glow {
    position: absolute;
    top: -20%;
    left: 50%;
    height: 60rem;
    width: 60rem;
    transform: translateX(-50%);
    background: radial-gradient(circle, rgba(208, 122, 69, 0.16) 0%, rgba(208, 122, 69, 0) 60%);
    pointer-events: none;
}

.kf-gable {
    position: absolute;
    right: -8rem;
    bottom: -10rem;
    width: 40rem;
    opacity: 0.04;
    pointer-events: none;
}

.kf-rise {
    animation: kf-rise 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes kf-rise {
    from {
        opacity: 0;
        transform: translateY(16px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .kf-rise {
        animation: none;
    }
}
</style>
