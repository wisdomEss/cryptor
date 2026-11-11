<!-- resources/js/pages/verifications/Create.vue -->
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import {
    ShieldCheck,
    Upload,
    FileText,
    CheckCircle2,
    XCircle,
    Loader2,
    X,
    User,
    Globe,
    FileSignature,
    Copy,
    History,
    Calendar,
    Fingerprint,
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

interface Signature {
    id: number;
    file_name: string;
    signer_name: string;
    signed_at: string;
}

defineProps<{
    recentSignatures?: Signature[];
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
        title: 'Verify File',
        href: route('verifications.create'),
    },
];

const form = useForm({
    file: null as File | null,
    signature_file: null as File | null,
    include_details: true,
});

const selectedFile = ref<File | null>(null);
const selectedSignatureFile = ref<File | null>(null);
const isDragging = ref(false);
const isDraggingSignature = ref(false);
const showResultDialog = ref(false);
const verificationResult = ref<null | {
    success: boolean;
    message: string;
    details: {
        signature_valid: boolean;
        document_integrity: boolean;
        signer: string;
        signed_at: string;
        algorithm: string;
        fingerprint: string;
        certificate_info?: any;
    };
    verification_id?: number;
    token?: string;
}>(null);

const isVerifying = ref(false);
const verificationProgress = ref(0);

const handleFileSelect = (event: Event) => {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        const file = input.files[0];
        selectedFile.value = file;
        form.file = file as any;
    }
};

const handleSignatureSelect = (event: Event) => {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        const file = input.files[0];
        selectedSignatureFile.value = file;
        form.signature_file = file as any;
    }
};

const handleDrop = (event: DragEvent, type: 'file' | 'signature') => {
    event.preventDefault();
    if (type === 'file') {
        isDragging.value = false;
    } else {
        isDraggingSignature.value = false;
    }

    if (event.dataTransfer?.files && event.dataTransfer.files[0]) {
        const file = event.dataTransfer.files[0];
        if (type === 'file') {
            selectedFile.value = file;
            form.file = file as any;
        } else {
            selectedSignatureFile.value = file;
            form.signature_file = file as any;
        }
    }
};

const removeFile = (type: 'file' | 'signature') => {
    if (type === 'file') {
        selectedFile.value = null;
        form.file = null;
    } else {
        selectedSignatureFile.value = null;
        form.signature_file = null;
    }
};

const submit = () => {
    isVerifying.value = true;
    verificationProgress.value = 0;

    // Simuler la progression
    const interval = setInterval(() => {
        verificationProgress.value += 10;
        if (verificationProgress.value >= 100) {
            clearInterval(interval);
        }
    }, 300);

    form.post(route('verifications.store'), {
        onSuccess: (page) => {
            console.log(page.props);
            clearInterval(interval);
            verificationProgress.value = 100;

            const result = page.props.verificationResult as any;
            if (result) {
                verificationResult.value = {
                    success: result.success,
                    message: result.message,
                    details: result.details,
                    verification_id: result.id,
                    token: result.token,
                };
                showResultDialog.value = true;
            }

            isVerifying.value = false;
        },
        onError: () => {
            clearInterval(interval);
            isVerifying.value = false;
            verificationResult.value = {
                success: false,
                message: 'Verification failed. Please try again.',
                details: {} as any,
            };
            showResultDialog.value = true;
        },
    });
};

const resetForm = () => {
    selectedFile.value = null;
    selectedSignatureFile.value = null;
    form.reset();
    verificationResult.value = null;
    showResultDialog.value = false;
};

const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
};

const getFileIcon = (filename: string) => {
    const ext = filename.split('.').pop()?.toLowerCase();
    if (ext === 'pdf') return FileText;
    if (['p7s', 'p7m'].includes(ext || '')) return FileSignature;
    return FileText;
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleString();
};
</script>

<template>
    <Head title="Verify File" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">
                        Verify File
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Verify the authenticity and integrity of a signed
                        document
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('verifications.index')">
                            <History class="mr-2 h-4 w-4" />
                            History
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Main Content -->
            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Left Column - Upload Form -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Upload Form -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Upload Files for Verification</CardTitle>
                            <CardDescription>
                                Upload the signed document and its signature
                                file
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <!-- Document Upload -->
                            <div class="space-y-2">
                                <Label>Document</Label>
                                <div
                                    v-if="!selectedFile"
                                    class="rounded-lg border-2 border-dashed p-6 transition-colors"
                                    :class="{
                                        'border-primary bg-primary/5':
                                            isDragging,
                                        'border-border hover:border-muted-foreground/50':
                                            !isDragging,
                                    }"
                                    @dragenter.prevent="isDragging = true"
                                    @dragover.prevent="isDragging = true"
                                    @dragleave.prevent="isDragging = false"
                                    @drop.prevent="(e) => handleDrop(e, 'file')"
                                >
                                    <div
                                        class="flex flex-col items-center justify-center text-center"
                                    >
                                        <div
                                            class="mb-2 rounded-full bg-primary/10 p-2"
                                        >
                                            <Upload
                                                class="h-5 w-5 text-primary"
                                            />
                                        </div>
                                        <p class="text-sm font-medium">
                                            Drop your document here
                                        </p>
                                        <p
                                            class="mb-2 text-xs text-muted-foreground"
                                        >
                                            or
                                        </p>
                                        <Input
                                            type="file"
                                            class="hidden"
                                            id="file-upload"
                                            @change="handleFileSelect"
                                            accept=".pdf,.doc,.docx,.txt,.xml,.json"
                                        />
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            as-child
                                        >
                                            <label
                                                for="file-upload"
                                                class="cursor-pointer"
                                            >
                                                Browse Files
                                            </label>
                                        </Button>
                                    </div>
                                </div>

                                <!-- File Preview -->
                                <div
                                    v-else
                                    class="flex items-start justify-between rounded-lg bg-muted/50 p-3"
                                >
                                    <div class="flex items-start gap-2">
                                        <div
                                            class="rounded bg-primary/10 p-1.5"
                                        >
                                            <component
                                                :is="
                                                    getFileIcon(
                                                        selectedFile.name,
                                                    )
                                                "
                                                class="h-4 w-4 text-primary"
                                            />
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium">
                                                {{ selectedFile.name }}
                                            </p>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    formatFileSize(
                                                        selectedFile.size,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-6 w-6"
                                        @click="removeFile('file')"
                                    >
                                        <X class="h-3 w-3" />
                                    </Button>
                                </div>
                            </div>

                            <!-- Signature File Upload -->
                            <div class="space-y-2">
                                <Label>Signature File (optional)</Label>
                                <div
                                    v-if="!selectedSignatureFile"
                                    class="rounded-lg border-2 border-dashed p-6 transition-colors"
                                    :class="{
                                        'border-primary bg-primary/5':
                                            isDraggingSignature,
                                        'border-border hover:border-muted-foreground/50':
                                            !isDraggingSignature,
                                    }"
                                    @dragenter.prevent="
                                        isDraggingSignature = true
                                    "
                                    @dragover.prevent="
                                        isDraggingSignature = true
                                    "
                                    @dragleave.prevent="
                                        isDraggingSignature = false
                                    "
                                    @drop.prevent="
                                        (e) => handleDrop(e, 'signature')
                                    "
                                >
                                    <div
                                        class="flex flex-col items-center justify-center text-center"
                                    >
                                        <div
                                            class="mb-2 rounded-full bg-primary/10 p-2"
                                        >
                                            <FileSignature
                                                class="h-5 w-5 text-primary"
                                            />
                                        </div>
                                        <p class="text-sm font-medium">
                                            Drop signature file here
                                        </p>
                                        <p
                                            class="mb-2 text-xs text-muted-foreground"
                                        >
                                            or
                                        </p>
                                        <Input
                                            type="file"
                                            class="hidden"
                                            id="signature-upload"
                                            @change="handleSignatureSelect"
                                            accept=".p7s,.p7m,.sig,.signature"
                                        />
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            as-child
                                        >
                                            <label
                                                for="signature-upload"
                                                class="cursor-pointer"
                                            >
                                                Browse Files
                                            </label>
                                        </Button>
                                    </div>
                                </div>

                                <!-- Signature File Preview -->
                                <div
                                    v-else
                                    class="flex items-start justify-between rounded-lg bg-muted/50 p-3"
                                >
                                    <div class="flex items-start gap-2">
                                        <div
                                            class="rounded bg-primary/10 p-1.5"
                                        >
                                            <FileSignature
                                                class="h-4 w-4 text-primary"
                                            />
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium">
                                                {{ selectedSignatureFile.name }}
                                            </p>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    formatFileSize(
                                                        selectedSignatureFile.size,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-6 w-6"
                                        @click="removeFile('signature')"
                                    >
                                        <X class="h-3 w-3" />
                                    </Button>
                                </div>
                            </div>

                            <!-- Options -->
                            <div class="space-y-3">
                                <div class="flex items-center space-x-2">
                                    <Checkbox
                                        id="include_details"
                                        v-model:checked="form.include_details"
                                    />
                                    <Label
                                        for="include_details"
                                        class="text-sm font-normal"
                                    >
                                        Include detailed verification report
                                    </Label>
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div v-if="isVerifying" class="space-y-2">
                                <div class="flex justify-between text-sm">
                                    <span>Verifying...</span>
                                    <span>{{ verificationProgress }}%</span>
                                </div>
                                <Progress
                                    :model-value="verificationProgress"
                                    class="h-2"
                                />
                            </div>
                        </CardContent>
                        <CardFooter>
                            <Button
                                type="submit"
                                class="w-full"
                                :disabled="isVerifying || !selectedFile"
                                @click="submit"
                            >
                                <Loader2
                                    v-if="isVerifying"
                                    class="mr-2 h-4 w-4 animate-spin"
                                />
                                <ShieldCheck v-else class="mr-2 h-4 w-4" />
                                {{
                                    isVerifying
                                        ? 'Verifying...'
                                        : 'Verify Signature'
                                }}
                            </Button>
                        </CardFooter>
                    </Card>
                </div>

                <!-- Right Column - Info & Recent Signatures -->
                <div class="space-y-6">
                    <!-- Info Card -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-sm font-medium"
                                >About Verification</CardTitle
                            >
                        </CardHeader>
                        <CardContent class="space-y-3 text-sm">
                            <div class="flex items-start gap-2">
                                <CheckCircle2
                                    class="mt-0.5 h-4 w-4 shrink-0 text-green-600"
                                />
                                <p>
                                    Verifies the cryptographic signature
                                    authenticity
                                </p>
                            </div>
                            <div class="flex items-start gap-2">
                                <CheckCircle2
                                    class="mt-0.5 h-4 w-4 shrink-0 text-green-600"
                                />
                                <p>
                                    Checks if the document has been modified
                                    since signing
                                </p>
                            </div>
                            <div class="flex items-start gap-2">
                                <CheckCircle2
                                    class="mt-0.5 h-4 w-4 shrink-0 text-green-600"
                                />
                                <p>Identifies the signer and signing time</p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Recent Signatures -->
                    <Card
                        v-if="recentSignatures && recentSignatures.length > 0"
                    >
                        <CardHeader>
                            <CardTitle class="text-sm font-medium"
                                >Recent Signatures</CardTitle
                            >
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <div
                                v-for="sig in recentSignatures"
                                :key="sig.id"
                                class="flex cursor-pointer items-center justify-between rounded-lg p-2 hover:bg-muted/50"
                                @click="
                                    $inertia.visit(
                                        route('verifications.create', {
                                            signature_id: sig.id,
                                        }),
                                    )
                                "
                            >
                                <div class="flex items-center gap-2">
                                    <FileText
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                    <div>
                                        <p class="text-sm font-medium">
                                            {{ sig.file_name }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ sig.signer_name }} •
                                            {{ sig.signed_at }}
                                        </p>
                                    </div>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-6 w-6"
                                >
                                    <ShieldCheck class="h-3 w-3" />
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Result Dialog -->
            <Dialog
                :open="showResultDialog"
                @update:open="showResultDialog = false"
            >
                <DialogContent class="sm:max-w-[600px]">
                    <DialogHeader>
                        <div class="flex items-center gap-3">
                            <div
                                :class="
                                    verificationResult?.success
                                        ? 'text-green-600'
                                        : 'text-red-600'
                                "
                            >
                                <component
                                    :is="
                                        verificationResult?.success
                                            ? CheckCircle2
                                            : XCircle
                                    "
                                    class="h-8 w-8"
                                />
                            </div>
                            <div>
                                <DialogTitle>
                                    {{
                                        verificationResult?.success
                                            ? 'Verification Successful'
                                            : 'Verification Failed'
                                    }}
                                </DialogTitle>
                                <DialogDescription>
                                    {{ verificationResult?.message }}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div
                        v-if="verificationResult?.details"
                        class="space-y-4 py-4"
                    >
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <div class="text-sm text-muted-foreground">
                                    Signature Status
                                </div>
                                <Badge
                                    :class="
                                        verificationResult.details
                                            .signature_valid
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-red-100 text-red-800'
                                    "
                                >
                                    {{
                                        verificationResult.details
                                            .signature_valid
                                            ? 'Valid'
                                            : 'Invalid'
                                    }}
                                </Badge>
                            </div>
                            <div class="space-y-2">
                                <div class="text-sm text-muted-foreground">
                                    Document Integrity
                                </div>
                                <Badge
                                    :class="
                                        verificationResult.details
                                            .document_integrity
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-red-100 text-red-800'
                                    "
                                >
                                    {{
                                        verificationResult.details
                                            .document_integrity
                                            ? 'Intact'
                                            : 'Modified'
                                    }}
                                </Badge>
                            </div>
                        </div>

                        <Separator />

                        <div class="space-y-3">
                            <div class="flex items-center gap-2">
                                <User class="h-4 w-4 text-muted-foreground" />
                                <span class="text-sm"
                                    ><span class="text-muted-foreground"
                                        >Signer:</span
                                    >
                                    {{
                                        verificationResult.details.signer
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
                                            verificationResult.details
                                                .signed_at,
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
                                        verificationResult.details.algorithm
                                    }}</span
                                >
                            </div>
                            <div class="flex items-center gap-2">
                                <Fingerprint
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                <span class="text-sm"
                                    ><span class="text-muted-foreground"
                                        >Fingerprint:</span
                                    ></span
                                >
                                <code
                                    class="ml-2 rounded bg-muted px-2 py-1 font-mono text-xs"
                                >
                                    {{ verificationResult.details.fingerprint }}
                                </code>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-6 w-6"
                                    @click="
                                        copyToClipboard(
                                            verificationResult.details
                                                .fingerprint,
                                        )
                                    "
                                >
                                    <Copy class="h-3 w-3" />
                                </Button>
                            </div>
                        </div>

                        <!-- Public Verification Link -->
                        <div
                            v-if="verificationResult.token"
                            class="mt-4 rounded-lg bg-muted p-3"
                        >
                            <div class="mb-2 flex items-center gap-2">
                                <Globe class="h-4 w-4 text-muted-foreground" />
                                <span class="text-sm font-medium"
                                    >Public Verification Link</span
                                >
                            </div>
                            <div class="flex items-center gap-2">
                                <code
                                    class="flex-1 overflow-auto rounded bg-background p-2 text-xs"
                                >
                                    {{
                                        route('verifications.public', {
                                            token: verificationResult.token,
                                        })
                                    }}
                                </code>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        copyToClipboard(
                                            route('verifications.public', {
                                                token: verificationResult.token,
                                            }),
                                        )
                                    "
                                >
                                    <Copy class="h-3 w-3" />
                                </Button>
                            </div>
                        </div>
                    </div>

                    <DialogFooter class="flex gap-2 sm:justify-between">
                        <Button
                            variant="outline"
                            @click="showResultDialog = false"
                        >
                            Close
                        </Button>
                        <div class="flex gap-2">
                            <Button variant="outline" @click="resetForm">
                                Verify Another File
                            </Button>
                            <Button
                                v-if="verificationResult?.verification_id"
                                as-child
                            >
                                <Link
                                    :href="
                                        route(
                                            'verifications.show',
                                            verificationResult.verification_id,
                                        )
                                    "
                                >
                                    View Full Report
                                </Link>
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
