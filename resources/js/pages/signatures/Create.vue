<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    FileSignature,
    Upload,
    Shield,
    KeyRound,
    MapPin,
    FileText,
    CheckCircle2,
    X,
    Info,
    Loader2,
    HelpCircle,
    File,
    Image,
    Archive,
    Code,
    Database,
    FileSpreadsheet,
} from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    CardFooter,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

interface KeyPair {
    id: number;
    key_name: string;
    algorithm_used: string;
    key_size: number;
    is_default: boolean;
    fingerprint: string;
}

const props = defineProps<{
    keys: KeyPair[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Signatures',
        href: route('signatures.index'),
    },
    {
        title: 'Sign Document',
        href: route('signatures.create'),
    },
];

const form = useForm({
    file: null as File | null,
    key_pair_id: props.keys.find((k) => k.is_default)?.id || '',
    signing_reason: '',
    signing_location: '',
    contact_info: '',
    signature_format: '',
    include_visual: false,
    expires_in_days: 365,
});

const selectedFile = ref<File | null>(null);
const isDragging = ref(false);
const uploadProgress = ref(0);
const isUploading = ref(false);
const showFormatGuide = ref(false);
const recommendedFormat = ref<string | null>(null);

// Types de fichiers et formats recommandés
const fileTypeCategories = {
    document: {
        extensions: ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt'],
        icon: FileText,
        description: 'Documents texte, contrats, rapports',
        recommended: 'cms',
        formats: ['cms', 'pdf'],
    },
    image: {
        extensions: ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp'],
        icon: Image,
        description: 'Images et photographies',
        recommended: 'cms',
        formats: ['cms'],
    },
    archive: {
        extensions: ['zip', 'tar', 'gz', '7z', 'rar'],
        icon: Archive,
        description: 'Fichiers compressés',
        recommended: 'cms',
        formats: ['cms'],
    },
    code: {
        extensions: [
            'xml',
            'json',
            'yml',
            'yaml',
            'js',
            'ts',
            'php',
            'py',
            'java',
            'cpp',
            'c',
            'h',
        ],
        icon: Code,
        description: 'Code source, fichiers de configuration',
        recommended: 'cms',
        formats: ['cms'],
    },
    executable: {
        extensions: ['exe', 'msi', 'deb', 'rpm', 'apk', 'appimage'],
        icon: File,
        description: 'Fichiers exécutables, installateurs',
        recommended: 'cms',
        formats: ['cms'],
    },
    database: {
        extensions: ['sql', 'db', 'sqlite', 'mdb'],
        icon: Database,
        description: 'Bases de données, scripts SQL',
        recommended: 'cms',
        formats: ['cms'],
    },
    spreadsheet: {
        extensions: ['xls', 'xlsx', 'ods', 'csv'],
        icon: FileSpreadsheet,
        description: 'Tableurs, données structurées',
        recommended: 'cms',
        formats: ['cms'],
    },
};

// Options de format avec descriptions détaillées
const formatOptions = [
    // {
    //     value: 'pkcs7',
    //     label: 'PKCS#7',
    //     description: 'Format standard pour signatures détachées',
    //     longDescription: 'Idéal pour tous types de fichiers. La signature est séparée du document original.',
    //     useCases: 'Contrats, documents légaux, archives, tout fichier nécessitant une signature indépendante',
    //     icon: FileSignature,
    //     compatibility: 'Très large - compatible avec la plupart des outils de signature'
    // },
    {
        value: 'cms',
        label: 'CMS',
        description: 'Cryptographie avancée',
        longDescription:
            "Format européen standard, basé sur PKCS#7 avec plus d'options",
        useCases:
            'Documents sensibles, transactions sécurisées, échanges inter-administrations',
        icon: Shield,
        compatibility:
            'Large - utilisé dans les systèmes gouvernementaux européens',
    },
    {
        value: 'pdf',
        label: 'PAdES',
        description: 'Signature intégrée au PDF',
        longDescription: 'Signature visible directement dans le document PDF',
        useCases:
            'Documents PDF à visualiser, factures électroniques, formulaires officiels',
        icon: FileText,
        compatibility: 'Spécifique PDF - nécessite un lecteur PDF compatible',
    },
];

const expirationOptions = [
    { value: 30, label: '30 days' },
    { value: 90, label: '90 days' },
    { value: 180, label: '6 months' },
    { value: 365, label: '1 year' },
    { value: 730, label: '2 years' },
    { value: 0, label: 'Never expires' },
];

// Détection du type de fichier
const getFileCategory = (filename: string) => {
    const extension = filename.split('.').pop()?.toLowerCase() || '';

    for (const [category, info] of Object.entries(fileTypeCategories)) {
        if (info.extensions.includes(extension)) {
            return { category, ...info };
        }
    }

    // Type par défaut
    return {
        category: 'other',
        icon: File,
        description: 'Type de fichier générique',
        recommended: 'cms',
        formats: ['cms'],
    };
};

// Met à jour le format recommandé quand un fichier est sélectionné
watch(selectedFile, (newFile) => {
    if (newFile) {
        const category = getFileCategory(newFile.name);
        recommendedFormat.value = category.recommended;

        // Pré-sélectionner le format recommandé si aucun n'est choisi
        if (!form.signature_format) {
            form.signature_format = category.recommended;
        }
    } else {
        recommendedFormat.value = null;
    }
});

const handleFileSelect = (event: Event) => {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        const file = input.files[0];
        selectedFile.value = file;
        form.file = file as any;
    }
};

const handleDrop = (event: DragEvent) => {
    event.preventDefault();
    isDragging.value = false;

    if (event.dataTransfer?.files && event.dataTransfer.files[0]) {
        const file = event.dataTransfer.files[0];
        selectedFile.value = file;
        form.file = file as any;
    }
};

const removeFile = () => {
    selectedFile.value = null;
    form.file = null;
    uploadProgress.value = 0;
    form.signature_format = '';
};

const submit = () => {
    form.post(route('signatures.store'), {
        onSuccess: () => {
            form.reset();
            selectedFile.value = null;
        },
    });
};

const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const getAlgorithmColor = (algorithm: string) => {
    const colors: Record<string, string> = {
        rsa: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        ecdsa: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
        ed25519:
            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    };
    return (
        colors[algorithm.toLowerCase()] ||
        'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
    );
};

const fileCategoryIcon = computed(() => {
    if (!selectedFile.value) return File;
    const category = getFileCategory(selectedFile.value.name);
    return category.icon;
});
</script>

<template>
    <Head title="Sign Document" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">
                        Sign Document
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Apply your digital signature to a document
                    </p>
                </div>
                <Button variant="outline" as-child>
                    <Link :href="route('signatures.index')">
                        <X class="mr-2 h-4 w-4" />
                        Cancel
                    </Link>
                </Button>
            </div>

            <!-- Main Content -->
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Two Column Layout -->
                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Left Column - File Upload & Signing Details -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- File Upload Card -->
                        <Card>
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2">
                                    <FileText class="h-5 w-5" />
                                    Document to Sign
                                </CardTitle>
                                <CardDescription>
                                    Upload the file you want to digitally sign
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div
                                    v-if="!selectedFile"
                                    class="rounded-lg border-2 border-dashed p-8 transition-colors"
                                    :class="{
                                        'border-primary bg-primary/5':
                                            isDragging,
                                        'border-border hover:border-muted-foreground/50':
                                            !isDragging,
                                    }"
                                    @dragenter.prevent="isDragging = true"
                                    @dragover.prevent="isDragging = true"
                                    @dragleave.prevent="isDragging = false"
                                    @drop.prevent="handleDrop"
                                >
                                    <div
                                        class="flex flex-col items-center justify-center text-center"
                                    >
                                        <div
                                            class="mb-4 rounded-full bg-primary/10 p-3"
                                        >
                                            <Upload
                                                class="h-6 w-6 text-primary"
                                            />
                                        </div>
                                        <h3 class="mb-1 text-lg font-semibold">
                                            Drop your file here
                                        </h3>
                                        <p
                                            class="mb-4 text-sm text-muted-foreground"
                                        >
                                            or click to browse from your
                                            computer
                                        </p>
                                        <Input
                                            type="file"
                                            class="hidden"
                                            id="file-upload"
                                            @change="handleFileSelect"
                                            accept=".pdf,.doc,.docx,.txt,.xml,.json,.p7m,.zip,.tar,.gz,.exe,.deb,.sql,.jpg,.png,.xls,.xlsx"
                                        />
                                        <Button variant="outline" as-child>
                                            <label
                                                for="file-upload"
                                                class="cursor-pointer"
                                            >
                                                Choose File
                                            </label>
                                        </Button>
                                        <p
                                            class="mt-4 text-xs text-muted-foreground"
                                        >
                                            Supported: PDF, DOC, DOCX, TXT, XML,
                                            JSON, ZIP, EXE, DEB, SQL, Images,
                                            and more
                                        </p>
                                    </div>
                                </div>

                                <!-- File Preview -->
                                <div v-else class="space-y-4">
                                    <div
                                        class="flex items-start justify-between rounded-lg bg-muted/50 p-4"
                                    >
                                        <div class="flex items-start gap-3">
                                            <div
                                                class="rounded bg-primary/10 p-2"
                                            >
                                                <component
                                                    :is="fileCategoryIcon"
                                                    class="h-5 w-5 text-primary"
                                                />
                                            </div>
                                            <div>
                                                <h4 class="font-medium">
                                                    {{ selectedFile.name }}
                                                </h4>
                                                <div
                                                    class="mt-1 flex items-center gap-2"
                                                >
                                                    <span
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        {{
                                                            formatFileSize(
                                                                selectedFile.size,
                                                            )
                                                        }}
                                                    </span>
                                                    <span
                                                        class="text-xs text-muted-foreground"
                                                        >•</span
                                                    >
                                                    <span
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        {{
                                                            selectedFile.type ||
                                                            getFileCategory(
                                                                selectedFile.name,
                                                            ).description
                                                        }}
                                                    </span>
                                                </div>
                                                <!-- Recommendation badge -->
                                                <Badge
                                                    v-if="recommendedFormat"
                                                    variant="outline"
                                                    class="mt-2 text-xs"
                                                >
                                                    <CheckCircle2
                                                        class="mr-1 h-3 w-3 text-green-600"
                                                    />
                                                    Recommended:
                                                    {{
                                                        formatOptions.find(
                                                            (f) =>
                                                                f.value ===
                                                                recommendedFormat,
                                                        )?.label
                                                    }}
                                                </Badge>
                                            </div>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            @click="removeFile"
                                        >
                                            <X class="h-4 w-4" />
                                        </Button>
                                    </div>

                                    <!-- Upload Progress -->
                                    <div v-if="isUploading" class="space-y-2">
                                        <div
                                            class="flex justify-between text-sm"
                                        >
                                            <span>Uploading...</span>
                                            <span>{{ uploadProgress }}%</span>
                                        </div>
                                        <div
                                            class="h-2 overflow-hidden rounded-full bg-muted"
                                        >
                                            <div
                                                class="h-full bg-primary transition-all duration-300"
                                                :style="{
                                                    width: `${uploadProgress}%`,
                                                }"
                                            ></div>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Signing Details Card -->
                        <Card v-if="selectedFile">
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2">
                                    <FileSignature class="h-5 w-5" />
                                    Signature Details
                                </CardTitle>
                                <CardDescription>
                                    Add context information to your signature
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <!-- Signing Reason -->
                                <div class="space-y-2">
                                    <Label for="signing_reason"
                                        >Reason for signing</Label
                                    >
                                    <Textarea
                                        id="signing_reason"
                                        v-model="form.signing_reason"
                                        placeholder="e.g., I approve this contract, I certify this document..."
                                        rows="3"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Optional: Explain why you're signing
                                        this document
                                    </p>
                                </div>

                                <!-- Location & Contact -->
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <Label for="signing_location"
                                            >Location</Label
                                        >
                                        <div class="relative">
                                            <MapPin
                                                class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                                            />
                                            <Input
                                                id="signing_location"
                                                v-model="form.signing_location"
                                                placeholder="City, Country"
                                                class="pl-9"
                                            />
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <Label for="contact_info"
                                            >Contact information</Label
                                        >
                                        <Input
                                            id="contact_info"
                                            v-model="form.contact_info"
                                            placeholder="Email or phone"
                                        />
                                    </div>
                                </div>

                                <!-- Signature Format avec guide -->
                                <div class="space-y-4">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <Label for="signature_format"
                                            >Signature format</Label
                                        >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="gap-1"
                                            @click="
                                                showFormatGuide =
                                                    !showFormatGuide
                                            "
                                        >
                                            <HelpCircle class="h-4 w-4" />
                                            {{
                                                showFormatGuide
                                                    ? 'Hide guide'
                                                    : 'Show format guide'
                                            }}
                                        </Button>
                                    </div>

                                    <!-- Format Guide -->
                                    <div
                                        v-if="showFormatGuide"
                                        class="space-y-3 rounded-lg bg-muted/50 p-4"
                                    >
                                        <h4 class="text-sm font-medium">
                                            Choose the right format:
                                        </h4>

                                        <div
                                            v-for="format in formatOptions"
                                            :key="format.value"
                                            class="flex items-start gap-3 rounded-lg border bg-background p-3"
                                            :class="{
                                                'border-primary':
                                                    recommendedFormat ===
                                                    format.value,
                                            }"
                                        >
                                            <div
                                                class="shrink-0 rounded bg-primary/10 p-2"
                                            >
                                                <component
                                                    :is="format.icon"
                                                    class="h-4 w-4 text-primary"
                                                />
                                            </div>
                                            <div class="flex-1">
                                                <div
                                                    class="flex items-center gap-2"
                                                >
                                                    <span class="font-medium">{{
                                                        format.label
                                                    }}</span>
                                                    <Badge
                                                        v-if="
                                                            recommendedFormat ===
                                                            format.value
                                                        "
                                                        variant="default"
                                                        class="text-xs"
                                                    >
                                                        Recommended for this
                                                        file
                                                    </Badge>
                                                </div>
                                                <p
                                                    class="mt-1 text-sm text-muted-foreground"
                                                >
                                                    {{ format.longDescription }}
                                                </p>
                                                <p
                                                    class="mt-2 text-xs text-muted-foreground"
                                                >
                                                    <span class="font-medium"
                                                        >Best for:</span
                                                    >
                                                    {{ format.useCases }}
                                                </p>
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    <span class="font-medium"
                                                        >Compatibility:</span
                                                    >
                                                    {{ format.compatibility }}
                                                </p>
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                @click="
                                                    form.signature_format =
                                                        format.value
                                                "
                                                :disabled="
                                                    form.signature_format ===
                                                    format.value
                                                "
                                            >
                                                {{
                                                    form.signature_format ===
                                                    format.value
                                                        ? 'Selected'
                                                        : 'Select'
                                                }}
                                            </Button>
                                        </div>
                                    </div>

                                    <!-- Format Selection -->
                                    <Select v-model="form.signature_format">
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Select a format"
                                            >
                                                <template
                                                    v-if="form.signature_format"
                                                >
                                                    <div
                                                        class="flex items-center gap-2"
                                                    >
                                                        <component
                                                            :is="
                                                                formatOptions.find(
                                                                    (f) =>
                                                                        f.value ===
                                                                        form.signature_format,
                                                                )?.icon
                                                            "
                                                            class="h-4 w-4"
                                                        />
                                                        {{
                                                            formatOptions.find(
                                                                (f) =>
                                                                    f.value ===
                                                                    form.signature_format,
                                                            )?.label
                                                        }}
                                                    </div>
                                                </template>
                                            </SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="format in formatOptions"
                                                :key="format.value"
                                                :value="format.value"
                                                class="py-3"
                                            >
                                                <div
                                                    class="flex items-start gap-3"
                                                >
                                                    <component
                                                        :is="format.icon"
                                                        class="mt-1 h-4 w-4 shrink-0"
                                                    />
                                                    <div>
                                                        <div
                                                            class="font-medium"
                                                        >
                                                            {{ format.label }}
                                                        </div>
                                                        <p
                                                            class="text-xs text-muted-foreground"
                                                        >
                                                            {{
                                                                format.description
                                                            }}
                                                        </p>
                                                    </div>
                                                </div>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>

                                    <!-- Format Info Tooltip -->
                                    <HoverCard v-if="form.signature_format">
                                        <HoverCardTrigger as-child>
                                            <Button
                                                variant="link"
                                                size="sm"
                                                class="gap-1"
                                            >
                                                <Info class="h-4 w-4" />
                                                About this format
                                            </Button>
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                            <div class="space-y-2">
                                                <h4 class="font-medium">
                                                    {{
                                                        formatOptions.find(
                                                            (f) =>
                                                                f.value ===
                                                                form.signature_format,
                                                        )?.label
                                                    }}
                                                </h4>
                                                <p class="text-sm">
                                                    {{
                                                        formatOptions.find(
                                                            (f) =>
                                                                f.value ===
                                                                form.signature_format,
                                                        )?.longDescription
                                                    }}
                                                </p>
                                                <p
                                                    class="mt-2 text-sm font-medium"
                                                >
                                                    Use cases:
                                                </p>
                                                <p class="text-sm">
                                                    {{
                                                        formatOptions.find(
                                                            (f) =>
                                                                f.value ===
                                                                form.signature_format,
                                                        )?.useCases
                                                    }}
                                                </p>
                                            </div>
                                        </HoverCardContent>
                                    </HoverCard>
                                </div>

                                <!-- Expiration & Visual Options -->
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <Label for="expires_in_days"
                                            >Valid for</Label
                                        >
                                        <Select v-model="form.expires_in_days">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select expiration"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="option in expirationOptions"
                                                    :key="option.value"
                                                    :value="option.value"
                                                >
                                                    {{ option.label }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <!-- Visual Signature Option (PDF only) -->
                                    <div
                                        v-if="
                                            selectedFile?.name
                                                .toLowerCase()
                                                .endsWith('.pdf')
                                        "
                                        class="flex items-center space-x-2 pt-8"
                                    >
                                        <Checkbox
                                            id="include_visual"
                                            v-model:checked="
                                                form.include_visual
                                            "
                                        />
                                        <Label
                                            for="include_visual"
                                            class="cursor-pointer text-sm font-normal"
                                        >
                                            Include visual signature
                                        </Label>
                                        <TooltipProvider>
                                            <Tooltip>
                                                <TooltipTrigger>
                                                    <Info
                                                        class="h-4 w-4 text-muted-foreground"
                                                    />
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p class="max-w-xs">
                                                        Adds a visible signature
                                                        block to the PDF
                                                        document
                                                    </p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Right Column - Key Selection & Summary (identique) -->
                    <div class="space-y-6">
                        <!-- Key Selection Card -->
                        <Card>
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2">
                                    <KeyRound class="h-5 w-5" />
                                    Signing Key
                                </CardTitle>
                                <CardDescription>
                                    Select the key pair to use
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div
                                    v-if="keys.length === 0"
                                    class="py-6 text-center"
                                >
                                    <div
                                        class="mb-3 inline-block rounded-full bg-muted p-3"
                                    >
                                        <KeyRound
                                            class="h-6 w-6 text-muted-foreground"
                                        />
                                    </div>
                                    <h3 class="mb-1 font-medium">
                                        No keys available
                                    </h3>
                                    <p
                                        class="mb-4 text-sm text-muted-foreground"
                                    >
                                        You need to generate a key pair first
                                    </p>
                                    <Button as-child>
                                        <Link :href="route('keys.create')">
                                            Generate Key
                                        </Link>
                                    </Button>
                                </div>

                                <div v-else class="space-y-3">
                                    <div
                                        v-for="key in keys"
                                        :key="key.id"
                                        class="relative flex cursor-pointer items-start rounded-lg border p-3 transition-colors"
                                        :class="{
                                            'border-primary bg-primary/5':
                                                form.key_pair_id === key.id,
                                            'border-border hover:border-muted-foreground/50':
                                                form.key_pair_id !== key.id,
                                        }"
                                        @click="form.key_pair_id = key.id"
                                    >
                                        <div class="flex-1">
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span class="font-medium">{{
                                                    key.key_name
                                                }}</span>
                                                <Badge
                                                    v-if="key.is_default"
                                                    variant="outline"
                                                    class="text-xs"
                                                >
                                                    Default
                                                </Badge>
                                            </div>
                                            <div
                                                class="mt-1 flex items-center gap-2"
                                            >
                                                <Badge
                                                    :class="
                                                        getAlgorithmColor(
                                                            key.algorithm_used,
                                                        )
                                                    "
                                                >
                                                    {{ key.algorithm_used }}
                                                    {{ key.key_size }}
                                                </Badge>
                                                <span
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{
                                                        key.fingerprint?.substring(
                                                            0,
                                                            12,
                                                        )
                                                    }}...
                                                </span>
                                            </div>
                                        </div>
                                        <div class="absolute top-3 right-3">
                                            <div
                                                v-if="
                                                    form.key_pair_id === key.id
                                                "
                                                class="flex h-5 w-5 items-center justify-center rounded-full bg-primary"
                                            >
                                                <CheckCircle2
                                                    class="h-3 w-3 text-white"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                            <CardFooter
                                v-if="keys.length > 0"
                                class="border-t pt-6"
                            >
                                <Button
                                    variant="outline"
                                    as-child
                                    class="w-full"
                                >
                                    <Link :href="route('keys.create')">
                                        + Generate New Key
                                    </Link>
                                </Button>
                            </CardFooter>
                        </Card>

                        <!-- Summary Card -->
                        <Card v-if="selectedFile && form.key_pair_id">
                            <CardHeader>
                                <CardTitle>Signature Summary</CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <span class="text-muted-foreground"
                                        >Document:</span
                                    >
                                    <span
                                        class="max-w-[150px] truncate font-medium"
                                        >{{ selectedFile.name }}</span
                                    >
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-muted-foreground"
                                        >Signing key:</span
                                    >
                                    <span class="font-medium">{{
                                        keys.find(
                                            (k) => k.id === form.key_pair_id,
                                        )?.key_name
                                    }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-muted-foreground"
                                        >Format:</span
                                    >
                                    <span class="font-medium">{{
                                        formatOptions.find(
                                            (f) =>
                                                f.value ===
                                                form.signature_format,
                                        )?.label
                                    }}</span>
                                </div>
                                <div
                                    v-if="form.signature_format === 'pdf'"
                                    class="flex justify-between text-sm"
                                >
                                    <span class="text-muted-foreground"
                                        >Visual signature:</span
                                    >
                                    <span class="font-medium">{{
                                        form.include_visual ? 'Yes' : 'No'
                                    }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-muted-foreground"
                                        >Expires:</span
                                    >
                                    <span class="font-medium">
                                        {{
                                            form.expires_in_days === 0
                                                ? 'Never'
                                                : `in ${form.expires_in_days} days`
                                        }}
                                    </span>
                                </div>
                                <div
                                    v-if="form.signing_location"
                                    class="flex justify-between text-sm"
                                >
                                    <span class="text-muted-foreground"
                                        >Location:</span
                                    >
                                    <span class="font-medium">{{
                                        form.signing_location
                                    }}</span>
                                </div>
                            </CardContent>
                            <CardFooter>
                                <Button
                                    type="submit"
                                    class="w-full"
                                    :disabled="
                                        form.processing ||
                                        !selectedFile ||
                                        !form.key_pair_id ||
                                        !form.signature_format
                                    "
                                >
                                    <Loader2
                                        v-if="form.processing"
                                        class="mr-2 h-4 w-4 animate-spin"
                                    />
                                    <FileSignature
                                        v-else
                                        class="mr-2 h-4 w-4"
                                    />
                                    {{
                                        form.processing
                                            ? 'Signing...'
                                            : 'Sign Document'
                                    }}
                                </Button>
                            </CardFooter>
                        </Card>

                        <!-- Info Alert -->
                        <Alert v-if="!selectedFile">
                            <Info class="h-4 w-4" />
                            <AlertTitle>Ready to sign</AlertTitle>
                            <AlertDescription>
                                Upload a document to start the signing process.
                                We'll recommend the best format for your file.
                            </AlertDescription>
                        </Alert>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
