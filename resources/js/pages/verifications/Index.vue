<!-- resources/js/pages/verifications/Index.vue -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    ShieldCheck,
    History,
    FileText,
    CheckCircle2,
    XCircle,
    Clock,
    Globe,
    User,
    Eye,
    Copy,
    MoreHorizontal,
    Search,
} from 'lucide-vue-next';
import { ref } from 'vue';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';

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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

interface Verification {
    id: number;
    signature: {
        id: number;
        file_name: string;
        signer_name: string;
    };
    result: boolean;
    verification_type: string;
    is_public_verification: boolean;
    verified_at: string;
    verification_duration_ms: number;
    verifier: {
        id: number;
        name: string;
        email: string;
    } | null;
    token?: string;
}

const props = defineProps<{
    verifications: Verification[];
    filters?: {
        result?: string;
        type?: string;
        date_from?: string;
        date_to?: string;
    };
    pagination?: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
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
];

// États des filtres
const search = ref('');
const resultFilter = ref(props.filters?.result || 'all');
const typeFilter = ref(props.filters?.type || 'all');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

// Fonction pour récupérer les paramètres actuels
const getCurrentParams = () => {
    const params: Record<string, any> = {};

    if (search.value) params.search = search.value;
    if (resultFilter.value !== 'all') params.result = resultFilter.value;
    if (typeFilter.value !== 'all') params.type = typeFilter.value;
    if (dateFrom.value) params.date_from = dateFrom.value;
    if (dateTo.value) params.date_to = dateTo.value;

    return params;
};

// Appliquer les filtres
const applyFilters = () => {
    const params = getCurrentParams();
    window.location.href = route('verifications.index', params);
};

// Réinitialiser les filtres
const resetFilters = () => {
    search.value = '';
    resultFilter.value = 'all';
    typeFilter.value = 'all';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
};

// Formater la date
const formatDate = (date: string) => {
    return new Date(date).toLocaleString();
};

// Formater la durée
const formatDuration = (ms: number) => {
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(2)}s`;
};

// Copier dans le presse-papier
const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
};

// Badge de résultat
const getResultBadge = (result: boolean) => {
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

// Icône de type
const getTypeIcon = (type: string, isPublic: boolean) => {
    if (isPublic) return Globe;
    switch (type) {
        case 'initial':
            return Clock;
        case 'manual':
            return User;
        default:
            return ShieldCheck;
    }
};
</script>

<template>
    <Head title="Verification History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">
                        Verification History
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        View all signature verifications performed
                    </p>
                </div>
                <Button as-child>
                    <Link :href="route('verifications.create')">
                        <ShieldCheck class="mr-2 h-4 w-4" />
                        New Verification
                    </Link>
                </Button>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-4">
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <CardTitle class="text-sm font-medium"
                            >Total Verifications</CardTitle
                        >
                        <ShieldCheck class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{ pagination?.total || verifications.length }}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <CardTitle class="text-sm font-medium">Valid</CardTitle>
                        <CheckCircle2 class="h-4 w-4 text-green-600" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-green-600">
                            {{ verifications.filter((v) => v.result).length }}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <CardTitle class="text-sm font-medium"
                            >Invalid</CardTitle
                        >
                        <XCircle class="h-4 w-4 text-red-600" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-red-600">
                            {{ verifications.filter((v) => !v.result).length }}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <CardTitle class="text-sm font-medium"
                            >Public Verifications</CardTitle
                        >
                        <Globe class="h-4 w-4 text-blue-600" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-blue-600">
                            {{
                                verifications.filter(
                                    (v) => v.is_public_verification,
                                ).length
                            }}
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Filters Card -->
            <Card>
                <CardHeader>
                    <CardTitle>Filters</CardTitle>
                    <CardDescription>
                        Filter verifications by result, type, or date
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 md:grid-cols-4">
                        <div class="space-y-2">
                            <Label>Search</Label>
                            <div class="relative">
                                <Search
                                    class="absolute top-2.5 left-2 h-4 w-4 text-muted-foreground"
                                />
                                <Input
                                    v-model="search"
                                    placeholder="Search by file name..."
                                    class="pl-8"
                                    @keyup.enter="applyFilters"
                                />
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label>Result</Label>
                            <Select
                                v-model="resultFilter"
                                @update:model-value="applyFilters"
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All results" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >All results</SelectItem
                                    >
                                    <SelectItem value="valid"
                                        >Valid only</SelectItem
                                    >
                                    <SelectItem value="invalid"
                                        >Invalid only</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label>Type</Label>
                            <Select
                                v-model="typeFilter"
                                @update:model-value="applyFilters"
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >All types</SelectItem
                                    >
                                    <SelectItem value="signature"
                                        >Signature</SelectItem
                                    >
                                    <SelectItem value="hash">Hash</SelectItem>
                                    <SelectItem value="public"
                                        >Public</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label>Date Range</Label>
                            <div class="flex gap-2">
                                <Input
                                    v-model="dateFrom"
                                    type="date"
                                    class="flex-1"
                                    @change="applyFilters"
                                />
                                <Input
                                    v-model="dateTo"
                                    type="date"
                                    class="flex-1"
                                    @change="applyFilters"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <Button variant="ghost" size="sm" @click="resetFilters">
                            Reset Filters
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Verifications Table -->
            <Card>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Document / Signer</TableHead>
                                <TableHead>Result</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Verified At</TableHead>
                                <TableHead>Duration</TableHead>
                                <TableHead>Verifier</TableHead>
                                <TableHead class="text-right"
                                    >Actions</TableHead
                                >
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="verifications.length === 0">
                                <TableCell colspan="7" class="h-32 text-center">
                                    <div
                                        class="flex flex-col items-center justify-center text-muted-foreground"
                                    >
                                        <History
                                            class="mb-2 h-8 w-8 opacity-50"
                                        />
                                        <p>No verifications found</p>
                                        <Button
                                            variant="link"
                                            as-child
                                            class="mt-2"
                                        >
                                            <Link
                                                :href="
                                                    route(
                                                        'verifications.create',
                                                    )
                                                "
                                            >
                                                Start your first verification
                                            </Link>
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>

                            <TableRow
                                v-for="verif in verifications"
                                :key="verif.id"
                            >
                                <TableCell>
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <FileText
                                                class="h-4 w-4 text-muted-foreground"
                                            />
                                            <span class="font-medium">{{
                                                verif.signature.file_name
                                            }}</span>
                                        </div>
                                        <span
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            Signed by:
                                            {{ verif.signature.signer_name }}
                                        </span>
                                    </div>
                                </TableCell>

                                <TableCell>
                                    <Badge
                                        :class="
                                            getResultBadge(verif.result).color
                                        "
                                    >
                                        <component
                                            :is="
                                                getResultBadge(verif.result)
                                                    .icon
                                            "
                                            class="mr-1 h-3 w-3"
                                        />
                                        {{ getResultBadge(verif.result).label }}
                                    </Badge>
                                </TableCell>

                                <TableCell>
                                    <div class="flex items-center gap-2">
                                        <component
                                            :is="
                                                getTypeIcon(
                                                    verif.verification_type,
                                                    verif.is_public_verification,
                                                )
                                            "
                                            class="h-4 w-4"
                                            :class="{
                                                'text-blue-600':
                                                    verif.is_public_verification,
                                                'text-muted-foreground':
                                                    !verif.is_public_verification,
                                            }"
                                        />
                                        <span class="text-sm">
                                            {{
                                                verif.is_public_verification
                                                    ? 'Public'
                                                    : verif.verification_type
                                            }}
                                        </span>
                                    </div>
                                </TableCell>

                                <TableCell>
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger>
                                                <span class="text-sm">{{
                                                    formatDate(
                                                        verif.verified_at,
                                                    )
                                                }}</span>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                <p>
                                                    {{
                                                        new Date(
                                                            verif.verified_at,
                                                        ).toLocaleString()
                                                    }}
                                                </p>
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </TableCell>

                                <TableCell>
                                    <span class="font-mono text-sm">{{
                                        formatDuration(
                                            verif.verification_duration_ms,
                                        )
                                    }}</span>
                                </TableCell>

                                <TableCell>
                                    <div
                                        v-if="verif.verifier"
                                        class="flex items-center gap-1"
                                    >
                                        <User
                                            class="h-3 w-3 text-muted-foreground"
                                        />
                                        <span class="text-sm">{{
                                            verif.verifier.name
                                        }}</span>
                                    </div>
                                    <span
                                        v-else
                                        class="text-sm text-muted-foreground"
                                        >Anonymous</span
                                    >
                                </TableCell>

                                <TableCell class="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="icon">
                                                <MoreHorizontal
                                                    class="h-4 w-4"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuLabel
                                                >Actions</DropdownMenuLabel
                                            >

                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="
                                                        route(
                                                            'verifications.show',
                                                            verif.id,
                                                        )
                                                    "
                                                    class="flex items-center"
                                                >
                                                    <Eye class="mr-2 h-4 w-4" />
                                                    View Details
                                                </Link>
                                            </DropdownMenuItem>

                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="
                                                        route(
                                                            'signatures.show',
                                                            verif.signature.id,
                                                        )
                                                    "
                                                    class="flex items-center"
                                                >
                                                    <FileText
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    View Signature
                                                </Link>
                                            </DropdownMenuItem>

                                            <DropdownMenuItem
                                                v-if="verif.token"
                                                @click="
                                                    copyToClipboard(
                                                        route(
                                                            'verifications.public',
                                                            {
                                                                token: verif.token,
                                                            },
                                                        ),
                                                    )
                                                "
                                            >
                                                <Copy class="mr-2 h-4 w-4" />
                                                Copy Public Link
                                            </DropdownMenuItem>

                                            <DropdownMenuSeparator />

                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="
                                                        route(
                                                            'verifications.create',
                                                            {
                                                                signature_id:
                                                                    verif
                                                                        .signature
                                                                        .id,
                                                            },
                                                        )
                                                    "
                                                    class="flex items-center"
                                                >
                                                    <ShieldCheck
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Verify Again
                                                </Link>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>

                <!-- Pagination Simple -->
                <CardFooter
                    v-if="pagination && pagination.last_page > 1"
                    class="border-t px-6 py-4"
                >
                    <div class="flex w-full items-center justify-between">
                        <div class="text-sm text-muted-foreground">
                            Showing page {{ pagination.current_page }} of
                            {{ pagination.last_page }}
                        </div>
                        <div class="flex items-center gap-2">
                            <!-- Previous Button -->
                            <Button
                                v-if="pagination.current_page > 1"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <Link
                                    :href="
                                        route('verifications.index', {
                                            ...getCurrentParams(),
                                            page: pagination.current_page - 1,
                                        })
                                    "
                                >
                                    Previous
                                </Link>
                            </Button>
                            <Button v-else variant="outline" size="sm" disabled>
                                Previous
                            </Button>

                            <!-- Page Numbers -->
                            <div class="flex items-center gap-1">
                                <Button
                                    v-for="pageNum in pagination.last_page"
                                    :key="pageNum"
                                    :variant="
                                        pageNum === pagination.current_page
                                            ? 'default'
                                            : 'outline'
                                    "
                                    size="sm"
                                    class="min-w-[32px]"
                                    as-child
                                >
                                    <Link
                                        :href="
                                            route('verifications.index', {
                                                ...getCurrentParams(),
                                                page: pageNum,
                                            })
                                        "
                                    >
                                        {{ pageNum }}
                                    </Link>
                                </Button>
                            </div>

                            <!-- Next Button -->
                            <Button
                                v-if="
                                    pagination.current_page <
                                    pagination.last_page
                                "
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <Link
                                    :href="
                                        route('verifications.index', {
                                            ...getCurrentParams(),
                                            page: pagination.current_page + 1,
                                        })
                                    "
                                >
                                    Next
                                </Link>
                            </Button>
                            <Button v-else variant="outline" size="sm" disabled>
                                Next
                            </Button>
                        </div>
                    </div>
                </CardFooter>
            </Card>
        </div>
    </AppLayout>
</template>
