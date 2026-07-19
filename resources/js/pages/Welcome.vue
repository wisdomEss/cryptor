<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import type { Config } from 'ziggy-js'
import { ref } from 'vue'
import {
    Shield,
    KeyRound,
    FileSignature,
    Eye,
    Lock,
    Clock,
    Users,
    Menu,
    X,
    Mail,
    Phone,
    MapPin
} from 'lucide-vue-next'

const page = usePage()
const ziggy = page.props.ziggy as Config

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy)
}

defineProps<{ canRegister: boolean }>()

const isMenuOpen = ref(false)

const toggleMenu = () => {
    isMenuOpen.value = !isMenuOpen.value
}

const features = [
    {
        icon: KeyRound,
        title: 'Génération de clés',
        description: 'Création de paires RSA et ECDSA sécurisées.'
    },
    {
        icon: FileSignature,
        title: 'Signature de documents',
        description: 'Signature numérique compatible PKCS#7 et CMS.'
    },
    {
        icon: Eye,
        title: 'Vérifier un document',
        description: 'Vérification d’authenticité sans authentification.'
    },
    {
        icon: Lock,
        title: 'Sécurité',
        description: 'Clés privées chiffrées et stockage protégé.'
    },
    {
        icon: Clock,
        title: 'Horodatage',
        description: 'Chaque signature est horodatée.'
    },
    {
        icon: Users,
        title: 'Gestion centralisée',
        description: 'Tableau de bord pour gérer clés et signatures.'
    }
]
</script>

<template>
<Head title="Cryptor - Signature Numérique" />

<div class="min-h-screen bg-white dark:bg-gray-950 flex flex-col">

<!-- ================= NAVBAR ================= -->
<nav class="fixed top-0 w-full bg-white dark:bg-gray-950 border-b border-gray-200 dark:border-gray-800 z-50">
    <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">

        <!-- <div class="flex items-center gap-2">
            <Shield class="h-7 w-7 text-gray-800 dark:text-white" />
            <span class="font-semibold text-lg text-gray-800 dark:text-white">Cryptor</span>
        </div> -->
         <Link href="/" class="flex items-center gap-2">
            <Shield class="h-7 w-7 text-gray-800 dark:text-white" />
            <span class="font-semibold text-lg text-gray-800 dark:text-white">
                Cryptor
            </span>
        </Link>

        <div class="hidden md:flex items-center gap-8">
            <a href="#home" class="text-sm text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white">Accueil</a>
            <a href="#features" class="text-sm text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white">Fonctionnalités</a>
            <a href="#contact" class="text-sm text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white">Contact</a>
        </div>

        <div class="hidden md:flex items-center gap-4">
            <Link :href="route('verifications.public')" class="text-sm text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white">
                Vérifier un document
            </Link>

            <template v-if="$page.props.auth?.user">
                <Link :href="route('dashboard')" class="px-4 py-2 text-sm rounded-lg bg-gray-900 text-white dark:bg-white dark:text-black">
                    Dashboard
                </Link>
            </template>

            <template v-else>
                <Link :href="route('login')" class="text-sm text-gray-600 dark:text-gray-300">
                    Se connecter
                </Link>
                <Link v-if="canRegister" :href="route('register')" class="px-4 py-2 text-sm rounded-lg bg-gray-900 text-white dark:bg-white dark:text-black">
                    S’enregistrer
                </Link>
            </template>
        </div>

        <button @click="toggleMenu" class="md:hidden">
            <Menu v-if="!isMenuOpen" class="h-6 w-6" />
            <X v-else class="h-6 w-6" />
        </button>
    </div>
</nav>

<!-- ================= HERO ================= -->
<section id="home" class="pt-32 pb-20 text-center px-4">
    <h1 class="text-4xl sm:text-5xl font-bold text-gray-900 dark:text-white">
        Plateforme de Signature Numérique
    </h1>

    <p class="mt-6 max-w-2xl mx-auto text-gray-600 dark:text-gray-300">
        Générez vos clés cryptographiques, signez vos documents et vérifiez leur authenticité en toute sécurité.
    </p>

    <div class="mt-10 flex flex-wrap justify-center gap-4">
        <template v-if="$page.props.auth?.user">
            <Link :href="route('dashboard')" class="px-6 py-3 rounded-lg bg-gray-900 text-white dark:bg-white dark:text-black">
                Accéder au Dashboard
            </Link>
        </template>

        <template v-else>
            <Link :href="route('login')" class="px-6 py-3 rounded-lg bg-gray-900 text-white dark:bg-white dark:text-black">
                Se connecter
            </Link>
            <Link v-if="canRegister" :href="route('register')" class="px-6 py-3 rounded-lg border border-gray-300 dark:border-gray-700">
                S’enregistrer
            </Link>
        </template>

        <Link :href="route('verifications.public')" class="px-6 py-3 rounded-lg border border-gray-300 dark:border-gray-700">
            Vérifier un document
        </Link>
    </div>
</section>

<!-- ================= FEATURES ================= -->
<section id="features" class="py-20 bg-gray-50 dark:bg-gray-900 px-4">
    <div class="max-w-7xl mx-auto">
        <h2 class="text-3xl font-bold text-center text-gray-900 dark:text-white">
            Fonctionnalités
        </h2>

        <div class="mt-16 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="feature in features" :key="feature.title"
                class="rounded-xl border border-gray-200 dark:border-gray-800 p-6 bg-white dark:bg-gray-950">
                
                <component :is="feature.icon" class="h-6 w-6 mb-4 text-gray-700 dark:text-gray-300" />

                <h3 class="font-semibold text-gray-900 dark:text-white">
                    {{ feature.title }}
                </h3>

                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ feature.description }}
                </p>
            </div>
        </div>
    </div>
</section>


<!-- ================= WHY ================= -->
<section class="py-20 px-4 bg-white dark:bg-gray-950">
    <div class="max-w-5xl mx-auto text-center">
        <h2 class="text-3xl font-bold text-gray-900 dark:text-white">
            Pourquoi utiliser une signature numérique ?
        </h2>

        <p class="mt-6 text-gray-600 dark:text-gray-300">
            La signature numérique garantit l’authenticité, l’intégrité et la non-répudiation
            d’un document électronique. Contrairement à une simple signature scannée,
            elle repose sur des mécanismes cryptographiques robustes.
        </p>

        <div class="mt-12 grid md:grid-cols-3 gap-8 text-left">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Authenticité</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Vérifie l’identité du signataire via certificat X.509.
                </p>
            </div>

            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Intégrité</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Toute modification du document invalide la signature.
                </p>
            </div>

            <div>
                <h3 class="font-semibold text-gray-900 dark:text-gray-400 mb-2">Non-répudiation</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Le signataire ne peut nier avoir signé le document.
                </p>
            </div>
        </div>
    </div>
</section>
<!-- ================= PROCESS ================= -->
<section class="py-20 px-4 bg-gray-50 dark:bg-gray-900">
    <div class="max-w-6xl mx-auto">
        <h2 class="text-3xl font-bold text-center text-gray-900 dark:text-white">
            Comment ça fonctionne ?
        </h2>

        <div class="mt-16 grid md:grid-cols-4 gap-8 text-center">

            <div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">1</div>
                <p class="mt-3 text-gray-600 dark:text-gray-400">
                    Génération d’une paire de clés cryptographiques.
                </p>
            </div>

            <div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">2</div>
                <p class="mt-3 text-gray-600 dark:text-gray-400">
                    Création d’un certificat X.509.
                </p>
            </div>

            <div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">3</div>
                <p class="mt-3 text-gray-600 dark:text-gray-400">
                    Signature du document via PKCS#7 ou CMS.
                </p>
            </div>

            <div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">4</div>
                <p class="mt-3 text-gray-600 dark:text-gray-400">
                    Vérification cryptographique de la signature.
                </p>
            </div>

        </div>
    </div>
</section>
<!-- ================= STANDARDS ================= -->
<section class="py-20 px-4 bg-white dark:bg-gray-950">
    <div class="max-w-5xl mx-auto text-center">
        <h2 class="text-3xl font-bold text-gray-900 dark:text-white">
            Standards et Conformité
        </h2>

        <p class="mt-6 text-gray-600 dark:text-gray-300">
            La plateforme repose sur des standards cryptographiques reconnus
            internationalement.
        </p>

        <div class="mt-12 grid md:grid-cols-2 gap-8 text-left">
            <ul class="space-y-3 text-gray-600 dark:text-gray-400">
                <li>• Certificats X.509</li>
                <li>• Signature PKCS#7 / CMS</li>
                <li>• RSA 2048 / 4096 bits</li>
                <li>• ECDSA</li>
            </ul>

            <ul class="space-y-3 text-gray-600 dark:text-gray-400">
                <li>• Hash SHA-256</li>
                <li>• Chiffrement AES-256</li>
                <li>• Horodatage sécurisé</li>
                <li>• Vérification indépendante</li>
            </ul>
        </div>
    </div>
</section>

<!-- ================= USE CASES ================= -->
<section class="py-20 px-4 bg-gray-50 dark:bg-gray-900">
    <div class="max-w-6xl mx-auto text-center">
        <h2 class="text-3xl font-bold text-gray-900 dark:text-white">
            Cas d’utilisation
        </h2>

        <div class="mt-12 grid md:grid-cols-3 gap-8 text-left">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">
                    Documents académiques
                </h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Validation de diplômes, attestations et certificats.
                </p>
            </div>

            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">
                    Contrats numériques
                </h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Signature sécurisée de contrats et conventions.
                </p>
            </div>

            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">
                    Archivage sécurisé
                </h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Conservation de documents avec preuve d’intégrité.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ================= CONTACT ================= -->
<section id="contact" class="py-20 px-4">
    <div class="max-w-6xl mx-auto grid md:grid-cols-2 gap-12">

        <!-- Infos -->
        <div>
            <h2 class="text-3xl font-bold text-gray-900 dark:text-white">
                Contact
            </h2>
            <p class="mt-4 text-gray-600 dark:text-gray-300">
                Pour toute question concernant l’utilisation ou la sécurité de la plateforme.
            </p>

            <div class="mt-8 space-y-4 text-gray-600 dark:text-gray-300">
                <div class="flex items-center gap-3">
                    <Mail class="h-5 w-5" />
                    contact@cryptor.local
                </div>
                <div class="flex items-center gap-3">
                    <Phone class="h-5 w-5" />
                    +212 6 00 00 00 00
                </div>
                <div class="flex items-center gap-3">
                    <MapPin class="h-5 w-5" />
                    Maroc
                </div>
            </div>
        </div>

        <!-- Formulaire -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-6">
            <form class="space-y-4">
                <input type="text" placeholder="Nom"
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-700 px-3 py-2 bg-transparent" />

                <input type="email" placeholder="Email"
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-700 px-3 py-2 bg-transparent" />

                <textarea rows="4" placeholder="Message"
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-700 px-3 py-2 bg-transparent"></textarea>

                <button type="submit"
                    class="w-full rounded-lg bg-gray-900 text-white py-2 dark:bg-white dark:text-black">
                    Envoyer
                </button>
            </form>
        </div>

    </div>
</section>

<!-- ================= FOOTER ================= -->
<footer class="bg-gray-100 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 mt-auto">
    <div class="max-w-7xl mx-auto px-4 py-12 grid md:grid-cols-4 gap-8 text-sm">

        <div>
            <div class="flex items-center gap-2 mb-4">
                <Shield class="h-6 w-6" />
                <span class="font-semibold">Cryptor</span>
            </div>
            <p class="text-gray-600 dark:text-gray-400">
                Plateforme académique de signature numérique sécurisée.
            </p>
        </div>

        <div>
            <h4 class="font-semibold mb-3 text-gray-900 dark:text-white">Navigation</h4>
            <ul class="space-y-2 text-gray-600 dark:text-gray-400">
                <li><a href="#features">Fonctionnalités</a></li>
                <li><Link :href="route('verifications.public')">Vérifier un document</Link></li>
                <li><a href="#contact">Contact</a></li>
            </ul>
        </div>

        <div>
            <h4 class="font-semibold mb-3 text-gray-900 dark:text-white">Légal</h4>
            <ul class="space-y-2 text-gray-600 dark:text-gray-400">
                <li><a href="#">Mentions légales</a></li>
                <li><a href="#">Politique de confidentialité</a></li>
                <li><a href="#">Conditions d’utilisation</a></li>
            </ul>
        </div>

        <div>
            <h4 class="font-semibold mb-3 text-gray-900 dark:text-white">Sécurité</h4>
            <ul class="space-y-2 text-gray-600 dark:text-gray-400">
                <li>Chiffrement AES-256</li>
                <li>Conformité X.509</li>
                <li>Horodatage sécurisé</li>
            </ul>
        </div>

    </div>

    <div class="border-t border-gray-200 dark:border-gray-800 py-6 text-center text-xs text-gray-500 dark:text-gray-400">
        © {{ new Date().getFullYear() }} Cryptor — Tous droits réservés.
    </div>
</footer>

</div>
</template>