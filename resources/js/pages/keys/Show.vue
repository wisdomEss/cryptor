<!-- resources/js/pages/Keys/Show.vue -->
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    Calendar,
    AlertCircle,
    ChevronLeft,
    Download,
    Copy,
    CheckCircle2,
    XCircle,
    Star,
    Clock,
    FileText,
    Lock,
    Ban,
    Trash2,
    MoreHorizontal,
    QrCode,
    AlertTriangle,
    Eye,
    EyeOff,
    FileKey,
    History,
} from 'lucide-vue-next';
import { ref } from 'vue';
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
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

// Configuration Ziggy
const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

interface KeyDetails {
    id: number;
    key_name: string;
    algorithm_used: string;
    key_size: number;
    key_identifier: string;
    is_default: boolean;
    is_active: boolean;
    is_revoked: boolean;
    public_key: string;
    valid_from?: string;
    valid_to?: string;
    created_at: string;
    updated_at: string;
    last_used?: string;
    revoked_at?: string;
    revoked_reason?: string;
    certificate_data?: string;
    certificate_issuer?: string;
    fingerprint: string;
    signatures_count: number;
}

const props = defineProps<{
    keyData: KeyDetails;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Keys',
        href: route('keys.index'),
    },
    {
        title: props.keyData.key_name,
        href: route('keys.show', props.keyData.id),
    },
];

// État local
const showPublicKey = ref(false);
const showRevokeDialog = ref(false);
const showDeleteDialog = ref(false);
const showPasswordDialog = ref(false);

// Formulaire de révocation
const revokeForm = useForm({
    reason: '',
});

// Formulaire de mot de passe pour clé privée
const passwordForm = useForm({
    password: '',
});

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
};

const getAlgorithmColor = (algorithm: string) => {
    const colors = {
        rsa: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        ecdsa: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
        ed25519:
            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    };
    return (
        colors[algorithm.toLowerCase() as keyof typeof colors] ||
        'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
    );
};

const getStatusBadge = () => {
    const key = props.keyData;
    if (key.is_revoked) {
        return {
            color: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
            label: 'Revoked',
            icon: Ban,
        };
    }
    if (!key.is_active) {
        return {
            color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
            label: 'Inactive',
            icon: XCircle,
        };
    }
    if (key.valid_to && new Date(key.valid_to) < new Date()) {
        return {
            color: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
            label: 'Expired',
            icon: AlertCircle,
        };
    }
    return {
        color: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        label: 'Active',
        icon: CheckCircle2,
    };
};

const getValidityStatus = () => {
    const key = props.keyData;
    if (!key.valid_from && !key.valid_to)
        return { label: 'Never expires', color: 'text-muted-foreground' };

    const now = new Date();
    const validFrom = key.valid_from ? new Date(key.valid_from) : null;
    const validTo = key.valid_to ? new Date(key.valid_to) : null;

    if (validFrom && validFrom > now) {
        return {
            label: 'Not yet valid',
            color: 'text-yellow-600 dark:text-yellow-500',
        };
    }
    if (validTo && validTo < now) {
        return { label: 'Expired', color: 'text-red-600 dark:text-red-500' };
    }
    if (validTo) {
        const daysLeft = Math.ceil(
            (validTo.getTime() - now.getTime()) / (1000 * 3600 * 24),
        );
        if (daysLeft < 30) {
            return {
                label: `Expires in ${daysLeft} days`,
                color: 'text-yellow-600 dark:text-yellow-500',
            };
        }
        return {
            label: `Valid until ${key.valid_to}`,
            color: 'text-green-600 dark:text-green-500',
        };
    }
    return { label: 'Valid', color: 'text-green-600 dark:text-green-500' };
};

const handleRevoke = () => {
    revokeForm.post(route('keys.revoke', props.keyData.id), {
        onSuccess: () => {
            showRevokeDialog.value = false;
            revokeForm.reset();
        },
    });
};

const handleDelete = () => {
    useForm({}).delete(route('keys.destroy', props.keyData.id), {
        onSuccess: () => {
            showDeleteDialog.value = false;
        },
    });
};

const handleSetDefault = () => {
    useForm({}).post(route('keys.set-default', props.keyData.id));
};

const handleActivate = () => {
    useForm({}).post(route('keys.activate', props.keyData.id));
};

const handleDeactivate = () => {
    useForm({}).post(route('keys.deactivate', props.keyData.id));
};

const handleDownloadPrivateKey = () => {
    passwordForm.post(route('keys.download-private', props.keyData.id), {
        onSuccess: () => {
            // Téléchargement géré par le navigateur
            showPasswordDialog.value = false;
            passwordForm.reset();
        },
    });
};

const status = getStatusBadge();
const validityStatus = getValidityStatus();
</script>

<template>
    <Head :title="keyData.key_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header avec navigation -->
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-4">
                    <Button variant="ghost" size="icon" as-child>
                        <Link :href="route('keys.index')">
                            <ChevronLeft class="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-bold tracking-tight">
                                {{ keyData.key_name }}
                            </h1>
                            <Badge :class="status.color" class="capitalize">
                                <component
                                    :is="status.icon"
                                    class="mr-1 h-3 w-3"
                                />
                                {{ status.label }}
                            </Badge>
                            <Badge
                                v-if="keyData.is_default"
                                variant="outline"
                                class="bg-yellow-50 dark:bg-yellow-950"
                            >
                                <Star class="mr-1 h-3 w-3 fill-yellow-500" />
                                Default
                            </Badge>
                        </div>
                        <div class="mt-1 flex items-center gap-2">
                            <span
                                class="font-mono text-sm text-muted-foreground"
                            >
                                {{ keyData.fingerprint }}
                            </span>
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-6 w-6"
                                            @click="
                                                copyToClipboard(
                                                    keyData.fingerprint,
                                                )
                                            "
                                        >
                                            <Copy class="h-3 w-3" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        Copy fingerprint
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                    </div>
                </div>

                <!-- Actions dropdown -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline">
                            <MoreHorizontal class="mr-2 h-4 w-4" />
                            Actions
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuLabel>Key Actions</DropdownMenuLabel>

                        <DropdownMenuItem
                            v-if="!keyData.is_default"
                            @click="handleSetDefault"
                        >
                            <Star class="mr-2 h-4 w-4" />
                            Set as Default
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <!-- Download submenu -->
                        <DropdownMenuItem as-child>
                            <Link
                                :href="route('keys.download', keyData.id)"
                                class="flex items-center"
                            >
                                <Download class="mr-2 h-4 w-4" />
                                Download Public Key
                            </Link>
                        </DropdownMenuItem>

                        <DropdownMenuItem @click="showPasswordDialog = true">
                            <Lock class="mr-2 h-4 w-4" />
                            Download Private Key
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <!-- Export submenu -->
                        <DropdownMenuItem as-child>
                            <Link
                                :href="route('keys.export.pem', keyData.id)"
                                class="flex items-center"
                            >
                                <FileKey class="mr-2 h-4 w-4" />
                                Export as PEM
                            </Link>
                        </DropdownMenuItem>

                        <DropdownMenuItem
                            v-if="keyData.algorithm_used === 'rsa'"
                            as-child
                        >
                            <Link
                                :href="route('keys.export.ssh', keyData.id)"
                                class="flex items-center"
                            >
                                <QrCode class="mr-2 h-4 w-4" />
                                Export as SSH
                            </Link>
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <!-- Status management -->
                        <DropdownMenuItem
                            v-if="!keyData.is_revoked && keyData.is_active"
                            @click="handleDeactivate"
                        >
                            <XCircle class="mr-2 h-4 w-4" />
                            Deactivate
                        </DropdownMenuItem>

                        <DropdownMenuItem
                            v-if="!keyData.is_revoked && !keyData.is_active"
                            @click="handleActivate"
                        >
                            <CheckCircle2 class="mr-2 h-4 w-4" />
                            Activate
                        </DropdownMenuItem>

                        <DropdownMenuItem
                            v-if="!keyData.is_revoked && !keyData.is_default"
                            class="text-red-600 dark:text-red-400"
                            @click="showRevokeDialog = true"
                        >
                            <Ban class="mr-2 h-4 w-4" />
                            Revoke Key
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <DropdownMenuItem
                            v-if="!keyData.is_default"
                            class="text-red-600 dark:text-red-400"
                            @click="showDeleteDialog = true"
                        >
                            <Trash2 class="mr-2 h-4 w-4" />
                            Delete Key
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <!-- Main content -->
            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Left column - Key details -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Key information card -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Key Information</CardTitle>
                            <CardDescription>
                                Details and metadata for this key pair
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <!-- Algorithm and size -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <Label class="text-sm text-muted-foreground"
                                        >Algorithm</Label
                                    >
                                    <div class="mt-1 flex items-center gap-2">
                                        <Badge
                                            :class="
                                                getAlgorithmColor(
                                                    keyData.algorithm_used,
                                                )
                                            "
                                        >
                                            {{
                                                keyData.algorithm_used.toUpperCase()
                                            }}
                                        </Badge>
                                        <span class="text-sm"
                                            >{{ keyData.key_size }} bits</span
                                        >
                                    </div>
                                </div>
                                <div>
                                    <Label class="text-sm text-muted-foreground"
                                        >Key ID</Label
                                    >
                                    <div class="mt-1 flex items-center gap-2">
                                        <span class="font-mono text-sm"
                                            >{{
                                                keyData.key_identifier.substring(
                                                    0,
                                                    8,
                                                )
                                            }}...</span
                                        >
                                        <TooltipProvider>
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        class="h-6 w-6"
                                                        @click="
                                                            copyToClipboard(
                                                                keyData.key_identifier,
                                                            )
                                                        "
                                                    >
                                                        <Copy class="h-3 w-3" />
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    Copy Key ID
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            <!-- Validity period -->
                            <div>
                                <Label class="text-sm text-muted-foreground"
                                    >Validity Period</Label
                                >
                                <div class="mt-2 grid grid-cols-2 gap-4">
                                    <div>
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Valid from</span
                                        >
                                        <p class="text-sm">
                                            {{
                                                keyData.valid_from ||
                                                'Immediately'
                                            }}
                                        </p>
                                    </div>
                                    <div>
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Valid until</span
                                        >
                                        <p class="text-sm">
                                            {{
                                                keyData.valid_to ||
                                                'Never expires'
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <Badge
                                        variant="outline"
                                        :class="validityStatus.color"
                                    >
                                        <Clock class="mr-1 h-3 w-3" />
                                        {{ validityStatus.label }}
                                    </Badge>
                                </div>
                            </div>

                            <Separator />

                            <!-- Certificate information -->
                            <div
                                v-if="
                                    keyData.certificate_data ||
                                    keyData.certificate_issuer
                                "
                            >
                                <Label class="text-sm text-muted-foreground"
                                    >Certificate Information</Label
                                >
                                <div class="mt-2 space-y-2">
                                    <div v-if="keyData.certificate_issuer">
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Issuer</span
                                        >
                                        <p class="text-sm">
                                            {{ keyData.certificate_issuer }}
                                        </p>
                                    </div>
                                    <div v-if="keyData.certificate_data">
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Certificate</span
                                        >
                                        <p class="truncate font-mono text-xs">
                                            {{ keyData.certificate_data }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Public Key -->
                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between"
                        >
                            <div>
                                <CardTitle>Public Key</CardTitle>
                                <CardDescription>
                                    Share this key to allow others to verify
                                    your signatures
                                </CardDescription>
                            </div>
                            <div class="flex gap-2">
                                <TooltipProvider>
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                @click="
                                                    copyToClipboard(
                                                        keyData.public_key,
                                                    )
                                                "
                                            >
                                                <Copy class="h-4 w-4" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Copy public key
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                                <Button variant="outline" size="icon" as-child>
                                    <Link
                                        :href="
                                            route('keys.download', keyData.id)
                                        "
                                    >
                                        <Download class="h-4 w-4" />
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="showPublicKey = !showPublicKey"
                                >
                                    <Eye
                                        v-if="!showPublicKey"
                                        class="mr-2 h-4 w-4"
                                    />
                                    <EyeOff v-else class="mr-2 h-4 w-4" />
                                    {{ showPublicKey ? 'Hide' : 'Show' }}
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div class="relative">
                                <pre
                                    v-if="showPublicKey"
                                    class="overflow-x-auto rounded-lg bg-muted p-4 font-mono text-xs"
                                    >{{ keyData.public_key }}</pre
                                >
                                <div
                                    v-else
                                    class="rounded-lg bg-muted p-4 text-center text-muted-foreground"
                                >
                                    <Lock
                                        class="mx-auto mb-2 h-8 w-8 opacity-50"
                                    />
                                    <p class="text-sm">
                                        Public key is hidden. Click "Show" to
                                        view.
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Usage statistics -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Usage Statistics</CardTitle>
                            <CardDescription>
                                How this key has been used
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div class="grid grid-cols-3 gap-4">
                                <div
                                    class="rounded-lg bg-muted p-4 text-center"
                                >
                                    <FileText
                                        class="mx-auto mb-2 h-6 w-6 text-muted-foreground"
                                    />
                                    <span class="text-2xl font-bold">{{
                                        keyData.signatures_count
                                    }}</span>
                                    <p class="text-xs text-muted-foreground">
                                        Signatures
                                    </p>
                                </div>
                                <div
                                    class="rounded-lg bg-muted p-4 text-center"
                                >
                                    <History
                                        class="mx-auto mb-2 h-6 w-6 text-muted-foreground"
                                    />
                                    <span class="text-sm font-medium">{{
                                        keyData.last_used || 'Never'
                                    }}</span>
                                    <p class="text-xs text-muted-foreground">
                                        Last used
                                    </p>
                                </div>
                                <div
                                    class="rounded-lg bg-muted p-4 text-center"
                                >
                                    <Calendar
                                        class="mx-auto mb-2 h-6 w-6 text-muted-foreground"
                                    />
                                    <span class="text-sm font-medium">{{
                                        keyData.created_at
                                    }}</span>
                                    <p class="text-xs text-muted-foreground">
                                        Created
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Right column - Status & Actions -->
                <div class="space-y-6">
                    <!-- Status card -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Key Status</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm">Status</span>
                                <Badge :class="status.color" class="capitalize">
                                    <component
                                        :is="status.icon"
                                        class="mr-1 h-3 w-3"
                                    />
                                    {{ status.label }}
                                </Badge>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm">Default key</span>
                                <Badge
                                    :variant="
                                        keyData.is_default
                                            ? 'default'
                                            : 'outline'
                                    "
                                >
                                    <Star
                                        v-if="keyData.is_default"
                                        class="mr-1 h-3 w-3 fill-current"
                                    />
                                    {{ keyData.is_default ? 'Yes' : 'No' }}
                                </Badge>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm">Signatures</span>
                                <span class="text-sm font-medium">{{
                                    keyData.signatures_count
                                }}</span>
                            </div>
                            <Separator />

                            <!-- Quick actions -->
                            <div class="space-y-2">
                                <Button
                                    v-if="keyData.is_revoked"
                                    class="w-full"
                                    variant="outline"
                                    disabled
                                >
                                    <Ban class="mr-2 h-4 w-4" />
                                    Key Revoked
                                </Button>
                                <Button
                                    v-else-if="!keyData.is_active"
                                    class="w-full"
                                    @click="handleActivate"
                                >
                                    <CheckCircle2 class="mr-2 h-4 w-4" />
                                    Activate Key
                                </Button>
                                <Button
                                    v-else-if="!keyData.is_default"
                                    class="w-full"
                                    @click="handleSetDefault"
                                >
                                    <Star class="mr-2 h-4 w-4" />
                                    Set as Default
                                </Button>

                                <Button
                                    v-if="
                                        !keyData.is_revoked &&
                                        !keyData.is_default &&
                                        keyData.is_active
                                    "
                                    variant="destructive"
                                    class="w-full"
                                    @click="showRevokeDialog = true"
                                >
                                    <Ban class="mr-2 h-4 w-4" />
                                    Revoke Key
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Security warnings -->
                    <Card v-if="!keyData.is_revoked && keyData.is_active">
                        <CardHeader>
                            <CardTitle
                                class="flex items-center gap-2 text-yellow-600 dark:text-yellow-500"
                            >
                                <AlertTriangle class="h-5 w-5" />
                                Security
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Alert>
                                <AlertTitle>Private key security</AlertTitle>
                                <AlertDescription>
                                    Never share your private key. Download it
                                    now and store it securely. You won't be able
                                    to download it again later.
                                </AlertDescription>
                            </Alert>
                            <Button
                                variant="outline"
                                class="mt-4 w-full"
                                @click="showPasswordDialog = true"
                            >
                                <Lock class="mr-2 h-4 w-4" />
                                Download Private Key
                            </Button>
                        </CardContent>
                    </Card>

                    <!-- Revocation info -->
                    <Card v-if="keyData.is_revoked">
                        <CardHeader>
                            <CardTitle
                                class="flex items-center gap-2 text-red-600 dark:text-red-500"
                            >
                                <Ban class="h-5 w-5" />
                                Revoked
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-2">
                            <div>
                                <span class="text-xs text-muted-foreground"
                                    >Revoked at</span
                                >
                                <p class="text-sm">{{ keyData.revoked_at }}</p>
                            </div>
                            <div v-if="keyData.revoked_reason">
                                <span class="text-xs text-muted-foreground"
                                    >Reason</span
                                >
                                <p class="text-sm">
                                    {{ keyData.revoked_reason }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Revoke Dialog -->
            <Dialog v-model:open="showRevokeDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            class="flex items-center gap-2 text-red-600 dark:text-red-500"
                        >
                            <Ban class="h-5 w-5" />
                            Revoke Key
                        </DialogTitle>
                        <DialogDescription>
                            Are you sure you want to revoke this key? This
                            action cannot be undone. Revoked keys cannot be used
                            for new signatures.
                        </DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="handleRevoke">
                        <div class="space-y-4 py-4">
                            <div class="space-y-2">
                                <Label for="reason">Reason (optional)</Label>
                                <Textarea
                                    id="reason"
                                    v-model="revokeForm.reason"
                                    placeholder="Why are you revoking this key?"
                                    rows="{3}"
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                @click="showRevokeDialog = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="revokeForm.processing"
                            >
                                <Ban class="mr-2 h-4 w-4" />
                                Revoke Key
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- Delete Dialog -->
            <Dialog v-model:open="showDeleteDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            class="flex items-center gap-2 text-red-600 dark:text-red-500"
                        >
                            <Trash2 class="h-5 w-5" />
                            Delete Key
                        </DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete this key? This
                            action cannot be undone. All signatures created with
                            this key will remain valid but cannot be verified
                            with this key.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showDeleteDialog = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            @click="handleDelete"
                        >
                            <Trash2 class="mr-2 h-4 w-4" />
                            Delete Key
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Password Dialog for Private Key -->
            <Dialog v-model:open="showPasswordDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Confirm Password</DialogTitle>
                        <DialogDescription>
                            Please enter your password to download the private
                            key.
                        </DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="handleDownloadPrivateKey">
                        <div class="space-y-4 py-4">
                            <div class="space-y-2">
                                <Label for="password">Password</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    v-model="passwordForm.password"
                                    placeholder="Enter your password"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Your private key is encrypted and requires
                                    your password to decrypt.
                                </p>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                @click="showPasswordDialog = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                :disabled="passwordForm.processing"
                            >
                                <Lock class="mr-2 h-4 w-4" />
                                Download Private Key
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
