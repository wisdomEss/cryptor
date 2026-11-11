<!-- resources/js/pages/publicVerifications/Index.vue -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    Shield,
    Upload,
    FileText,
    CheckCircle2,
    XCircle,
    AlertCircle,
    Info,
    Loader2,
    Eye,
    Copy,
    Globe,
    Lock,
    HelpCircle,
    Menu,
    X,
} from 'lucide-vue-next';
import { ref, computed } from 'vue';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

// États
const selectedFile = ref<File | null>(null);
const selectedSignatureFile = ref<File | null>(null);
const isDragging = ref(false);
const isDraggingSignature = ref(false);
const isVerifying = ref(false);
const verificationProgress = ref(0);
const isMenuOpen = ref(false);
const verificationResult = ref<null | {
    success: boolean;
    message: string;
    details: {
        signature_valid: boolean;
        document_integrity: boolean;
        signer: string;
        signed_at: string;
        algorithm: string;
        fingerprint: string;
        certificate_trust?: string;
        timestamp?: { status: string; time: string | null };
        certificate_info?: any;
        warning?: string;
    };
    verification_id?: number;
    token?: string;
}>(null);

const trustInfo = (status: string) => {
    const infos: Record<string, { label: string; class: string }> = {
        trusted: {
            label: "Émis par l'autorité CRYPTOR, valide",
            class: 'text-green-600 dark:text-green-400',
        },
        self_signed: {
            label: "Auto-signé (non émis par l'autorité CRYPTOR)",
            class: 'text-yellow-600 dark:text-yellow-400',
        },
        untrusted: {
            label: 'Émetteur inconnu (autorité non reconnue)',
            class: 'text-red-600 dark:text-red-400',
        },
        expired: {
            label: 'Certificat expiré',
            class: 'text-red-600 dark:text-red-400',
        },
        revoked: {
            label: 'Certificat révoqué',
            class: 'text-red-600 dark:text-red-400',
        },
        invalid: {
            label: 'Certificat invalide',
            class: 'text-red-600 dark:text-red-400',
        },
        unknown: {
            label: 'Aucun certificat à valider',
            class: 'text-gray-500 dark:text-gray-400',
        },
    };

    return infos[status] ?? infos.unknown;
};

const timestampInfo = (status: string) => {
    const infos: Record<string, { label: string; class: string }> = {
        trusted: {
            label: 'Horodaté par une autorité de confiance (RFC 3161)',
            class: 'text-green-600 dark:text-green-400',
        },
        untrusted_tsa: {
            label: 'Horodaté par une autorité non reconnue (RFC 3161)',
            class: 'text-yellow-600 dark:text-yellow-400',
        },
        internal: {
            label: 'Horodatage interne (heure du serveur, sans tiers)',
            class: 'text-yellow-600 dark:text-yellow-400',
        },
        invalid: {
            label: 'Horodatage invalide',
            class: 'text-red-600 dark:text-red-400',
        },
    };

    return infos[status];
};

const verificationMethod = ref<'token' | 'upload'>('token');
const verificationToken = ref('');
const tokenError = ref('');

// Références typées
const fileInput = ref<HTMLInputElement | null>(null);
const signatureInput = ref<HTMLInputElement | null>(null);

const toggleMenu = () => {
    isMenuOpen.value = !isMenuOpen.value;
};

// Gestion des fichiers
const handleFileSelect = (event: Event, type: 'file' | 'signature') => {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (type === 'file') {
            selectedFile.value = file;
        } else {
            selectedSignatureFile.value = file;
        }
    }
};

const handleDrop = (event: DragEvent, type: 'file' | 'signature') => {
    event.preventDefault();
    if (type === 'file') {
        isDragging.value = false;
    } else {
        isDraggingSignature.value = false;
    }

    if (event.dataTransfer?.files && event.dataTransfer.files[0]) {
        const file = event.dataTransfer.files[0];
        if (type === 'file') {
            selectedFile.value = file;
        } else {
            selectedSignatureFile.value = file;
        }
    }
};

const removeFile = (type: 'file' | 'signature') => {
    if (type === 'file') {
        selectedFile.value = null;
    } else {
        selectedSignatureFile.value = null;
    }
};

const verifyByToken = () => {
    if (!verificationToken.value.trim()) {
        tokenError.value = 'Veuillez entrer un token de vérification';
        return;
    }

    tokenError.value = '';
    isVerifying.value = true;
    verificationProgress.value = 0;

    const interval = setInterval(() => {
        verificationProgress.value += 10;
        if (verificationProgress.value >= 100) {
            clearInterval(interval);
        }
    }, 300);

    fetch(route('api.public.verify'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ token: verificationToken.value }),
    })
        .then((response) => response.json())
        .then((data) => {
            clearInterval(interval);
            verificationProgress.value = 100;
            verificationResult.value = data;
            isVerifying.value = false;
        })
        .catch((error) => {
            clearInterval(interval);
            console.error('Erreur:', error);
            verificationResult.value = {
                success: false,
                message: 'Erreur lors de la vérification',
                details: {
                    signature_valid: false,
                    document_integrity: false,
                    signer: 'Inconnu',
                    signed_at: '',
                    algorithm: 'Inconnu',
                    fingerprint: '',
                    warning: 'Impossible de contacter le serveur',
                },
            };
            isVerifying.value = false;
        });
};

const verifyByUpload = () => {
    if (!selectedFile.value) {
        alert('Veuillez sélectionner un fichier à vérifier');
        return;
    }

    isVerifying.value = true;
    verificationProgress.value = 0;

    const interval = setInterval(() => {
        verificationProgress.value += 10;
        if (verificationProgress.value >= 100) {
            clearInterval(interval);
        }
    }, 300);

    const formData = new FormData();
    formData.append('file', selectedFile.value);
    if (selectedSignatureFile.value) {
        formData.append('signature_file', selectedSignatureFile.value);
    }

    fetch(route('api.public.verify'), {
        method: 'POST',
        body: formData,
    })
        .then((response) => response.json())
        .then((data) => {
            clearInterval(interval);
            verificationProgress.value = 100;
            verificationResult.value = data;
            isVerifying.value = false;
        })
        .catch((error) => {
            clearInterval(interval);
            console.error('Erreur:', error);
            verificationResult.value = {
                success: false,
                message: 'Erreur lors de la vérification',
                details: {
                    signature_valid: false,
                    document_integrity: false,
                    signer: 'Inconnu',
                    signed_at: '',
                    algorithm: 'Inconnu',
                    fingerprint: '',
                    warning: 'Impossible de contacter le serveur',
                },
            };
            isVerifying.value = false;
        });
};

const resetVerification = () => {
    selectedFile.value = null;
    selectedSignatureFile.value = null;
    verificationToken.value = '';
    tokenError.value = '';
    verificationResult.value = null;
    verificationMethod.value = 'token';
};

const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const formatDate = (date: string | null) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleString('fr-FR');
};

const copyToClipboard = (text: string) => {
    if (text) {
        navigator.clipboard.writeText(text);
    }
};

const canVerify = computed(() => {
    if (verificationMethod.value === 'token') {
        return verificationToken.value.trim().length > 0;
    } else {
        return selectedFile.value !== null;
    }
});

const openFileSelector = (type: 'file' | 'signature') => {
    if (type === 'file' && fileInput.value) {
        fileInput.value.click();
    } else if (type === 'signature' && signatureInput.value) {
        signatureInput.value.click();
    }
};

const getStatusBadge = (valid: boolean) => {
    return valid
        ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'
        : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
};
</script>

<template>
    <Head title="Vérification Publique - Cryptor" />

    <div class="flex min-h-screen flex-col bg-white dark:bg-gray-950">
        <!-- Navigation -->
        <nav
            class="fixed top-0 z-50 w-full border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950"
        >
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4"
            >
                <div class="flex items-center gap-2">
                    <Shield class="h-7 w-7 text-gray-800 dark:text-white" />
                    <span
                        class="text-lg font-semibold text-gray-800 dark:text-white"
                        >Cryptor</span
                    >
                </div>

                <div class="hidden items-center gap-8 md:flex">
                    <Link
                        href="/#home"
                        class="text-sm text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white"
                    >
                        Accueil
                    </Link>
                    <Link
                        href="/#features"
                        class="text-sm text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white"
                    >
                        Fonctionnalités
                    </Link>
                    <Link
                        href="/#contact"
                        class="text-sm text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white"
                    >
                        Contact
                    </Link>
                </div>

                <div class="hidden items-center gap-4 md:flex">
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

        <!-- Contenu principal -->
        <main class="flex-1 px-4 pt-24 pb-20">
            <div class="mx-auto max-w-4xl">
                <!-- En-tête -->
                <div class="mb-12 text-center">
                    <div
                        class="mb-4 inline-flex rounded-full bg-gray-100 p-3 dark:bg-gray-800"
                    >
                        <Globe
                            class="h-8 w-8 text-gray-700 dark:text-gray-300"
                        />
                    </div>
                    <h1
                        class="text-3xl font-bold text-gray-900 sm:text-4xl dark:text-white"
                    >
                        Vérification Publique
                    </h1>
                    <p
                        class="mx-auto mt-4 max-w-2xl text-lg text-gray-600 dark:text-gray-300"
                    >
                        Vérifiez l'authenticité d'une signature numérique sans
                        créer de compte
                    </p>
                </div>

                <!-- Résultat -->
                <div v-if="verificationResult" class="mb-8">
                    <div
                        class="rounded-xl border p-6"
                        :class="
                            verificationResult.success
                                ? 'border-green-500 bg-green-50 dark:bg-green-950/20'
                                : 'border-red-500 bg-red-50 dark:bg-red-950/20'
                        "
                    >
                        <div class="flex items-center gap-4">
                            <div
                                :class="
                                    verificationResult.success
                                        ? 'text-green-600'
                                        : 'text-red-600'
                                "
                            >
                                <component
                                    :is="
                                        verificationResult.success
                                            ? CheckCircle2
                                            : XCircle
                                    "
                                    class="h-10 w-10"
                                />
                            </div>
                            <div>
                                <h2
                                    class="text-xl font-semibold"
                                    :class="
                                        verificationResult.success
                                            ? 'text-green-700'
                                            : 'text-red-700'
                                    "
                                >
                                    {{
                                        verificationResult.success
                                            ? 'Signature valide'
                                            : 'Signature invalide'
                                    }}
                                </h2>
                                <p
                                    class="text-sm text-gray-600 dark:text-gray-300"
                                >
                                    {{ verificationResult.message }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <div
                                class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    >Statut</span
                                >
                                <div class="mt-1">
                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                        :class="
                                            getStatusBadge(
                                                verificationResult.details
                                                    .signature_valid,
                                            )
                                        "
                                    >
                                        {{
                                            verificationResult.details
                                                .signature_valid
                                                ? 'Valide'
                                                : 'Invalide'
                                        }}
                                    </span>
                                </div>
                            </div>
                            <div
                                class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    >Intégrité</span
                                >
                                <div class="mt-1">
                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                        :class="
                                            getStatusBadge(
                                                verificationResult.details
                                                    .document_integrity,
                                            )
                                        "
                                    >
                                        {{
                                            verificationResult.details
                                                .document_integrity
                                                ? 'Intact'
                                                : 'Modifié'
                                        }}
                                    </span>
                                </div>
                            </div>
                            <div
                                class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    >Signataire</span
                                >
                                <div class="mt-1 text-sm font-medium">
                                    {{ verificationResult.details.signer }}
                                </div>
                            </div>
                            <div
                                class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    >Date</span
                                >
                                <div class="mt-1 text-sm">
                                    {{
                                        formatDate(
                                            verificationResult.details
                                                .signed_at,
                                        )
                                    }}
                                </div>
                            </div>
                            <div
                                class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    >Algorithme</span
                                >
                                <div class="mt-1 text-sm">
                                    {{ verificationResult.details.algorithm }}
                                </div>
                            </div>
                            <div
                                v-if="
                                    verificationResult.details.certificate_trust
                                "
                                class="rounded-lg border border-gray-200 bg-white p-3 sm:col-span-2 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    >Certificat du signataire</span
                                >
                                <div class="mt-1 text-sm">
                                    <span
                                        :class="
                                            trustInfo(
                                                verificationResult.details
                                                    .certificate_trust,
                                            ).class
                                        "
                                        class="font-medium"
                                    >
                                        {{
                                            trustInfo(
                                                verificationResult.details
                                                    .certificate_trust,
                                            ).label
                                        }}
                                    </span>
                                    <span
                                        class="text-gray-500 dark:text-gray-400"
                                    >
                                        —
                                        <a href="/pki/ca.crt" class="underline"
                                            >certificat de l'autorité</a
                                        >
                                        ·
                                        <a
                                            href="/pki/revocations.json"
                                            class="underline"
                                            >liste de révocation</a
                                        >
                                    </span>
                                </div>
                            </div>
                            <div
                                v-if="
                                    verificationResult.details.timestamp &&
                                    timestampInfo(
                                        verificationResult.details.timestamp
                                            .status,
                                    )
                                "
                                class="rounded-lg border border-gray-200 bg-white p-3 sm:col-span-2 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    >Horodatage</span
                                >
                                <div class="mt-1 text-sm">
                                    <span
                                        :class="
                                            timestampInfo(
                                                verificationResult.details
                                                    .timestamp.status,
                                            ).class
                                        "
                                        class="font-medium"
                                    >
                                        {{
                                            timestampInfo(
                                                verificationResult.details
                                                    .timestamp.status,
                                            ).label
                                        }}
                                    </span>
                                    <span
                                        v-if="
                                            verificationResult.details.timestamp
                                                .time
                                        "
                                        class="text-gray-500 dark:text-gray-400"
                                    >
                                        —
                                        {{
                                            new Date(
                                                verificationResult.details
                                                    .timestamp.time,
                                            ).toLocaleString('fr-FR')
                                        }}
                                    </span>
                                </div>
                            </div>
                            <div
                                class="rounded-lg border border-gray-200 bg-white p-3 sm:col-span-2 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                    >Empreinte</span
                                >
                                <div class="mt-1 flex items-center gap-2">
                                    <code
                                        class="flex-1 overflow-auto rounded bg-gray-100 px-2 py-1 font-mono text-xs dark:bg-gray-800"
                                    >
                                        {{
                                            verificationResult.details
                                                .fingerprint
                                        }}
                                    </code>
                                    <button
                                        @click="
                                            copyToClipboard(
                                                verificationResult.details
                                                    .fingerprint,
                                            )
                                        "
                                        class="rounded p-1 hover:bg-gray-200 dark:hover:bg-gray-700"
                                    >
                                        <Copy class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="verificationResult.details.warning"
                            class="mt-4 rounded-lg border border-yellow-200 bg-yellow-50 p-3 dark:border-yellow-800 dark:bg-yellow-950/20"
                        >
                            <div
                                class="flex items-center gap-2 text-sm text-yellow-700 dark:text-yellow-300"
                            >
                                <AlertCircle class="h-4 w-4" />
                                {{ verificationResult.details.warning }}
                            </div>
                        </div>

                        <div class="mt-6 flex justify-center gap-3">
                            <button
                                @click="resetVerification"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
                            >
                                Nouvelle vérification
                            </button>
                            <button
                                v-if="verificationResult.token"
                                @click="
                                    copyToClipboard(
                                        route('verifications.public', {
                                            token: verificationResult.token,
                                        }),
                                    )
                                "
                                class="rounded-lg bg-gray-900 px-4 py-2 text-sm text-white dark:bg-white dark:text-black"
                            >
                                <Copy class="mr-2 inline h-4 w-4" />
                                Copier le lien
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Formulaire -->
                <div
                    v-else
                    class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900"
                >
                    <!-- Méthodes -->
                    <div class="mb-6 flex gap-3">
                        <button
                            @click="verificationMethod = 'token'"
                            class="flex-1 rounded-lg border py-2 text-sm transition-colors"
                            :class="
                                verificationMethod === 'token'
                                    ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-black'
                                    : 'border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800'
                            "
                        >
                            <Eye class="mr-2 inline h-4 w-4" />
                            Par token
                        </button>
                        <button
                            @click="verificationMethod = 'upload'"
                            class="flex-1 rounded-lg border py-2 text-sm transition-colors"
                            :class="
                                verificationMethod === 'upload'
                                    ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-black'
                                    : 'border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800'
                            "
                        >
                            <Upload class="mr-2 inline h-4 w-4" />
                            Par fichier
                        </button>
                    </div>

                    <!-- Token -->
                    <div
                        v-if="verificationMethod === 'token'"
                        class="space-y-4"
                    >
                        <div>
                            <label
                                class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Token de vérification
                            </label>
                            <div class="relative">
                                <input
                                    type="text"
                                    v-model="verificationToken"
                                    placeholder="Entrez votre token"
                                    class="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2 pr-20 focus:ring-1 focus:ring-gray-900 focus:outline-none dark:border-gray-700 dark:focus:ring-white"
                                    :class="{ 'border-red-500': tokenError }"
                                    @keyup.enter="verifyByToken"
                                />
                                <button
                                    @click="verifyByToken"
                                    :disabled="
                                        !verificationToken.trim() || isVerifying
                                    "
                                    class="absolute top-1 right-1 rounded bg-gray-900 px-3 py-1 text-sm text-white disabled:opacity-50 dark:bg-white dark:text-black"
                                >
                                    Vérifier
                                </button>
                            </div>
                            <p
                                v-if="tokenError"
                                class="mt-2 text-sm text-red-600"
                            >
                                {{ tokenError }}
                            </p>
                        </div>

                        <div
                            class="rounded-lg bg-gray-50 p-4 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                        >
                            <Info class="mr-2 inline h-4 w-4 text-gray-500" />
                            Le token vous a été fourni lors de la signature du
                            document.
                        </div>
                    </div>

                    <!-- Upload -->
                    <div v-else class="space-y-4">
                        <!-- Document -->
                        <div>
                            <label
                                class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Document à vérifier
                            </label>
                            <div
                                v-if="!selectedFile"
                                class="cursor-pointer rounded-lg border-2 border-dashed p-8 text-center"
                                :class="
                                    isDragging
                                        ? 'border-gray-900 bg-gray-50 dark:border-white dark:bg-gray-800'
                                        : 'border-gray-300 hover:border-gray-400 dark:border-gray-700 dark:hover:border-gray-600'
                                "
                                @dragenter.prevent="isDragging = true"
                                @dragover.prevent="isDragging = true"
                                @dragleave.prevent="isDragging = false"
                                @drop.prevent="handleDrop($event, 'file')"
                                @click="openFileSelector('file')"
                            >
                                <Upload
                                    class="mx-auto h-10 w-10 text-gray-400"
                                />
                                <p
                                    class="mt-2 text-sm text-gray-600 dark:text-gray-300"
                                >
                                    Glissez-déposez ou
                                    <span
                                        class="font-medium text-gray-900 dark:text-white"
                                        >cliquez</span
                                    >
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    PDF, DOC, DOCX, TXT, XML, JSON (max 10MB)
                                </p>
                                <input
                                    type="file"
                                    ref="fileInput"
                                    class="hidden"
                                    @change="handleFileSelect($event, 'file')"
                                    accept=".pdf,.doc,.docx,.txt,.xml,.json"
                                />
                            </div>
                            <div
                                v-else
                                class="flex items-center justify-between rounded-lg bg-gray-50 p-3 dark:bg-gray-800"
                            >
                                <div class="flex items-center gap-3">
                                    <FileText class="h-6 w-6 text-gray-400" />
                                    <div>
                                        <p class="text-sm font-medium">
                                            {{ selectedFile.name }}
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            {{
                                                formatFileSize(
                                                    selectedFile.size,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <button
                                    @click="removeFile('file')"
                                    class="text-gray-400 hover:text-gray-600"
                                >
                                    <XCircle class="h-5 w-5" />
                                </button>
                            </div>
                        </div>

                        <!-- Signature (optionnel) -->
                        <div>
                            <label
                                class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Signature (optionnel)
                            </label>
                            <div
                                v-if="!selectedSignatureFile"
                                class="cursor-pointer rounded-lg border-2 border-dashed p-6 text-center"
                                :class="
                                    isDraggingSignature
                                        ? 'border-gray-900 bg-gray-50 dark:border-white dark:bg-gray-800'
                                        : 'border-gray-300 hover:border-gray-400 dark:border-gray-700 dark:hover:border-gray-600'
                                "
                                @dragenter.prevent="isDraggingSignature = true"
                                @dragover.prevent="isDraggingSignature = true"
                                @dragleave.prevent="isDraggingSignature = false"
                                @drop.prevent="handleDrop($event, 'signature')"
                                @click="openFileSelector('signature')"
                            >
                                <FileText
                                    class="mx-auto h-8 w-8 text-gray-400"
                                />
                                <p
                                    class="mt-1 text-sm text-gray-600 dark:text-gray-300"
                                >
                                    Ajouter un fichier .p7s ou .sig
                                </p>
                                <input
                                    type="file"
                                    ref="signatureInput"
                                    class="hidden"
                                    @change="
                                        handleFileSelect($event, 'signature')
                                    "
                                    accept=".p7s,.p7m,.sig,.signature"
                                />
                            </div>
                            <div
                                v-else
                                class="flex items-center justify-between rounded-lg bg-gray-50 p-3 dark:bg-gray-800"
                            >
                                <div class="flex items-center gap-2">
                                    <FileText class="h-5 w-5 text-gray-400" />
                                    <span class="text-sm">{{
                                        selectedSignatureFile.name
                                    }}</span>
                                </div>
                                <button
                                    @click="removeFile('signature')"
                                    class="text-gray-400 hover:text-gray-600"
                                >
                                    <XCircle class="h-4 w-4" />
                                </button>
                            </div>
                        </div>

                        <div
                            class="rounded-lg bg-yellow-50 p-3 text-sm text-yellow-700 dark:bg-yellow-950/20 dark:text-yellow-300"
                        >
                            <HelpCircle class="mr-2 inline h-4 w-4" />
                            Si vous avez une signature détachée (.p7s), vous
                            pouvez l'ajouter.
                        </div>
                    </div>

                    <!-- Progression -->
                    <div v-if="isVerifying" class="mt-4">
                        <div class="mb-1 flex justify-between text-sm">
                            <span>Vérification...</span>
                            <span>{{ verificationProgress }}%</span>
                        </div>
                        <div
                            class="h-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                        >
                            <div
                                class="h-full bg-gray-900 transition-all duration-300 dark:bg-white"
                                :style="{ width: `${verificationProgress}%` }"
                            ></div>
                        </div>
                    </div>

                    <!-- Bouton -->
                    <button
                        v-if="verificationMethod === 'upload'"
                        @click="verifyByUpload"
                        :disabled="!canVerify || isVerifying"
                        class="mt-4 w-full rounded-lg bg-gray-900 py-2 text-white disabled:opacity-50 dark:bg-white dark:text-black"
                    >
                        <Loader2
                            v-if="isVerifying"
                            class="mr-2 inline h-4 w-4 animate-spin"
                        />
                        {{
                            isVerifying
                                ? 'Vérification...'
                                : 'Vérifier la signature'
                        }}
                    </button>
                </div>

                <!-- Informations -->
                <div class="mt-12 grid gap-6 text-center sm:grid-cols-3">
                    <div>
                        <div
                            class="mb-3 inline-flex rounded-lg bg-gray-100 p-3 dark:bg-gray-800"
                        >
                            <Lock
                                class="h-5 w-5 text-gray-700 dark:text-gray-300"
                            />
                        </div>
                        <h3 class="font-medium text-gray-900 dark:text-white">
                            Sécurisé
                        </h3>
                        <p
                            class="mt-1 text-sm text-gray-500 dark:text-gray-400"
                        >
                            Vérification cryptographique
                        </p>
                    </div>
                    <div>
                        <div
                            class="mb-3 inline-flex rounded-lg bg-gray-100 p-3 dark:bg-gray-800"
                        >
                            <Globe
                                class="h-5 w-5 text-gray-700 dark:text-gray-300"
                            />
                        </div>
                        <h3 class="font-medium text-gray-900 dark:text-white">
                            Public
                        </h3>
                        <p
                            class="mt-1 text-sm text-gray-500 dark:text-gray-400"
                        >
                            Sans création de compte
                        </p>
                    </div>
                    <div>
                        <div
                            class="mb-3 inline-flex rounded-lg bg-gray-100 p-3 dark:bg-gray-800"
                        >
                            <FileText
                                class="h-5 w-5 text-gray-700 dark:text-gray-300"
                            />
                        </div>
                        <h3 class="font-medium text-gray-900 dark:text-white">
                            Multi-format
                        </h3>
                        <p
                            class="mt-1 text-sm text-gray-500 dark:text-gray-400"
                        >
                            Tous types de fichiers
                        </p>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer
            class="mt-auto border-t border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900"
        >
            <div
                class="mx-auto max-w-7xl px-4 py-8 text-center text-sm text-gray-600 dark:text-gray-400"
            >
                © {{ new Date().getFullYear() }} Cryptor — Vérification publique
                de signatures numériques.
            </div>
        </footer>
    </div>
</template>
