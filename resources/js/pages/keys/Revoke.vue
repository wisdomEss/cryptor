<!-- resources/js/pages/keys/Revoke.vue -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { Ban, Copy, Eye, KeyRound } from 'lucide-vue-next';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

interface RevokedKey {
    id: number;
    key_name: string;
    algorithm_used: string;
    key_size: number;
    revoked_at?: string;
    fingerprint: string;
}

defineProps<{
    keys: RevokedKey[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'My Keys', href: route('keys.index') },
    { title: 'Revoked', href: route('keys.revoked') },
];

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
};
</script>

<template>
    <Head title="Revoked Keys" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Revoked Keys</h1>
                <p class="text-sm text-muted-foreground">
                    Keys that have been revoked and can no longer be used to
                    sign documents
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Revoked Keys</CardTitle>
                    <CardDescription>
                        {{ keys.length }} key{{
                            keys.length !== 1 ? 's' : ''
                        }}
                        revoked
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name / Fingerprint</TableHead>
                                <TableHead>Algorithm</TableHead>
                                <TableHead>Revoked at</TableHead>
                                <TableHead class="text-right"
                                    >Actions</TableHead
                                >
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="keys.length === 0">
                                <TableCell colspan="4" class="h-32 text-center">
                                    <div
                                        class="flex flex-col items-center justify-center text-muted-foreground"
                                    >
                                        <Ban class="mb-2 h-8 w-8 opacity-50" />
                                        <p>No revoked keys</p>
                                    </div>
                                </TableCell>
                            </TableRow>

                            <TableRow v-for="key in keys" :key="key.id">
                                <TableCell>
                                    <div class="flex flex-col">
                                        <span class="font-medium">{{
                                            key.key_name
                                        }}</span>
                                        <div
                                            class="mt-1 flex items-center gap-1"
                                        >
                                            <span
                                                class="font-mono text-xs text-muted-foreground"
                                            >
                                                {{ key.fingerprint }}
                                            </span>
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
                                                <Copy class="h-3 w-3" />
                                            </Button>
                                        </div>
                                    </div>
                                </TableCell>

                                <TableCell>
                                    <Badge variant="outline">
                                        <KeyRound class="mr-1 h-3 w-3" />
                                        {{ key.algorithm_used.toUpperCase() }}
                                        {{ key.key_size }}
                                    </Badge>
                                </TableCell>

                                <TableCell>
                                    <span class="text-sm text-muted-foreground">
                                        {{ key.revoked_at ?? 'Unknown' }}
                                    </span>
                                </TableCell>

                                <TableCell class="text-right">
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link
                                            :href="route('keys.show', key.id)"
                                        >
                                            <Eye class="mr-2 h-4 w-4" />
                                            View
                                        </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
