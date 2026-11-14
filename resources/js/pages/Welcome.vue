<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
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
    MapPin,
} from 'lucide-vue-next';
import { ref } from 'vue';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

defineProps<{ canRegister: boolean }>();

const isMenuOpen = ref(false);

const toggleMenu = () => {
    isMenuOpen.value = !isMenuOpen.value;
};

const features = [
    {
        icon: KeyRound,
        title: 'Génération de clés',
        description: 'Création de paires RSA et ECDSA sécurisées.',
    },
    {
        icon: FileSignature,
        title: 'Signature de documents',
        description: 'Signature numérique compatible PKCS#7 et CMS.',
    },
    {
        icon: Eye,
        title: 'Vérifier un document',
        description: 'Vérification d’authenticité sans authentification.',
    },
    {
        icon: Lock,
        title: 'Sécurité',
        description: 'Clés privées chiffrées et stockage protégé.',
    },
    {
        icon: Clock,
        title: 'Horodatage',
        description: 'Chaque signature est horodatée.',
    },
    {
        icon: Users,
        title: 'Gestion centralisée',
        description: 'Tableau de bord pour gérer clés et signatures.',
    },
];
</script>

<template>
    <Head title="Cryptor - Signature Numérique" />

    <div class="flex min-h-screen flex-col bg-white dark:bg-gray-950">
        <!-- ================= NAVBAR ================= -->
        <nav
            class="fixed top-0 z-50 w-full border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950"
        >
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4"
            >
                <!-- <div class="flex items-center gap-2">
            <Shield class="h-7 w-7 text-gray-800 dark:text-white" />
            <span class="font-semibold text-lg text-gray-800 dark:text-white">Cryptor</span>
        </div> -->
                <Link href="/" class="flex items-center gap-2">
                    <Shield class="h-7 w-7 text-gray-800 dark:text-white" />
                    <span
                        class="text-lg font-semibold text-gray-800 dark:text-white"
                    >
                        Cryptor
                    </span>
                </Link>

                <div class="hidden items-center gap-8 md:flex">
                    <a
                        href="#home"
                        class="text-sm text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white"
                        >Accueil</a
                    >
                    <a
                        href="#features"
                        class="text-sm text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white"
                        >Fonctionnalités</a
                    >
                    <a
                        href="#contact"
                        class="text-sm text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white"
                        >Contact</a
                    >
                </div>

                <div class="hidden items-center gap-4 md:flex">
                    <Link
                        :href="route('verifications.public')"
                        class="text-sm text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white"
                    >
                        Vérifier un document
                    </Link>

                    <template v-if="$page.props.auth?.user">
                        <Link
                            :href="route('dashboard')"
                            class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white dark:bg-white dark:text-black"
                        >
                            Dashboard
                        </Link>
                    </template>

                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="text-sm text-gray-600 dark:text-gray-300"
                        >
                            Se connecter
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white dark:bg-white dark:text-black"
                        >
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
        <section id="home" class="px-4 pt-32 pb-20 text-center">
            <h1
                class="text-4xl font-bold text-gray-900 sm:text-5xl dark:text-white"
            >
                Plateforme de Signature Numérique
            </h1>

            <p class="mx-auto mt-6 max-w-2xl text-gray-600 dark:text-gray-300">
                Générez vos clés cryptographiques, signez vos documents et
                vérifiez leur authenticité en toute sécurité.
            </p>

            <div class="mt-10 flex flex-wrap justify-center gap-4">
                <template v-if="$page.props.auth?.user">
                    <Link
                        :href="route('dashboard')"
                        class="rounded-lg bg-gray-900 px-6 py-3 text-white dark:bg-white dark:text-black"
                    >
                        Accéder au Dashboard
                    </Link>
                </template>

                <template v-else>
                    <Link
                        :href="route('login')"
                        class="rounded-lg bg-gray-900 px-6 py-3 text-white dark:bg-white dark:text-black"
                    >
                        Se connecter
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="rounded-lg border border-gray-300 px-6 py-3 dark:border-gray-700"
                    >
                        S’enregistrer
                    </Link>
                </template>

                <Link
                    :href="route('verifications.public')"
                    class="rounded-lg border border-gray-300 px-6 py-3 dark:border-gray-700"
                >
                    Vérifier un document
                </Link>
            </div>
        </section>

        <!-- ================= FEATURES ================= -->
        <section id="features" class="bg-gray-50 px-4 py-20 dark:bg-gray-900">
            <div class="mx-auto max-w-7xl">
                <h2
                    class="text-center text-3xl font-bold text-gray-900 dark:text-white"
                >
                    Fonctionnalités
                </h2>

                <div class="mt-16 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-950"
                    >
                        <component
                            :is="feature.icon"
                            class="mb-4 h-6 w-6 text-gray-700 dark:text-gray-300"
                        />

                        <h3 class="font-semibold text-gray-900 dark:text-white">
                            {{ feature.title }}
                        </h3>

                        <p
                            class="mt-2 text-sm text-gray-600 dark:text-gray-400"
                        >
                            {{ feature.description }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ================= WHY ================= -->
        <section class="bg-white px-4 py-20 dark:bg-gray-950">
            <div class="mx-auto max-w-5xl text-center">
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white">
                    Pourquoi utiliser une signature numérique ?
                </h2>

                <p class="mt-6 text-gray-600 dark:text-gray-300">
                    La signature numérique garantit l’authenticité, l’intégrité
                    et la non-répudiation d’un document électronique.
                    Contrairement à une simple signature scannée, elle repose
                    sur des mécanismes cryptographiques robustes.
                </p>

                <div class="mt-12 grid gap-8 text-left md:grid-cols-3">
                    <div>
                        <h3
                            class="mb-2 font-semibold text-gray-900 dark:text-white"
                        >
                            Authenticité
                        </h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            Vérifie l’identité du signataire via certificat
                            X.509.
                        </p>
                    </div>

                    <div>
                        <h3
                            class="mb-2 font-semibold text-gray-900 dark:text-white"
                        >
                            Intégrité
                        </h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            Toute modification du document invalide la
                            signature.
                        </p>
                    </div>

                    <div>
                        <h3
                            class="mb-2 font-semibold text-gray-900 dark:text-gray-400"
                        >
                            Non-répudiation
                        </h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            Le signataire ne peut nier avoir signé le document.
                        </p>
                    </div>
                </div>
            </div>
        </section>
        <!-- ================= PROCESS ================= -->
        <section class="bg-gray-50 px-4 py-20 dark:bg-gray-900">
            <div class="mx-auto max-w-6xl">
                <h2
                    class="text-center text-3xl font-bold text-gray-900 dark:text-white"
                >
                    Comment ça fonctionne ?
                </h2>

                <div class="mt-16 grid gap-8 text-center md:grid-cols-4">
                    <div>
                        <div
                            class="text-2xl font-bold text-gray-900 dark:text-white"
                        >
                            1
                        </div>
                        <p class="mt-3 text-gray-600 dark:text-gray-400">
                            Génération d’une paire de clés cryptographiques.
                        </p>
                    </div>

                    <div>
                        <div
                            class="text-2xl font-bold text-gray-900 dark:text-white"
                        >
                            2
                        </div>
                        <p class="mt-3 text-gray-600 dark:text-gray-400">
                            Création d’un certificat X.509.
                        </p>
                    </div>

                    <div>
                        <div
                            class="text-2xl font-bold text-gray-900 dark:text-white"
                        >
                            3
                        </div>
                        <p class="mt-3 text-gray-600 dark:text-gray-400">
                            Signature du document via PKCS#7 ou CMS.
                        </p>
                    </div>

                    <div>
                        <div
                            class="text-2xl font-bold text-gray-900 dark:text-white"
                        >
                            4
                        </div>
                        <p class="mt-3 text-gray-600 dark:text-gray-400">
                            Vérification cryptographique de la signature.
                        </p>
                    </div>
                </div>
            </div>
        </section>
        <!-- ================= STANDARDS ================= -->
        <section class="bg-white px-4 py-20 dark:bg-gray-950">
            <div class="mx-auto max-w-5xl text-center">
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white">
                    Standards et Conformité
                </h2>

                <p class="mt-6 text-gray-600 dark:text-gray-300">
                    La plateforme repose sur des standards cryptographiques
                    reconnus internationalement.
                </p>

                <div class="mt-12 grid gap-8 text-left md:grid-cols-2">
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
        <section class="bg-gray-50 px-4 py-20 dark:bg-gray-900">
            <div class="mx-auto max-w-6xl text-center">
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white">
                    Cas d’utilisation
                </h2>

                <div class="mt-12 grid gap-8 text-left md:grid-cols-3">
                    <div>
                        <h3
                            class="mb-2 font-semibold text-gray-900 dark:text-white"
                        >
                            Documents académiques
                        </h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            Validation de diplômes, attestations et certificats.
                        </p>
                    </div>

                    <div>
                        <h3
                            class="mb-2 font-semibold text-gray-900 dark:text-white"
                        >
                            Contrats numériques
                        </h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            Signature sécurisée de contrats et conventions.
                        </p>
                    </div>

                    <div>
                        <h3
                            class="mb-2 font-semibold text-gray-900 dark:text-white"
                        >
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
        <section id="contact" class="px-4 py-20">
            <div class="mx-auto grid max-w-6xl gap-12 md:grid-cols-2">
                <!-- Infos -->
                <div>
                    <h2
                        class="text-3xl font-bold text-gray-900 dark:text-white"
                    >
                        Contact
                    </h2>
                    <p class="mt-4 text-gray-600 dark:text-gray-300">
                        Pour toute question concernant l’utilisation ou la
                        sécurité de la plateforme.
                    </p>

                    <div
                        class="mt-8 space-y-4 text-gray-600 dark:text-gray-300"
                    >
                        <div class="flex items-center gap-3">
                            <Mail class="h-5 w-5" />
                            ewisdommoukpe@gmail.com
                        </div>

                        <div class="flex items-center gap-3">
                            <MapPin class="h-5 w-5" />
                            Maroc
                        </div>
                    </div>
                </div>

                <!-- Formulaire -->
                <div
                    class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900"
                >
                    <form class="space-y-4">
                        <input
                            type="text"
                            placeholder="Nom"
                            class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 dark:border-gray-700"
                        />

                        <input
                            type="email"
                            placeholder="Email"
                            class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 dark:border-gray-700"
                        />

                        <textarea
                            rows="4"
                            placeholder="Message"
                            class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 dark:border-gray-700"
                        ></textarea>

                        <button
                            type="submit"
                            class="w-full rounded-lg bg-gray-900 py-2 text-white dark:bg-white dark:text-black"
                        >
                            Envoyer
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <!-- ================= FOOTER ================= -->
        <footer
            class="mt-auto border-t border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-gray-900"
        >
            <div
                class="mx-auto grid max-w-7xl gap-8 px-4 py-12 text-sm md:grid-cols-4"
            >
                <div>
                    <div class="mb-4 flex items-center gap-2">
                        <Shield class="h-6 w-6" />
                        <span class="font-semibold">Cryptor</span>
                    </div>
                    <p class="text-gray-600 dark:text-gray-400">
                        Plateforme académique de signature numérique sécurisée.
                    </p>
                </div>

                <div>
                    <h4
                        class="mb-3 font-semibold text-gray-900 dark:text-white"
                    >
                        Navigation
                    </h4>
                    <ul class="space-y-2 text-gray-600 dark:text-gray-400">
                        <li><a href="#features">Fonctionnalités</a></li>
                        <li>
                            <Link :href="route('verifications.public')"
                                >Vérifier un document</Link
                            >
                        </li>
                        <li><a href="#contact">Contact</a></li>
                    </ul>
                </div>

                <div>
                    <h4
                        class="mb-3 font-semibold text-gray-900 dark:text-white"
                    >
                        Légal
                    </h4>
                    <ul class="space-y-2 text-gray-600 dark:text-gray-400">
                        <li><a href="#">Mentions légales</a></li>
                        <li><a href="#">Politique de confidentialité</a></li>
                        <li><a href="#">Conditions d’utilisation</a></li>
                    </ul>
                </div>

                <div>
                    <h4
                        class="mb-3 font-semibold text-gray-900 dark:text-white"
                    >
                        Sécurité
                    </h4>
                    <ul class="space-y-2 text-gray-600 dark:text-gray-400">
                        <li>Chiffrement AES-256</li>
                        <li>Conformité X.509</li>
                        <li>Horodatage sécurisé</li>
                    </ul>
                </div>
            </div>

            <div
                class="border-t border-gray-200 py-6 text-center text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400"
            >
                © {{ new Date().getFullYear() }} Cryptor — Tous droits réservés.
            </div>
        </footer>
    </div>
</template>
