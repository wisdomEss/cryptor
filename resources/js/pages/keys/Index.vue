<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    KeyRound,
    Plus,
    Calendar,
    CheckCircle2,
    XCircle,
    Star,
    Copy,
    Download,
    Eye,
    MoreHorizontal,
} from 'lucide-vue-next';
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
} from '@/components/ui/card';
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

interface Key {
    id: number;
    key_name: string;
    algorithm_used: string;
    key_size: number;
    is_default: boolean;
    is_active: boolean;
    is_revoked: boolean;
    valid_from?: string;
    valid_to?: string;
    created_at: string;
    fingerprint: string;
}

defineProps<{
    keys: Key[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'My Keys',
        href: route('keys.index'),
    },
];

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
};

const getAlgorithmBadge = (algorithm: string) => {
    const algos: Record<string, { color: string; label: string }> = {
        rsa: {
            color: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
            label: 'RSA',
        },
        ecdsa: {
            color: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
            label: 'ECDSA',
        },
        ed25519: {
            color: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
            label: 'Ed25519',
        },
    };
    return (
        algos[algorithm.toLowerCase()] || {
            color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
            label: algorithm,
        }
    );
};

const getStatusBadge = (key: Key) => {
    if (key.is_revoked) {
        return {
            color: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
            label: 'Revoked',
            icon: XCircle,
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
            icon: Calendar,
        };
    }
    return {
        color: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        label: 'Active',
        icon: CheckCircle2,
    };
};
</script>

<template>
    <Head title="My Keys" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">My Keys</h1>
                    <p class="text-sm text-muted-foreground">
                        Manage your cryptographic key pairs
                    </p>
                </div>
                <Button as-child>
                    <Link :href="route('keys.create')">
                        <Plus class="mr-2 h-4 w-4" />
                        Generate New Key
                    </Link>
                </Button>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-3">
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <CardTitle class="text-sm font-medium"
                            >Total Keys</CardTitle
                        >
                        <KeyRound class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ keys.length }}</div>
                        <p class="text-xs text-muted-foreground">
                            Active:
                            {{
                                keys.filter((k) => k.is_active && !k.is_revoked)
                                    .length
                            }}
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <CardTitle class="text-sm font-medium"
                            >Default Key</CardTitle
                        >
                        <Star class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{
                                keys.find((k) => k.is_default)?.key_name ||
                                'None'
                            }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Used for new signatures by default
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <CardTitle class="text-sm font-medium"
                            >Expiring Soon</CardTitle
                        >
                        <Calendar class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{
                                keys.filter((k) => {
                                    if (!k.valid_to) return false;
                                    const daysLeft =
                                        (new Date(k.valid_to).getTime() -
                                            new Date().getTime()) /
                                        (1000 * 3600 * 24);
                                    return daysLeft > 0 && daysLeft < 30;
                                }).length
                            }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Keys expiring within 30 days
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Keys Table -->
            <Card>
                <CardHeader>
                    <CardTitle>All Keys</CardTitle>
                    <CardDescription>
                        {{ keys.length }} key{{
                            keys.length !== 1 ? 's' : ''
                        }}
                        found
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name / Fingerprint</TableHead>
                                <TableHead>Algorithm</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead>Valid Until</TableHead>
                                <TableHead class="text-right"
                                    >Actions</TableHead
                                >
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="keys.length === 0">
                                <TableCell colspan="6" class="h-32 text-center">
                                    <div
                                        class="flex flex-col items-center justify-center text-muted-foreground"
                                    >
                                        <KeyRound
                                            class="mb-2 h-8 w-8 opacity-50"
                                        />
                                        <p>No keys found</p>
                                        <Button
                                            variant="link"
                                            as-child
                                            class="mt-2"
                                        >
                                            <Link :href="route('keys.create')"
                                                >Generate your first key</Link
                                            >
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>

                            <TableRow v-for="key in keys" :key="key.id">
                                <TableCell>
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium">{{
                                                key.key_name
                                            }}</span>
                                            <Badge
                                                v-if="key.is_default"
                                                variant="outline"
                                                class="text-xs"
                                            >
                                                <Star
                                                    class="mr-1 h-3 w-3 fill-current"
                                                />
                                                Default
                                            </Badge>
                                        </div>
                                        <div
                                            class="mt-1 flex items-center gap-1"
                                        >
                                            <span
                                                class="font-mono text-xs text-muted-foreground"
                                            >
                                                {{ key.fingerprint }}
                                            </span>
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger as-child>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            class="h-5 w-5"
                                                            @click="
                                                                copyToClipboard(
                                                                    key.fingerprint,
                                                                )
                                                            "
                                                        >
                                                            <Copy
                                                                class="h-3 w-3"
                                                            />
                                                        </Button>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        <p>Copy fingerprint</p>
                                                    </TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </div>
                                    </div>
                                </TableCell>

                                <TableCell>
                                    <Badge
                                        :class="
                                            getAlgorithmBadge(
                                                key.algorithm_used,
                                            ).color
                                        "
                                    >
                                        {{
                                            getAlgorithmBadge(
                                                key.algorithm_used,
                                            ).label
                                        }}
                                        {{ key.key_size }}
                                    </Badge>
                                </TableCell>

                                <TableCell>
                                    <Badge :class="getStatusBadge(key).color">
                                        <component
                                            :is="getStatusBadge(key).icon"
                                            class="mr-1 h-3 w-3"
                                        />
                                        {{ getStatusBadge(key).label }}
                                    </Badge>
                                </TableCell>

                                <TableCell>
                                    <span class="text-sm">{{
                                        key.created_at
                                    }}</span>
                                </TableCell>

                                <TableCell>
                                    <span v-if="key.valid_to" class="text-sm">
                                        {{ key.valid_to }}
                                    </span>
                                    <span
                                        v-else
                                        class="text-sm text-muted-foreground"
                                    >
                                        Never expires
                                    </span>
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
                                                            'keys.show',
                                                            key.id,
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
                                                            'keys.download',
                                                            key.id,
                                                        )
                                                    "
                                                    class="flex items-center"
                                                >
                                                    <Download
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Download Public Key
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="!key.is_default"
                                                class="flex items-center"
                                            >
                                                <Link
                                                    :href="
                                                        route(
                                                            'keys.set-default',
                                                            key.id,
                                                        )
                                                    "
                                                    method="post"
                                                    as="button"
                                                    class="flex items-center"
                                                >
                                                    <Star
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Set as Default
                                                </Link>
                                            </DropdownMenuItem>

                                            <DropdownMenuSeparator />
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
