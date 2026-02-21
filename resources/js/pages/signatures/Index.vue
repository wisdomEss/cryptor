<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';
import {
    FileSignature,
    Plus,
    Shield,
    CheckCircle2,
    XCircle,
    Clock,
    FileText,
    Eye,
    MoreHorizontal,
    Download,
    AlertCircle,
    User,
    KeyRound,
    Archive
} from 'lucide-vue-next';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tabs,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

interface Signature {
    id: number;
    signature_format: string;
    algorithm_used: string;
    signing_reason?: string;
    validation_status: 'pending' | 'valid' | 'invalid' | 'revoked';
    validation_message?: string;
    signed_at: string;
    revoked_at?: string;
    verification_count: number;
    file: {
        id: number;
        original_file_name: string;
        file_size: number;
    };
    key_pair: {
        id: number;
        key_name: string;
        algorithm_used: string;
    };
    signer: {
        id: number;
        name: string;
        email: string;
    };
}

const props = defineProps<{
    signatures: Signature[];
    type?: 'all' | 'pending' | 'revoked' | 'expired';
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Signatures', href: route('signatures.index') },
];

const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const getStatusBadge = (signature: Signature) => {
    if (signature.revoked_at) {
        return { color: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300', label: 'Revoked', icon: Archive };
    }
    switch(signature.validation_status) {
        case 'invalid':
            return { color: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300', label: 'Invalid', icon: XCircle };
        case 'pending':
            return { color: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300', label: 'Pending', icon: Clock };
        case 'valid':
            return { color: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300', label: 'Valid', icon: CheckCircle2 };
        default:
            return { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', label: 'Unknown', icon: AlertCircle };
    }
};

const getAlgorithmBadge = (algorithm: string) => {
    const algos: Record<string, string> = {
        'rsa': 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        'ecdsa': 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
        'ed25519': 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    };
    return algos[algorithm.toLowerCase()] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
};

const getFormatBadge = (format: string) => {
    const formats: Record<string, string> = {
        'pkcs7': 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        'cms': 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
        'pdf': 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    };
    return formats[format.toLowerCase()] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
};

const getTypeLabel = () => {
    switch(props.type) {
        case 'pending': return 'Pending Signatures';
        case 'revoked': return 'Revoked Signatures';
        case 'expired': return 'Expired Signatures';
        default: return 'All Signatures';
    }
};
</script>

<template>
    <Head :title="getTypeLabel()" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">{{ getTypeLabel() }}</h1>
                    <p class="text-sm text-muted-foreground">
                        Manage your digital signatures
                    </p>
                </div>
                <Button as-child>
                    <Link :href="route('signatures.create')">
                        <Plus class="mr-2 h-4 w-4" />
                        Sign Document
                    </Link>
                </Button>
            </div>

            <!-- Tabs -->
            <Tabs :default-value="type || 'all'" class="w-full">
                <TabsList class="grid w-full grid-cols-4">
                    <TabsTrigger value="all" as-child>
                        <Link :href="route('signatures.index')" class="flex items-center gap-2">
                            <FileSignature class="h-4 w-4" />
                            All
                        </Link>
                    </TabsTrigger>
                    <TabsTrigger value="pending" as-child>
                        <Link :href="route('signatures.pending')" class="flex items-center gap-2">
                            <Clock class="h-4 w-4" />
                            Pending
                        </Link>
                    </TabsTrigger>
                    <TabsTrigger value="revoked" as-child>
                        <Link :href="route('signatures.revoked')" class="flex items-center gap-2">
                            <Archive class="h-4 w-4" />
                            Revoked
                        </Link>
                    </TabsTrigger>
                    <TabsTrigger value="expired" as-child>
                        <Link :href="route('signatures.expired')" class="flex items-center gap-2">
                            <AlertCircle class="h-4 w-4" />
                            Expired
                        </Link>
                    </TabsTrigger>
                </TabsList>
            </Tabs>

            <!-- Stats Card -->
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle class="text-sm font-medium">Total Signatures</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">{{ signatures.length }}</div>
                    <p class="text-xs text-muted-foreground mt-1">
                        {{ signatures.filter(s => s.validation_status === 'valid').length }} valid,
                        {{ signatures.filter(s => s.validation_status === 'pending').length }} pending
                    </p>
                </CardContent>
            </Card>

            <!-- Signatures Table -->
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle>All Signatures</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Document / Signer</TableHead>
                                <TableHead>Key / Algorithm</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Signed At</TableHead>
                                <TableHead>Format</TableHead>
                                <TableHead>Verif.</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="signatures.length === 0">
                                <TableCell colspan="7" class="h-32 text-center">
                                    <div class="flex flex-col items-center justify-center text-muted-foreground">
                                        <FileSignature class="h-8 w-8 mb-2 opacity-50" />
                                        <p>No signatures found</p>
                                        <Button variant="link" as-child class="mt-2">
                                            <Link :href="route('signatures.create')">Sign your first document</Link>
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>

                            <TableRow v-for="signature in signatures" :key="signature.id">
                                <TableCell>
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <FileText class="h-4 w-4 text-muted-foreground shrink-0" />
                                            <span class="font-medium truncate max-w-[200px]">
                                                {{ signature.file.original_file_name }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-1 mt-1">
                                            <User class="h-3 w-3 text-muted-foreground" />
                                            <span class="text-xs text-muted-foreground truncate max-w-[150px]">
                                                {{ signature.signer.name }}
                                            </span>
                                        </div>
                                        <span v-if="signature.signing_reason" class="text-xs text-muted-foreground mt-1 truncate max-w-[200px]">
                                            {{ signature.signing_reason }}
                                        </span>
                                    </div>
                                </TableCell>

                                <TableCell>
                                    <div class="flex flex-col gap-1">
                                        <Badge :class="getAlgorithmBadge(signature.algorithm_used)">
                                            {{ signature.algorithm_used }}
                                        </Badge>
                                        <span class="text-xs text-muted-foreground">
                                            {{ signature.key_pair.key_name }}
                                        </span>
                                    </div>
                                </TableCell>

                                <TableCell>
                                    <Badge :class="getStatusBadge(signature).color">
                                        <component :is="getStatusBadge(signature).icon" class="h-3 w-3 mr-1" />
                                        {{ getStatusBadge(signature).label }}
                                    </Badge>
                                </TableCell>

                                <TableCell>
                                    <span class="text-sm">{{ signature.signed_at }}</span>
                                </TableCell>

                                <TableCell>
                                    <Badge :class="getFormatBadge(signature.signature_format)">
                                        {{ signature.signature_format }}
                                    </Badge>
                                </TableCell>

                                <TableCell>
                                    <div class="flex items-center gap-1">
                                        <Shield class="h-4 w-4 text-muted-foreground" />
                                        <span class="text-sm">{{ signature.verification_count }}</span>
                                    </div>
                                </TableCell>

                                <TableCell class="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="icon">
                                                <MoreHorizontal class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                            <DropdownMenuItem as-child>
                                                <Link :href="route('signatures.show', signature.id)" class="flex items-center">
                                                    <Eye class="h-4 w-4 mr-2" />
                                                    View Details
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem as-child>
                                                <Link :href="route('verifications.create', { signature_id: signature.id })" class="flex items-center">
                                                    <Shield class="h-4 w-4 mr-2" />
                                                    Verify
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem as-child>
                                                <Link :href="route('keys.show', signature.key_pair.id)" class="flex items-center">
                                                    <KeyRound class="h-4 w-4 mr-2" />
                                                    View Key
                                                </Link>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>