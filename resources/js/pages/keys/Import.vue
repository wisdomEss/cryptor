<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ChevronLeft, Upload } from 'lucide-vue-next';
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
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Keys', href: route('keys.index') },
    { title: 'Import', href: route('keys.import.form') },
];

const form = useForm<{
    file: File | null;
    key_name: string;
    passphrase: string;
}>({
    file: null,
    key_name: '',
    passphrase: '',
});

const onFileChange = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.file = file;

    if (file && !form.key_name) {
        form.key_name = file.name.replace(/\.(p12|pfx)$/i, '');
    }
};

const submit = () => {
    form.post(route('keys.import'), {
        forceFormData: true,
        onFinish: () => form.reset('passphrase'),
    });
};
</script>

<template>
    <Head title="Import a key" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
            <Button variant="ghost" size="sm" as-child>
                <a :href="route('keys.index')">
                    <ChevronLeft class="mr-1 h-4 w-4" />
                    Back to keys
                </a>
            </Button>

            <Card>
                <CardHeader>
                    <CardTitle>Import an encrypted backup</CardTitle>
                    <CardDescription>
                        Restore a key from a PKCS#12 file (.p12 / .pfx), for
                        example a backup exported from CRYPTOR. RSA (2048 bits
                        or more) and ECDSA P-256 keys are supported. The file is
                        read on the server and is never stored.
                    </CardDescription>
                </CardHeader>

                <form @submit.prevent="submit">
                    <CardContent class="space-y-6">
                        <div class="space-y-2">
                            <Label for="file">Backup file</Label>
                            <Input
                                id="file"
                                type="file"
                                accept=".p12,.pfx"
                                required
                                @change="onFileChange"
                            />
                            <InputError :message="form.errors.file" />
                        </div>

                        <div class="space-y-2">
                            <Label for="passphrase">Backup passphrase</Label>
                            <Input
                                id="passphrase"
                                v-model="form.passphrase"
                                type="password"
                                autocomplete="off"
                                required
                            />
                            <InputError :message="form.errors.passphrase" />
                        </div>

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
                    </CardContent>

                    <CardFooter class="flex justify-end gap-3">
                        <Button type="button" variant="outline" as-child>
                            <a :href="route('keys.index')">Cancel</a>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            <Upload class="mr-2 h-4 w-4" />
                            Import key
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
