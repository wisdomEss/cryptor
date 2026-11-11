<!-- resources/js/pages/keys/Edit.vue -->
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

interface KeyEditData {
    id: number;
    key_name: string;
    algorithm_used: string;
    key_size: number;
    is_default: boolean;
    is_active: boolean;
    is_revoked: boolean;
    valid_to?: string | null;
    certificate_issuer?: string | null;
    certificate_data?: string | null;
}

const props = defineProps<{
    keyData: KeyEditData;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Keys', href: route('keys.index') },
    {
        title: props.keyData.key_name,
        href: route('keys.show', props.keyData.id),
    },
    { title: 'Edit', href: route('keys.edit', props.keyData.id) },
];

const form = useForm({
    key_name: props.keyData.key_name,
    is_default: props.keyData.is_default,
    valid_to: props.keyData.valid_to ?? '',
    certificate_issuer: props.keyData.certificate_issuer ?? '',
    certificate_data: props.keyData.certificate_data ?? '',
});

const submit = () => {
    form.put(route('keys.update', props.keyData.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Edit Key" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
            <Button variant="ghost" size="sm" as-child>
                <a :href="route('keys.show', keyData.id)">
                    <ChevronLeft class="mr-1 h-4 w-4" />
                    Back to key
                </a>
            </Button>

            <Card>
                <CardHeader>
                    <CardTitle>Edit Key</CardTitle>
                    <CardDescription>
                        Update the metadata for "{{ keyData.key_name }}". The
                        cryptographic material itself cannot be changed; revoke
                        the key and create a new one if you need different key
                        material.
                    </CardDescription>
                </CardHeader>

                <form @submit.prevent="submit">
                    <CardContent class="space-y-6">
                        <div class="space-y-2">
                            <Label for="key_name">Key name</Label>
                            <Input
                                id="key_name"
                                v-model="form.key_name"
                                required
                                maxlength="255"
                            />
                            <InputError :message="form.errors.key_name" />
                        </div>

                        <div
                            class="flex items-center justify-between rounded-lg border p-4"
                        >
                            <div class="space-y-0.5">
                                <Label for="is_default">Default key</Label>
                                <p class="text-sm text-muted-foreground">
                                    Use this key by default when signing new
                                    documents.
                                </p>
                            </div>
                            <input
                                id="is_default"
                                v-model="form.is_default"
                                type="checkbox"
                                class="h-4 w-4"
                                :disabled="
                                    keyData.is_revoked || !keyData.is_active
                                "
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="valid_to">Valid until</Label>
                            <Input
                                id="valid_to"
                                v-model="form.valid_to"
                                type="date"
                            />
                            <InputError :message="form.errors.valid_to" />
                        </div>

                        <div class="space-y-2">
                            <Label for="certificate_issuer"
                                >Certificate issuer</Label
                            >
                            <Input
                                id="certificate_issuer"
                                v-model="form.certificate_issuer"
                                maxlength="255"
                            />
                            <InputError
                                :message="form.errors.certificate_issuer"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="certificate_data"
                                >Certificate data (PEM)</Label
                            >
                            <Textarea
                                id="certificate_data"
                                v-model="form.certificate_data"
                                rows="6"
                                class="font-mono text-xs"
                            />
                            <InputError
                                :message="form.errors.certificate_data"
                            />
                        </div>
                    </CardContent>

                    <CardFooter class="flex justify-end gap-3">
                        <Button type="button" variant="outline" as-child>
                            <a :href="route('keys.show', keyData.id)">Cancel</a>
                        </Button>
                        <Button type="submit" :disabled="form.processing"
                            >Save changes</Button
                        >
                    </CardFooter>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
