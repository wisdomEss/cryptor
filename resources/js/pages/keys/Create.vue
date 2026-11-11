<!-- resources/js/pages/Keys/Create.vue -->
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    KeyRound,
    Info,
    AlertCircle,
    ChevronLeft,
    LoaderCircle,
    CheckCircle2,
    Download,
    Copy,
} from 'lucide-vue-next';
import { ref, computed } from 'vue';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    FormControl,
    FormDescription,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

// Configuration Ziggy
const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

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
        title: 'Generate Key',
        href: route('keys.create'),
    },
];

interface KeyForm {
    key_name: string;
    algorithm: 'rsa' | 'ecdsa';
    key_size: '2048' | '4096';
    valid_days: string;
    set_as_default: boolean;
    certificate_data?: string;
    certificate_issuer?: string;
}

const form = useForm<KeyForm>({
    key_name: '',
    algorithm: 'rsa',
    key_size: '2048',
    valid_days: '365',
    set_as_default: false,
    certificate_data: '',
    certificate_issuer: '',
});

const showAdvanced = ref(false);
const showCertificate = ref(false);
const generatedKey = ref<null | {
    id: number;
    key_name: string;
    fingerprint: string;
    public_key: string;
    private_key?: string;
}>(null);

const isSubmitting = computed(() => form.processing);

const algorithmDescriptions = {
    rsa: {
        title: 'RSA',
        description:
            'Rivest-Shamir-Adleman - Standard industriel largement supporté',
        strengths: [
            'Très compatible',
            'Bon pour le chiffrement',
            'Testé et approuvé',
        ],
        weaknesses: [
            'Plus lent que ECDSA',
            'Clés plus longues pour même sécurité',
        ],
    },
    ecdsa: {
        title: 'ECDSA',
        description:
            'Elliptic Curve Digital Signature Algorithm - Plus rapide avec des clés plus courtes',
        strengths: ['Clés plus courtes', 'Plus rapide', 'Bonne sécurité'],
        weaknesses: ['Moins compatible que RSA', 'Plus complexe'],
    },
};

const submitForm = () => {
    form.post(route('keys.store'), {
        onSuccess: (page) => {
            // Récupérer la clé générée depuis la réponse
            const key = page.props.key as any;
            if (key) {
                generatedKey.value = key;
            }
        },
    });
};

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
};

const downloadKey = (content: string, filename: string) => {
    const blob = new Blob([content], { type: 'application/x-pem-file' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
};

// Reset form for new key
const resetForm = () => {
    generatedKey.value = null;
    form.reset();
    form.clearErrors();
};
</script>

<template>
    <Head title="Generate Key Pair" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" as-child>
                    <Link :href="route('keys.index')">
                        <ChevronLeft class="h-4 w-4" />
                    </Link>
                </Button>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">
                        Generate Key Pair
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Create a new cryptographic key pair for signing
                        documents
                    </p>
                </div>
            </div>

            <!-- Success State - Key Generated -->
            <div v-if="generatedKey" class="mx-auto w-full max-w-3xl">
                <Card>
                    <CardHeader>
                        <div class="flex items-center gap-2">
                            <CheckCircle2
                                class="h-6 w-6 text-green-600 dark:text-green-500"
                            />
                            <CardTitle>Key Generated Successfully</CardTitle>
                        </div>
                        <CardDescription>
                            Your new key pair has been created and securely
                            stored.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <Alert>
                            <AlertCircle class="h-4 w-4" />
                            <AlertTitle>Important</AlertTitle>
                            <AlertDescription>
                                Save your private key now. You won't be able to
                                retrieve it again!
                            </AlertDescription>
                        </Alert>

                        <!-- Key Details -->
                        <div class="space-y-4">
                            <div class="grid gap-2">
                                <div class="text-sm font-medium">Key Name</div>
                                <div class="text-sm">
                                    {{ generatedKey.key_name }}
                                </div>
                            </div>
                            <Separator />

                            <div class="grid gap-2">
                                <div class="text-sm font-medium">
                                    Fingerprint
                                </div>
                                <div class="flex items-center gap-2">
                                    <code
                                        class="relative rounded bg-muted px-2 py-1 font-mono text-sm"
                                    >
                                        {{ generatedKey.fingerprint }}
                                    </code>
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    class="h-8 w-8"
                                                    @click="
                                                        copyToClipboard(
                                                            generatedKey.fingerprint,
                                                        )
                                                    "
                                                >
                                                    <Copy class="h-4 w-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                Copy fingerprint
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </div>
                            </div>

                            <!-- Private Key Download -->
                            <div
                                v-if="generatedKey.private_key"
                                class="space-y-2"
                            >
                                <div class="text-sm font-medium">
                                    Private Key
                                </div>
                                <Button
                                    variant="outline"
                                    @click="
                                        downloadKey(
                                            generatedKey.private_key!,
                                            `${generatedKey.key_name}.key`,
                                        )
                                    "
                                >
                                    <Download class="mr-2 h-4 w-4" />
                                    Download Private Key
                                </Button>
                                <p class="text-xs text-muted-foreground">
                                    Store this key securely. It cannot be
                                    recovered if lost.
                                </p>
                            </div>

                            <!-- Public Key Download -->
                            <div class="space-y-2">
                                <div class="text-sm font-medium">
                                    Public Key
                                </div>
                                <Button
                                    variant="outline"
                                    @click="
                                        downloadKey(
                                            generatedKey.public_key,
                                            `${generatedKey.key_name}.pub`,
                                        )
                                    "
                                >
                                    <Download class="mr-2 h-4 w-4" />
                                    Download Public Key
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                    <CardFooter class="flex justify-between">
                        <Button variant="outline" @click="resetForm">
                            Generate Another Key
                        </Button>
                        <Button as-child>
                            <Link :href="route('keys.index')">
                                View All Keys
                            </Link>
                        </Button>
                    </CardFooter>
                </Card>
            </div>

            <!-- Generation Form -->
            <div v-else class="mx-auto w-full max-w-3xl">
                <form @submit.prevent="submitForm">
                    <Card>
                        <CardHeader>
                            <CardTitle>Key Configuration</CardTitle>
                            <CardDescription>
                                Choose the algorithm and parameters for your key
                                pair
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <!-- Key Name -->
                            <FormField
                                v-slot="{ componentField }"
                                name="key_name"
                            >
                                <FormItem>
                                    <FormLabel>Key Name</FormLabel>
                                    <FormControl>
                                        <Input
                                            v-bind="componentField"
                                            v-model="form.key_name"
                                            placeholder="e.g., My Signing Key 2026"
                                        />
                                    </FormControl>
                                    <FormDescription>
                                        A friendly name to identify this key
                                    </FormDescription>
                                    <FormMessage v-if="form.errors.key_name">
                                        {{ form.errors.key_name }}
                                    </FormMessage>
                                </FormItem>
                            </FormField>

                            <!-- Algorithm Selection -->
                            <FormField name="algorithm">
                                <FormItem>
                                    <FormLabel>Algorithm</FormLabel>
                                    <FormControl>
                                        <Tabs
                                            v-model="form.algorithm"
                                            class="w-full"
                                        >
                                            <TabsList
                                                class="grid w-full grid-cols-2"
                                            >
                                                <TabsTrigger value="rsa"
                                                    >RSA</TabsTrigger
                                                >
                                                <TabsTrigger value="ecdsa"
                                                    >ECDSA</TabsTrigger
                                                >
                                            </TabsList>
                                            <TabsContent
                                                value="rsa"
                                                class="mt-4"
                                            >
                                                <div
                                                    class="rounded-lg border p-4"
                                                >
                                                    <h4 class="font-medium">
                                                        {{
                                                            algorithmDescriptions
                                                                .rsa.title
                                                        }}
                                                    </h4>
                                                    <p
                                                        class="mt-1 text-sm text-muted-foreground"
                                                    >
                                                        {{
                                                            algorithmDescriptions
                                                                .rsa.description
                                                        }}
                                                    </p>
                                                    <div
                                                        class="mt-3 grid grid-cols-2 gap-4"
                                                    >
                                                        <div>
                                                            <span
                                                                class="text-xs font-medium"
                                                                >Advantages</span
                                                            >
                                                            <ul
                                                                class="mt-1 list-inside list-disc text-xs text-muted-foreground"
                                                            >
                                                                <li
                                                                    v-for="item in algorithmDescriptions
                                                                        .rsa
                                                                        .strengths"
                                                                    :key="item"
                                                                >
                                                                    {{ item }}
                                                                </li>
                                                            </ul>
                                                        </div>
                                                        <div>
                                                            <span
                                                                class="text-xs font-medium"
                                                                >Considerations</span
                                                            >
                                                            <ul
                                                                class="mt-1 list-inside list-disc text-xs text-muted-foreground"
                                                            >
                                                                <li
                                                                    v-for="item in algorithmDescriptions
                                                                        .rsa
                                                                        .weaknesses"
                                                                    :key="item"
                                                                >
                                                                    {{ item }}
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                            </TabsContent>
                                            <TabsContent
                                                value="ecdsa"
                                                class="mt-4"
                                            >
                                                <div
                                                    class="rounded-lg border p-4"
                                                >
                                                    <h4 class="font-medium">
                                                        {{
                                                            algorithmDescriptions
                                                                .ecdsa.title
                                                        }}
                                                    </h4>
                                                    <p
                                                        class="mt-1 text-sm text-muted-foreground"
                                                    >
                                                        {{
                                                            algorithmDescriptions
                                                                .ecdsa
                                                                .description
                                                        }}
                                                    </p>
                                                    <div
                                                        class="mt-3 grid grid-cols-2 gap-4"
                                                    >
                                                        <div>
                                                            <span
                                                                class="text-xs font-medium"
                                                                >Advantages</span
                                                            >
                                                            <ul
                                                                class="mt-1 list-inside list-disc text-xs text-muted-foreground"
                                                            >
                                                                <li
                                                                    v-for="item in algorithmDescriptions
                                                                        .ecdsa
                                                                        .strengths"
                                                                    :key="item"
                                                                >
                                                                    {{ item }}
                                                                </li>
                                                            </ul>
                                                        </div>
                                                        <div>
                                                            <span
                                                                class="text-xs font-medium"
                                                                >Considerations</span
                                                            >
                                                            <ul
                                                                class="mt-1 list-inside list-disc text-xs text-muted-foreground"
                                                            >
                                                                <li
                                                                    v-for="item in algorithmDescriptions
                                                                        .ecdsa
                                                                        .weaknesses"
                                                                    :key="item"
                                                                >
                                                                    {{ item }}
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                            </TabsContent>
                                        </Tabs>
                                    </FormControl>
                                    <FormMessage v-if="form.errors.algorithm">
                                        {{ form.errors.algorithm }}
                                    </FormMessage>
                                </FormItem>
                            </FormField>

                            <!-- Key Size (RSA only) -->
                            <FormField
                                v-if="form.algorithm === 'rsa'"
                                name="key_size"
                            >
                                <FormItem>
                                    <FormLabel>Key Size</FormLabel>
                                    <FormControl>
                                        <RadioGroup
                                            v-model="form.key_size"
                                            class="flex gap-4"
                                        >
                                            <FormItem
                                                class="flex items-center space-y-0 space-x-2"
                                            >
                                                <FormControl>
                                                    <RadioGroupItem
                                                        value="2048"
                                                    />
                                                </FormControl>
                                                <FormLabel
                                                    class="cursor-pointer font-normal"
                                                >
                                                    2048 bits (recommended)
                                                </FormLabel>
                                            </FormItem>
                                            <FormItem
                                                class="flex items-center space-y-0 space-x-2"
                                            >
                                                <FormControl>
                                                    <RadioGroupItem
                                                        value="4096"
                                                    />
                                                </FormControl>
                                                <FormLabel
                                                    class="cursor-pointer font-normal"
                                                >
                                                    4096 bits (more secure)
                                                </FormLabel>
                                            </FormItem>

                                            5123
                                        </RadioGroup>
                                    </FormControl>
                                    <FormDescription>
                                        Larger keys are more secure but slower
                                        to generate and use
                                    </FormDescription>
                                    <FormMessage v-if="form.errors.key_size">
                                        {{ form.errors.key_size }}
                                    </FormMessage>
                                </FormItem>
                            </FormField>

                            <!-- Validity Period -->
                            <FormField name="valid_days">
                                <FormItem>
                                    <FormLabel>Validity Period</FormLabel>
                                    <FormControl>
                                        <Select v-model="form.valid_days">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select validity period"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="30"
                                                    >30 days</SelectItem
                                                >
                                                <SelectItem value="90"
                                                    >90 days</SelectItem
                                                >
                                                <SelectItem value="180"
                                                    >6 months</SelectItem
                                                >
                                                <SelectItem value="365"
                                                    >1 year</SelectItem
                                                >
                                                <SelectItem value="730"
                                                    >2 years</SelectItem
                                                >
                                                <SelectItem value="0"
                                                    >Never expires</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </FormControl>
                                    <FormDescription>
                                        How long this key should remain valid
                                    </FormDescription>
                                    <FormMessage v-if="form.errors.valid_days">
                                        {{ form.errors.valid_days }}
                                    </FormMessage>
                                </FormItem>
                            </FormField>

                            <!-- Advanced Options -->
                            <div class="space-y-4">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    class="h-auto p-0 font-normal"
                                    @click="showAdvanced = !showAdvanced"
                                >
                                    {{
                                        showAdvanced ? 'Hide' : 'Show'
                                    }}
                                    advanced options
                                </Button>

                                <div v-if="showAdvanced" class="space-y-4">
                                    <!-- Set as Default -->
                                    <FormField
                                        v-slot="{ componentField }"
                                        name="set_as_default"
                                    >
                                        <FormItem
                                            class="flex flex-row items-start space-y-0 space-x-3"
                                        >
                                            <FormControl>
                                                <Checkbox
                                                    v-bind="componentField"
                                                    v-model:checked="
                                                        form.set_as_default
                                                    "
                                                />
                                            </FormControl>
                                            <div class="space-y-1 leading-none">
                                                <FormLabel
                                                    >Set as default
                                                    key</FormLabel
                                                >
                                                <FormDescription>
                                                    Use this key by default for
                                                    new signatures
                                                </FormDescription>
                                            </div>
                                        </FormItem>
                                    </FormField>

                                    <!-- Certificate Information Toggle -->
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        class="h-auto p-0 font-normal"
                                        @click="
                                            showCertificate = !showCertificate
                                        "
                                    >
                                        {{
                                            showCertificate ? 'Hide' : 'Add'
                                        }}
                                        certificate information
                                    </Button>

                                    <div
                                        v-if="showCertificate"
                                        class="space-y-4 border-l-2 pl-4"
                                    >
                                        <FormField
                                            v-slot="{ componentField }"
                                            name="certificate_issuer"
                                        >
                                            <FormItem>
                                                <FormLabel
                                                    >Certificate
                                                    Issuer</FormLabel
                                                >
                                                <FormControl>
                                                    <Input
                                                        v-bind="componentField"
                                                        v-model="
                                                            form.certificate_issuer
                                                        "
                                                        placeholder="e.g., DigiCert, Let's Encrypt"
                                                    />
                                                </FormControl>
                                            </FormItem>
                                        </FormField>

                                        <FormField
                                            v-slot="{ componentField }"
                                            name="certificate_data"
                                        >
                                            <FormItem>
                                                <FormLabel
                                                    >Certificate Data
                                                    (PEM)</FormLabel
                                                >
                                                <FormControl>
                                                    <Input
                                                        v-bind="componentField"
                                                        v-model="
                                                            form.certificate_data
                                                        "
                                                        placeholder="-----BEGIN CERTIFICATE-----..."
                                                    />
                                                </FormControl>
                                            </FormItem>
                                        </FormField>
                                    </div>
                                </div>
                            </div>

                            <!-- Security Notice -->
                            <Alert>
                                <Info class="h-4 w-4" />
                                <AlertTitle>Security Notice</AlertTitle>
                                <AlertDescription>
                                    Your private key will be encrypted and
                                    stored securely. However, you should always
                                    keep a backup of your keys in a safe
                                    location.
                                </AlertDescription>
                            </Alert>
                        </CardContent>
                        <CardFooter class="flex justify-between">
                            <Button type="button" variant="outline" as-child>
                                <Link :href="route('keys.index')">
                                    Cancel
                                </Link>
                            </Button>
                            <Button type="submit" :disabled="isSubmitting">
                                <LoaderCircle
                                    v-if="isSubmitting"
                                    class="mr-2 h-4 w-4 animate-spin"
                                />
                                <KeyRound v-else class="mr-2 h-4 w-4" />
                                Generate Key Pair
                            </Button>
                        </CardFooter>
                    </Card>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
