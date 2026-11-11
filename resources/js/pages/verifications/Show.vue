<!-- resources/js/pages/verifications/Show.vue -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    ShieldCheck,
    FileText,
    CheckCircle2,
    XCircle,
    Clock,
    Globe,
    User,
    Copy,
    ChevronLeft,
    Calendar,
    Fingerprint,
    Hash,
    AlertCircle,
    Info,
    Eye,
    KeyRound,
    Smartphone,
    History,
    QrCode,
} from 'lucide-vue-next';
import { ref, computed } from 'vue';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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

// Interface pour Signature basée sur la migration
interface Signature {
    id: number;
    file_id: number;
    key_pair_id: number;
    signer_id: number;
    signature_value: string;
    signature_format: 'cms' | 'CAdES' | 'PAdES' | 'pdf';
    algorithm_used: string;
    signing_reason: string | null;
    signing_location: string | null;
    contact_info: string | null;
    visual_signature_data: string | null;
    is_valid: boolean;
    validation_status: 'pending' | 'valid' | 'invalid' | 'expired' | 'revoked';
    validation_message: string | null;
    nonce_value: string | null;
    timestamp_token: string | null;
    signing_ip: string | null;
    signing_device_info: string | null;
    signed_at: string;
    validated_at: string | null;
    revoked_at: string | null;
    revocation_reason: string | null;
    created_at: string;
    updated_at: string;
    // Relations
    file?: {
        id: number;
        original_file_name: string;
        file_name: string;
        file_path: string;
        file_hash: string;
        file_size: number;
        mime_type: string;
        file_extension: string;
        uploader_id: number;
        created_at: string;
    };
    signer?: {
        id: number;
        name: string;
        email: string;
    };
    keyPair?: {
        id: number;
        key_name: string;
        algorithm_used: string;
        key_size: number;
        public_key: string;
        fingerprint?: string;
    };
}

// Interface pour Verification basée sur la migration
interface Verification {
    id: number;
    signature_id: number;
    verifier_id: number | null;
    verified_hash: string;
    expected_hash: string;
    result: boolean;
    verification_type:
        | 'hash'
        | 'signature'
        | 'certificate'
        | 'timestamp'
        | 'auto'
        | 'manual';
    verification_details: any | null; // JSON field
    error_message: string | null;
    signature_timestamp: string | null;
    document_timestamp: string | null;
    verifier_ip: string;
    verifier_user_agent: string | null;
    verified_at: string;
    verification_duration_ms: number | null;
    certificate_chain: string | null;
    timestamp_authority_used: string | null;
    verification_token: string | null;
    is_public_verification: boolean;
    created_at: string;
    updated_at: string;
    // Relations
    signature?: Signature;
    verifier?: {
        id: number;
        name: string;
        email: string;
    } | null;
}

const props = defineProps<{
    verification: Verification;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Verification',
        href: '#',
    },
    {
        title: 'History',
        href: route('verifications.index'),
    },
    {
        title: `Verification #${props.verification.id}`,
        href: route('verifications.show', props.verification.id),
    },
];

const activeTab = ref('overview');

const copyToClipboard = (text: string) => {
    if (text) {
        navigator.clipboard.writeText(text);
    }
};

const formatDate = (date: string | null | undefined) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleString();
};

const formatDuration = (ms: number | null | undefined) => {
    if (!ms) return 'N/A';
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(2)}s`;
};

const formatFileSize = (bytes: number | null | undefined) => {
    if (!bytes || bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const getResultConfig = (result: boolean) => {
    return result
        ? {
              color: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
              icon: CheckCircle2,
              label: 'Valid',
          }
        : {
              color: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
              icon: XCircle,
              label: 'Invalid',
          };
};

const getTypeIcon = (type: string | undefined, isPublic: boolean) => {
    if (isPublic) return Globe;
    switch (type) {
        case 'hash':
            return Hash;
        case 'signature':
            return ShieldCheck;
        case 'certificate':
            return KeyRound;
        case 'timestamp':
            return Clock;
        case 'auto':
            return Smartphone;
        case 'manual':
            return User;
        default:
            return ShieldCheck;
    }
};

const getTypeLabel = (type: string | undefined, isPublic: boolean) => {
    if (isPublic) return 'Public';
    switch (type) {
        case 'hash':
            return 'Hash';
        case 'signature':
            return 'Signature';
        case 'certificate':
            return 'Certificate';
        case 'timestamp':
            return 'Timestamp';
        case 'auto':
            return 'Auto';
        case 'manual':
            return 'Manual';
        default:
            return type || 'Unknown';
    }
};

const resultConfig = computed(() => getResultConfig(props.verification.result));
const typeIcon = computed(() =>
    getTypeIcon(
        props.verification.verification_type,
        props.verification.is_public_verification,
    ),
);
const typeLabel = computed(() =>
    getTypeLabel(
        props.verification.verification_type,
        props.verification.is_public_verification,
    ),
);

// Valeurs sécurisées avec fallback
const safeSignature = computed(() => {
    return (
        props.verification.signature || {
            id: 0,
            file_id: 0,
            key_pair_id: 0,
            signer_id: 0,
            signature_value: '',
            signature_format: 'cms' as const,
            algorithm_used: 'Unknown',
            signing_reason: null,
            signing_location: null,
            contact_info: null,
            visual_signature_data: null,
            is_valid: false,
            validation_status: 'pending' as const,
            validation_message: null,
            nonce_value: null,
            timestamp_token: null,
            signing_ip: null,
            signing_device_info: null,
            signed_at: '',
            validated_at: null,
            revoked_at: null,
            revocation_reason: null,
            created_at: '',
            updated_at: '',
            file: {
                id: 0,
                original_file_name: 'Unknown',
                file_name: 'Unknown',
                file_path: '',
                file_hash: '',
                file_size: 0,
                mime_type: 'Unknown',
                file_extension: '',
                uploader_id: 0,
                created_at: '',
            },
            signer: {
                id: 0,
                name: 'Unknown',
                email: 'Unknown',
            },
            keyPair: {
                id: 0,
                key_name: 'Unknown',
                algorithm_used: 'Unknown',
                key_size: 0,
                public_key: '',
            },
        }
    );
});

const safeVerificationDetails = computed(() => {
    // Parser le JSON des détails de vérification si nécessaire
    let details = props.verification.verification_details;
    if (typeof details === 'string') {
        try {
            details = JSON.parse(details);
        } catch {
            details = {};
        }
    }
    return details || {};
});

const safeVerifiedHash = computed(
    () => props.verification.verified_hash || 'N/A',
);
const safeExpectedHash = computed(
    () => props.verification.expected_hash || 'N/A',
);
const safeErrorMessage = computed(() => props.verification.error_message || '');
const safeToken = computed(() => props.verification.verification_token || '');
const safeCertificateChain = computed(
    () => props.verification.certificate_chain || '',
);
const safeTimestampAuthority = computed(
    () => props.verification.timestamp_authority_used || 'N/A',
);

// Générer le contenu pour le QR code
const qrCodeContent = computed(() => {
    if (safeToken.value) {
        return route('verifications.public', safeToken.value);
    }
    return route('verifications.show', props.verification.id);
});
</script>

<template>
    <Head :title="`Verification #${verification.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header avec navigation -->
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" as-child>
                    <Link :href="route('verifications.index')">
                        <ChevronLeft class="h-4 w-4" />
                    </Link>
                </Button>
                <div class="flex-1">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold tracking-tight">
                            Verification Details
                        </h1>
                        <Badge :class="resultConfig.color" class="text-sm">
                            <component
                                :is="resultConfig.icon"
                                class="mr-1 h-3 w-3"
                            />
                            {{ resultConfig.label }}
                        </Badge>
                        <Badge
                            v-if="verification.is_public_verification"
                            variant="outline"
                            class="text-sm"
                        >
                            <Globe class="mr-1 h-3 w-3" />
                            Public
                        </Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Verification performed on
                        {{ formatDate(verification.verified_at) }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <Dialog>
                        <DialogTrigger as-child>
                            <Button variant="outline">
                                <QrCode class="mr-2 h-4 w-4" />
                                QR Code
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Verification QR Code</DialogTitle>
                                <DialogDescription>
                                    Scan this code to access the verification
                                    result
                                </DialogDescription>
                            </DialogHeader>
                            <div class="flex justify-center p-4">
                                <div
                                    class="rounded-lg bg-muted p-4 text-center"
                                >
                                    <QrCode
                                        class="mx-auto h-48 w-48 text-primary"
                                    />
                                    <p
                                        class="mt-2 max-w-[300px] text-xs break-all text-muted-foreground"
                                    >
                                        {{ qrCodeContent }}
                                    </p>
                                </div>
                            </div>
                        </DialogContent>
                    </Dialog>

                    <Button
                        v-if="safeToken"
                        variant="outline"
                        @click="
                            copyToClipboard(
                                route('verifications.public', safeToken),
                            )
                        "
                    >
                        <Copy class="mr-2 h-4 w-4" />
                        Copy Link
                    </Button>

                    <Button as-child>
                        <Link :href="route('verifications.create')">
                            <ShieldCheck class="mr-2 h-4 w-4" />
                            New Verification
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Alert pour les erreurs -->
            <Alert v-if="safeErrorMessage" variant="destructive">
                <AlertCircle class="h-4 w-4" />
                <AlertTitle>Verification Error</AlertTitle>
                <AlertDescription>
                    {{ safeErrorMessage }}
                </AlertDescription>
            </Alert>

            <!-- Main Content Tabs -->
            <Tabs v-model="activeTab" class="space-y-6">
                <TabsList class="grid w-full grid-cols-3 lg:w-[400px]">
                    <TabsTrigger value="overview">
                        <Info class="mr-2 h-4 w-4" />
                        Overview
                    </TabsTrigger>
                    <TabsTrigger value="details">
                        <FileText class="mr-2 h-4 w-4" />
                        Details
                    </TabsTrigger>
                    <TabsTrigger value="raw">
                        <Hash class="mr-2 h-4 w-4" />
                        Raw Data
                    </TabsTrigger>
                </TabsList>

                <!-- Overview Tab -->
                <TabsContent value="overview" class="space-y-6">
                    <!-- Résultat principal -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <component
                                    :is="resultConfig.icon"
                                    :class="resultConfig.color"
                                    class="h-5 w-5 rounded p-0.5"
                                />
                                Verification Result
                            </CardTitle>
                            <CardDescription>
                                Overall status and key information
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div class="grid gap-6 md:grid-cols-2">
                                <div class="space-y-4">
                                    <div
                                        class="flex items-start gap-3 rounded-lg bg-muted/50 p-3"
                                    >
                                        <component
                                            :is="resultConfig.icon"
                                            class="h-8 w-8"
                                            :class="resultConfig.color"
                                        />
                                        <div>
                                            <div class="text-lg font-medium">
                                                {{ resultConfig.label }}
                                            </div>
                                            <div
                                                class="text-sm text-muted-foreground"
                                            >
                                                {{
                                                    resultConfig.label ===
                                                    'Valid'
                                                        ? 'The signature is cryptographically valid'
                                                        : 'The signature could not be verified'
                                                }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="rounded-lg bg-muted/30 p-3">
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                Verification Type
                                            </div>
                                            <div
                                                class="mt-1 flex items-center gap-1"
                                            >
                                                <component
                                                    :is="typeIcon"
                                                    class="h-4 w-4"
                                                />
                                                <span class="text-sm">{{
                                                    typeLabel
                                                }}</span>
                                            </div>
                                        </div>
                                        <div class="rounded-lg bg-muted/30 p-3">
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                Duration
                                            </div>
                                            <div class="mt-1 text-sm">
                                                {{
                                                    formatDuration(
                                                        verification.verification_duration_ms,
                                                    )
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-3">
                                    <div class="flex items-center gap-2">
                                        <User
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span class="text-sm"
                                            ><span class="text-muted-foreground"
                                                >Signer:</span
                                            >
                                            {{
                                                safeSignature.signer?.name ||
                                                'Unknown'
                                            }}</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Calendar
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span class="text-sm"
                                            ><span class="text-muted-foreground"
                                                >Signed at:</span
                                            >
                                            {{
                                                formatDate(
                                                    safeSignature.signed_at,
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <ShieldCheck
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span class="text-sm"
                                            ><span class="text-muted-foreground"
                                                >Algorithm:</span
                                            >
                                            {{
                                                safeSignature.algorithm_used ||
                                                'Unknown'
                                            }}</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Fingerprint
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span class="text-sm"
                                            ><span class="text-muted-foreground"
                                                >Format:</span
                                            >
                                            {{
                                                safeSignature.signature_format ||
                                                'Unknown'
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Document & Signer Info -->
                    <div class="grid gap-6 md:grid-cols-2">
                        <!-- Document Info -->
                        <Card>
                            <CardHeader>
                                <CardTitle
                                    class="flex items-center gap-2 text-lg"
                                >
                                    <FileText class="h-5 w-5" />
                                    Document Information
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-3">
                                <div>
                                    <div class="text-sm text-muted-foreground">
                                        File Name
                                    </div>
                                    <div class="font-medium">
                                        {{
                                            safeSignature.file
                                                ?.original_file_name ||
                                            'Unknown'
                                        }}
                                    </div>
                                </div>
                                <Separator />
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <div
                                            class="text-sm text-muted-foreground"
                                        >
                                            Size
                                        </div>
                                        <div>
                                            {{
                                                formatFileSize(
                                                    safeSignature.file
                                                        ?.file_size,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            class="text-sm text-muted-foreground"
                                        >
                                            Type
                                        </div>
                                        <div>
                                            {{
                                                safeSignature.file?.mime_type ||
                                                'Unknown'
                                            }}
                                        </div>
                                    </div>
                                </div>
                                <Separator />
                                <div>
                                    <div
                                        class="mb-1 text-sm text-muted-foreground"
                                    >
                                        File Hash (SHA-256)
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <code
                                            class="flex-1 overflow-auto rounded bg-muted px-2 py-1 text-xs break-all"
                                        >
                                            {{ safeVerifiedHash }}
                                        </code>
                                        <TooltipProvider>
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        class="h-6 w-6 shrink-0"
                                                        @click="
                                                            copyToClipboard(
                                                                safeVerifiedHash,
                                                            )
                                                        "
                                                    >
                                                        <Copy class="h-3 w-3" />
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    Copy hash
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Signer Info -->
                        <Card>
                            <CardHeader>
                                <CardTitle
                                    class="flex items-center gap-2 text-lg"
                                >
                                    <User class="h-5 w-5" />
                                    Signer Information
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <div class="rounded-full bg-primary/10 p-2">
                                        <User class="h-5 w-5 text-primary" />
                                    </div>
                                    <div>
                                        <div class="font-medium">
                                            {{
                                                safeSignature.signer?.name ||
                                                'Unknown'
                                            }}
                                        </div>
                                        <div
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                safeSignature.signer?.email ||
                                                ''
                                            }}
                                        </div>
                                    </div>
                                </div>
                                <Separator />
                                <div>
                                    <div class="text-sm text-muted-foreground">
                                        Key Used
                                    </div>
                                    <div class="mt-1 font-medium">
                                        {{
                                            safeSignature.keyPair?.key_name ||
                                            'Unknown'
                                        }}
                                    </div>
                                    <div
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{
                                            safeSignature.keyPair
                                                ?.algorithm_used
                                        }}
                                        {{
                                            safeSignature.keyPair?.key_size
                                        }}
                                        bits
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Public Link -->
                    <Card v-if="safeToken">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Globe class="h-5 w-5" />
                                Public Verification Link
                            </CardTitle>
                            <CardDescription>
                                Share this link to allow anyone to verify this
                                signature
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div
                                class="flex items-center gap-2 rounded-lg bg-muted p-3"
                            >
                                <code
                                    class="flex-1 overflow-auto text-xs break-all"
                                >
                                    {{
                                        route('verifications.public', safeToken)
                                    }}
                                </code>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        copyToClipboard(
                                            route(
                                                'verifications.public',
                                                safeToken,
                                            ),
                                        )
                                    "
                                    class="shrink-0"
                                >
                                    <Copy class="mr-2 h-3 w-3" />
                                    Copy
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Details Tab -->
                <TabsContent value="details" class="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Verification Details</CardTitle>
                            <CardDescription>
                                Complete verification report
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <!-- Verification Metadata -->
                            <div class="grid grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <div class="text-sm text-muted-foreground">
                                        Verification ID
                                    </div>
                                    <div class="font-mono text-sm">
                                        #{{ verification.id }}
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <div class="text-sm text-muted-foreground">
                                        Signature ID
                                    </div>
                                    <div class="font-mono text-sm">
                                        #{{ verification.signature_id }}
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <div class="text-sm text-muted-foreground">
                                        Type
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <component
                                            :is="typeIcon"
                                            class="h-4 w-4"
                                        />
                                        <span>{{ typeLabel }}</span>
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <div class="text-sm text-muted-foreground">
                                        Verified At
                                    </div>
                                    <div>
                                        {{
                                            formatDate(verification.verified_at)
                                        }}
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <div class="text-sm text-muted-foreground">
                                        Duration
                                    </div>
                                    <div>
                                        {{
                                            formatDuration(
                                                verification.verification_duration_ms,
                                            )
                                        }}
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <div class="text-sm text-muted-foreground">
                                        Verifier IP
                                    </div>
                                    <div>
                                        {{ verification.verifier_ip || 'N/A' }}
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            <!-- Verifier Info -->
                            <div v-if="verification.verifier" class="space-y-2">
                                <div class="text-sm font-medium">Verifier</div>
                                <div class="flex items-center gap-2">
                                    <User
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                    <span>{{
                                        verification.verifier.name
                                    }}</span>
                                    <span class="text-sm text-muted-foreground"
                                        >({{
                                            verification.verifier.email
                                        }})</span
                                    >
                                </div>
                            </div>
                            <div v-else class="space-y-2">
                                <div class="text-sm font-medium">Verifier</div>
                                <div class="text-sm text-muted-foreground">
                                    Anonymous (not logged in)
                                </div>
                            </div>

                            <Separator />

                            <!-- Hash Comparison -->
                            <div class="space-y-3">
                                <div class="text-sm font-medium">
                                    Hash Verification
                                </div>
                                <div class="space-y-2">
                                    <div>
                                        <div
                                            class="mb-1 text-xs text-muted-foreground"
                                        >
                                            Verified Hash
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <code
                                                class="flex-1 overflow-auto rounded bg-muted px-2 py-1 text-xs break-all"
                                            >
                                                {{ safeVerifiedHash }}
                                            </code>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-6 w-6 shrink-0"
                                                @click="
                                                    copyToClipboard(
                                                        safeVerifiedHash,
                                                    )
                                                "
                                            >
                                                <Copy class="h-3 w-3" />
                                            </Button>
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            class="mb-1 text-xs text-muted-foreground"
                                        >
                                            Expected Hash
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <code
                                                class="flex-1 overflow-auto rounded bg-muted px-2 py-1 text-xs break-all"
                                            >
                                                {{ safeExpectedHash }}
                                            </code>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-6 w-6 shrink-0"
                                                @click="
                                                    copyToClipboard(
                                                        safeExpectedHash,
                                                    )
                                                "
                                            >
                                                <Copy class="h-3 w-3" />
                                            </Button>
                                        </div>
                                    </div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <Badge
                                            :class="
                                                safeVerifiedHash ===
                                                safeExpectedHash
                                                    ? 'bg-green-100 text-green-800'
                                                    : 'bg-red-100 text-red-800'
                                            "
                                        >
                                            {{
                                                safeVerifiedHash ===
                                                safeExpectedHash
                                                    ? 'Match'
                                                    : 'Mismatch'
                                            }}
                                        </Badge>
                                        <span
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                safeVerifiedHash ===
                                                safeExpectedHash
                                                    ? 'Document hash matches expected value'
                                                    : 'Document hash does not match'
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Timestamp Authority -->
                            <div
                                v-if="safeTimestampAuthority !== 'N/A'"
                                class="space-y-2"
                            >
                                <Separator />
                                <div class="text-sm font-medium">
                                    Timestamp Authority
                                </div>
                                <div class="text-sm">
                                    {{ safeTimestampAuthority }}
                                </div>
                            </div>

                            <!-- Certificate Chain -->
                            <div v-if="safeCertificateChain" class="space-y-2">
                                <Separator />
                                <div class="text-sm font-medium">
                                    Certificate Chain
                                </div>
                                <pre
                                    class="max-h-32 overflow-auto rounded bg-muted p-2 text-xs"
                                    >{{ safeCertificateChain }}</pre
                                >
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Raw Data Tab -->
                <TabsContent value="raw" class="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Raw Verification Data</CardTitle>
                            <CardDescription>
                                Complete verification details in JSON format
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div class="relative">
                                <pre
                                    class="max-h-96 overflow-auto rounded-lg bg-muted p-4 font-mono text-xs"
                                >
                                    <code>{{ JSON.stringify(safeVerificationDetails, null, 2) }}</code>
                                </pre>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="absolute top-2 right-2"
                                    @click="
                                        copyToClipboard(
                                            JSON.stringify(
                                                safeVerificationDetails,
                                                null,
                                                2,
                                            ),
                                        )
                                    "
                                >
                                    <Copy class="h-4 w-4" />
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>

            <!-- Actions Footer -->
            <Card>
                <CardFooter
                    class="flex flex-col justify-between gap-2 p-4 sm:flex-row"
                >
                    <Button variant="outline" as-child class="w-full sm:w-auto">
                        <Link :href="route('verifications.index')">
                            <History class="mr-2 h-4 w-4" />
                            Back to History
                        </Link>
                    </Button>
                    <div
                        class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row"
                    >
                        <Button
                            variant="outline"
                            as-child
                            class="w-full sm:w-auto"
                        >
                            <Link
                                :href="
                                    route(
                                        'signatures.show',
                                        verification.signature_id,
                                    )
                                "
                            >
                                <Eye class="mr-2 h-4 w-4" />
                                View Signature
                            </Link>
                        </Button>
                        <Button as-child class="w-full sm:w-auto">
                            <Link
                                :href="
                                    route('verifications.create', {
                                        signature_id: verification.signature_id,
                                    })
                                "
                            >
                                <ShieldCheck class="mr-2 h-4 w-4" />
                                Verify Again
                            </Link>
                        </Button>
                    </div>
                </CardFooter>
            </Card>
        </div>
    </AppLayout>
</template>
