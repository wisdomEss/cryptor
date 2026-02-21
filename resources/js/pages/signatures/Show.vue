<!-- resources/js/pages/Signatures/Show.vue -->
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';
import { ref, computed } from 'vue';
import {
    FileSignature,
    Shield,
    KeyRound,
    MapPin,
    FileText,
    AlertCircle,
    CheckCircle2,
    XCircle,
    Clock,
    Download,
    Copy,
    ChevronLeft,
    User,
    Calendar,
    Hash,
    Fingerprint,
    Eye,
    QrCode,
    Globe,
    Mail,
    Smartphone,
    Lock,
    Unlock,
    Info,
    FileJson,
    FileCode,
    FileDigit,
    FileArchive,
    Image,
    Database,
    FileSpreadsheet,
    RefreshCw,
    Ban,
    RotateCcw
} from 'lucide-vue-next';

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
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Progress } from '@/components/ui/progress';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

interface Signature {
    id: number;
    signature_value: string;
    signature_format: 'pkcs7' | 'cms' | 'pdf';
    algorithm_used: string;
    signing_reason: string | null;
    signing_location: string | null;
    contact_info: string | null;
    visual_signature_data: string | null;
    is_valid: boolean;
    validation_status: 'pending' | 'valid' | 'invalid' | 'revoked';
    validation_message: string | null;
    signed_at: string;
    validated_at: string | null;
    revoked_at: string | null;
    signing_ip: string;
    signing_device_info: string;
    nonce_value: string;
    timestamp_token: string | null;
    verification_count: number;
    file: {
        id: number;
        original_file_name: string;
        file_size: number;
        mime_type: string;
        file_extension: string;
        file_hash: string;
        expires_at: string | null;
        created_at: string;
    };
    key_pair: {
        id: number;
        key_name: string;
        algorithm_used: string;
        key_size: number;
        fingerprint: string;
        public_key: string;
    };
    signer: {
        id: number;
        name: string;
        email: string;
    };
    verifications: Array<{
        id: number;
        result: boolean;
        verified_at: string;
        verification_type: string;
        verifier: {
            name: string;
            email: string;
        } | null;
    }>;
}

const props = defineProps<{
    signature: Signature;
}>();

const showFullSignature = ref(false);
const activeTab = ref('details');
const showRevokeDialog = ref(false);
const showValidateDialog = ref(false);
const isRevoking = ref(false);
const isValidating = ref(false);

const revokeForm = useForm({
    revocation_reason: '',
});

const validateForm = useForm({
    validation_notes: '',
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Signatures',
        href: route('signatures.index'),
    },
    {
        title: `Signature #${props.signature.id}`,
        href: route('signatures.show', props.signature.id),
    },
];

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
};

const downloadFile = (content: string, filename: string, type: string) => {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
};

const downloadSignature = () => {
    const content = props.signature.signature_value;
    const filename = `signature_${props.signature.id}.p7s`;
    downloadFile(content, filename, 'application/pkcs7-signature');
};

const downloadDocument = () => {
    window.open(route('files.download', props.signature.file.id), '_blank');
};

const handleRevoke = () => {
    isRevoking.value = true;
    revokeForm.post(route('signatures.revoke', props.signature.id), {
        onSuccess: () => {
            showRevokeDialog.value = false;
            revokeForm.reset();
            isRevoking.value = false;
            // Recharger la page pour voir les changements
            window.location.reload();
        },
        onError: () => {
            isRevoking.value = false;
        }
    });
};

const handleValidate = () => {
    isValidating.value = true;
    validateForm.put(route('signatures.update', props.signature.id), {
        onSuccess: () => {
            showValidateDialog.value = false;
            validateForm.reset();
            isValidating.value = false;
            // Recharger la page pour voir les changements
            window.location.reload();
        },
        onError: () => {
            isValidating.value = false;
        }
    });
};

const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const formatDate = (date: string | null) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleString();
};

const getStatusConfig = (signature: Signature) => {
    if (signature.revoked_at) {
        return {
            color: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
            icon: Ban,
            label: 'Revoked'
        };
    }
    switch (signature.validation_status) {
        case 'valid':
            return {
                color: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
                icon: CheckCircle2,
                label: 'Valid'
            };
        case 'invalid':
            return {
                color: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
                icon: XCircle,
                label: 'Invalid'
            };
        case 'pending':
            return {
                color: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
                icon: Clock,
                label: 'Pending Validation'
            };
        default:
            return {
                color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                icon: AlertCircle,
                label: 'Unknown'
            };
    }
};

const getFileIcon = (extension: string) => {
    const icons: Record<string, any> = {
        'pdf': FileText,
        'doc': FileText,
        'docx': FileText,
        'txt': FileText,
        'jpg': Image,
        'jpeg': Image,
        'png': Image,
        'gif': Image,
        'zip': FileArchive,
        'tar': FileArchive,
        'gz': FileArchive,
        'rar': FileArchive,
        '7z': FileArchive,
        'xml': FileCode,
        'json': FileJson,
        'sql': Database,
        'csv': FileSpreadsheet,
        'xls': FileSpreadsheet,
        'xlsx': FileSpreadsheet,
    };
    return icons[extension.toLowerCase()] || FileText;
};

const getFormatIcon = (format: string) => {
    const icons: Record<string, any> = {
        'pkcs7': FileSignature,
        'cms': Shield,
        'pdf': FileText,
    };
    return icons[format] || FileSignature;
};

const getAlgorithmColor = (algorithm: string) => {
    const colors: Record<string, string> = {
        'rsa': 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        'ecdsa': 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    };
    return colors[algorithm.toLowerCase()] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
};

const statusConfig = computed(() => getStatusConfig(props.signature));
const fileIcon = computed(() => getFileIcon(props.signature.file.file_extension));
const formatIcon = computed(() => getFormatIcon(props.signature.signature_format));

// Calculer le niveau de confiance basé sur les vérifications
const trustLevel = computed(() => {
    if (props.signature.revoked_at) return 0;
    if (props.signature.validation_status === 'invalid') return 25;
    if (props.signature.validation_status === 'pending') return 50;
    if (props.signature.verification_count === 0) return 75;
    return 100;
});

// Vérifier si l'utilisateur peut valider/révoquer
const canValidate = computed(() => {
    return props.signature.validation_status === 'pending' && !props.signature.revoked_at;
});

const canRevoke = computed(() => {
    return !props.signature.revoked_at && props.signature.validation_status !== 'revoked';
});

const canReactivate = computed(() => {
    return props.signature.revoked_at && props.signature.validation_status === 'revoked';
});
</script>

<template>
    <Head :title="`Signature #${signature.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header avec navigation -->
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" as-child>
                    <Link :href="route('signatures.index')">
                        <ChevronLeft class="h-4 w-4" />
                    </Link>
                </Button>
                <div class="flex-1">
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="text-2xl font-bold tracking-tight">
                            Signature Details
                        </h1>
                        <Badge :class="statusConfig.color" class="text-sm">
                            <component :is="statusConfig.icon" class="h-3 w-3 mr-1" />
                            {{ statusConfig.label }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground mt-1">
                        View and manage this digital signature
                    </p>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <!-- Boutons d'action selon le statut -->
                    <Button v-if="canValidate" variant="default" @click="showValidateDialog = true">
                        <CheckCircle2 class="mr-2 h-4 w-4" />
                        Validate
                    </Button>
                    
                    <Button v-if="canRevoke" variant="destructive" @click="showRevokeDialog = true">
                        <Ban class="mr-2 h-4 w-4" />
                        Revoke
                    </Button>
                    
                    <Button variant="outline" @click="downloadDocument">
                        <Download class="mr-2 h-4 w-4" />
                        Document
                    </Button>
                    
                    <Button variant="outline" @click="downloadSignature">
                        <Download class="mr-2 h-4 w-4" />
                        Signature
                    </Button>
                    
                    <Button variant="outline" as-child>
                        <Link :href="route('verifications.create', { signature_id: signature.id })">
                            <Shield class="mr-2 h-4 w-4" />
                            Verify
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Trust Level Indicator -->
            <Card>
                <CardContent class="pt-6">
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">Trust Level</span>
                            <span class="font-medium">{{ trustLevel }}%</span>
                        </div>
                        <Progress :model-value="trustLevel" class="h-2" />
                    </div>
                </CardContent>
            </Card>

            <!-- Alert pour signature révoquée -->
            <Alert v-if="signature.revoked_at" variant="destructive">
                <Ban class="h-4 w-4" />
                <AlertTitle>Signature Revoked</AlertTitle>
                <AlertDescription>
                    This signature was revoked on {{ formatDate(signature.revoked_at) }}.
                    {{ signature.validation_message || 'No reason provided.' }}
                </AlertDescription>
            </Alert>

            <!-- Main Content Tabs -->
            <Tabs v-model="activeTab" class="space-y-6">
                <TabsList class="grid w-full grid-cols-4 lg:w-[600px]">
                    <TabsTrigger value="details">
                        <Info class="h-4 w-4 mr-2" />
                        Details
                    </TabsTrigger>
                    <TabsTrigger value="document">
                        <FileText class="h-4 w-4 mr-2" />
                        Document
                    </TabsTrigger>
                    <TabsTrigger value="key">
                        <KeyRound class="h-4 w-4 mr-2" />
                        Key
                    </TabsTrigger>
                    <TabsTrigger value="verifications">
                        <Shield class="h-4 w-4 mr-2" />
                        Verifications
                    </TabsTrigger>
                </TabsList>

                <!-- Details Tab (contenu inchangé) -->
                <TabsContent value="details" class="space-y-6">
                    <!-- Warning Alert for invalid -->
                    <Alert v-if="signature.validation_status === 'invalid' && !signature.revoked_at" variant="destructive">
                        <AlertCircle class="h-4 w-4" />
                        <AlertTitle>Invalid Signature</AlertTitle>
                        <AlertDescription>
                            {{ signature.validation_message || 'This signature is invalid.' }}
                        </AlertDescription>
                    </Alert>

                    <div class="grid gap-6 md:grid-cols-2">
                        <!-- Signature Information -->
                        <Card>
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2 text-lg">
                                    <FileSignature class="h-5 w-5" />
                                    Signature Information
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <div class="text-sm text-muted-foreground">Format</div>
                                        <div class="flex items-center gap-2 mt-1">
                                            <component :is="formatIcon" class="h-4 w-4" />
                                            <span class="font-medium">{{ signature.signature_format.toUpperCase() }}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-muted-foreground">Algorithm</div>
                                        <Badge :class="getAlgorithmColor(signature.algorithm_used)" class="mt-1">
                                            {{ signature.algorithm_used }}
                                        </Badge>
                                    </div>
                                    <div>
                                        <div class="text-sm text-muted-foreground">Signed At</div>
                                        <div class="flex items-center gap-1 mt-1">
                                            <Calendar class="h-4 w-4 text-muted-foreground" />
                                            <span>{{ formatDate(signature.signed_at) }}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-muted-foreground">Validated At</div>
                                        <div class="flex items-center gap-1 mt-1">
                                            <CheckCircle2 class="h-4 w-4 text-muted-foreground" />
                                            <span>{{ formatDate(signature.validated_at) || 'Pending' }}</span>
                                        </div>
                                    </div>
                                </div>

                                <Separator />

                                <div v-if="signature.signing_reason" class="space-y-1">
                                    <div class="text-sm text-muted-foreground">Reason</div>
                                    <p class="text-sm bg-muted p-3 rounded">{{ signature.signing_reason }}</p>
                                </div>

                                <div v-if="signature.signing_location" class="space-y-1">
                                    <div class="text-sm text-muted-foreground">Location</div>
                                    <div class="flex items-center gap-1">
                                        <MapPin class="h-4 w-4 text-muted-foreground" />
                                        <span>{{ signature.signing_location }}</span>
                                    </div>
                                </div>

                                <div v-if="signature.contact_info" class="space-y-1">
                                    <div class="text-sm text-muted-foreground">Contact</div>
                                    <div class="flex items-center gap-1">
                                        <Mail class="h-4 w-4 text-muted-foreground" />
                                        <span>{{ signature.contact_info }}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Signer Information -->
                        <Card>
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2 text-lg">
                                    <User class="h-5 w-5" />
                                    Signer Information
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <div class="p-2 bg-primary/10 rounded-full">
                                        <User class="h-6 w-6 text-primary" />
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ signature.signer.name }}</div>
                                        <div class="text-sm text-muted-foreground">{{ signature.signer.email }}</div>
                                    </div>
                                </div>

                                <Separator />

                                <div class="space-y-3">
                                    <div>
                                        <div class="text-sm text-muted-foreground">Signing IP</div>
                                        <div class="flex items-center gap-1 mt-1">
                                            <Globe class="h-4 w-4 text-muted-foreground" />
                                            <span class="font-mono text-sm">{{ signature.signing_ip }}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-muted-foreground">Device Info</div>
                                        <div class="flex items-center gap-1 mt-1">
                                            <Smartphone class="h-4 w-4 text-muted-foreground" />
                                            <span class="text-sm">{{ signature.signing_device_info }}</span>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Signature Value Card -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-lg">
                                <FileCode class="h-5 w-5" />
                                Signature Data
                                <Button variant="ghost" size="sm" @click="showFullSignature = !showFullSignature">
                                    {{ showFullSignature ? 'Show less' : 'Show full' }}
                                </Button>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="relative">
                                <pre class="bg-muted p-4 rounded-lg overflow-auto max-h-96 text-xs font-mono">
                                    <code>{{ showFullSignature ? signature.signature_value : signature.signature_value.substring(0, 500) + '...' }}</code>
                                </pre>
                                <Button 
                                    variant="ghost" 
                                    size="icon" 
                                    class="absolute top-2 right-2"
                                    @click="copyToClipboard(signature.signature_value)"
                                >
                                    <Copy class="h-4 w-4" />
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Document Tab (contenu inchangé) -->
                <TabsContent value="document" class="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <component :is="fileIcon" class="h-5 w-5" />
                                Signed Document
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="flex items-center gap-4 p-4 bg-muted/50 rounded-lg">
                                <div class="p-3 bg-primary/10 rounded">
                                    <component :is="fileIcon" class="h-8 w-8 text-primary" />
                                </div>
                                <div class="flex-1">
                                    <h3 class="font-medium">{{ signature.file.original_file_name }}</h3>
                                    <div class="flex items-center gap-3 mt-1">
                                        <span class="text-sm text-muted-foreground">
                                            {{ formatFileSize(signature.file.file_size) }}
                                        </span>
                                        <span class="text-sm text-muted-foreground">•</span>
                                        <span class="text-sm text-muted-foreground">
                                            {{ signature.file.mime_type }}
                                        </span>
                                    </div>
                                </div>
                                <Button variant="outline" @click="downloadDocument">
                                    <Download class="mr-2 h-4 w-4" />
                                    Download
                                </Button>
                            </div>

                            <Separator />

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <div class="text-sm text-muted-foreground">File Hash (SHA-256)</div>
                                    <div class="flex items-center gap-2 mt-1">
                                        <code class="bg-muted px-2 py-1 rounded text-xs font-mono">
                                            {{ signature.file.file_hash.substring(0, 32) }}...
                                        </code>
                                        <TooltipProvider>
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <Button variant="ghost" size="icon" class="h-6 w-6"
                                                        @click="copyToClipboard(signature.file.file_hash)">
                                                        <Copy class="h-3 w-3" />
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    Copy full hash
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-sm text-muted-foreground">Expires</div>
                                    <div class="flex items-center gap-1 mt-1">
                                        <Calendar class="h-4 w-4 text-muted-foreground" />
                                        <span>{{ formatDate(signature.file.expires_at) || 'Never' }}</span>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                        <CardFooter>
                            <Button variant="outline" class="w-full" @click="downloadDocument">
                                <Download class="mr-2 h-4 w-4" />
                                Download Original Document
                            </Button>
                        </CardFooter>
                    </Card>
                </TabsContent>

                <!-- Key Tab (contenu inchangé) -->
                <TabsContent value="key" class="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <KeyRound class="h-5 w-5" />
                                Signing Key Details
                            </CardTitle>
                            <CardDescription>
                                The key pair used to create this signature
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="flex items-start gap-4">
                                <div class="p-3 bg-primary/10 rounded-full">
                                    <KeyRound class="h-6 w-6 text-primary" />
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-medium">{{ signature.key_pair.key_name }}</h3>
                                        <Badge :class="getAlgorithmColor(signature.key_pair.algorithm_used)">
                                            {{ signature.key_pair.algorithm_used }} {{ signature.key_pair.key_size }}
                                        </Badge>
                                    </div>
                                    <div class="flex items-center gap-2 mt-2">
                                        <Fingerprint class="h-4 w-4 text-muted-foreground" />
                                        <code class="text-sm font-mono">{{ signature.key_pair.fingerprint }}</code>
                                        <Button variant="ghost" size="icon" class="h-6 w-6"
                                            @click="copyToClipboard(signature.key_pair.fingerprint)">
                                            <Copy class="h-3 w-3" />
                                        </Button>
                                    </div>
                                </div>
                                <Button variant="outline" as-child>
                                    <Link :href="route('keys.show', signature.key_pair.id)">
                                        <Eye class="mr-2 h-4 w-4" />
                                        View Key
                                    </Link>
                                </Button>
                            </div>

                            <Separator />

                            <div>
                                <div class="text-sm text-muted-foreground mb-2">Public Key</div>
                                <div class="relative">
                                    <pre class="bg-muted p-4 rounded-lg overflow-auto max-h-60 text-xs font-mono">
                                        <code>{{ signature.key_pair.public_key }}</code>
                                    </pre>
                                    <Button 
                                        variant="ghost" 
                                        size="icon" 
                                        class="absolute top-2 right-2"
                                        @click="copyToClipboard(signature.key_pair.public_key)"
                                    >
                                        <Copy class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Verifications Tab (contenu inchangé) -->
                <TabsContent value="verifications" class="space-y-6">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <CardTitle class="flex items-center gap-2">
                                    <Shield class="h-5 w-5" />
                                    Verification History
                                </CardTitle>
                                <Badge variant="outline">
                                    {{ signature.verification_count }} total
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Verifier</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Result</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="signature.verifications.length === 0">
                                        <TableCell colspan="4" class="h-32 text-center">
                                            <div class="flex flex-col items-center justify-center text-muted-foreground">
                                                <Shield class="h-8 w-8 mb-2 opacity-50" />
                                                <p>No verifications yet</p>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow v-for="verif in signature.verifications" :key="verif.id">
                                        <TableCell>{{ formatDate(verif.verified_at) }}</TableCell>
                                        <TableCell>
                                            {{ verif.verifier?.name || 'Public Verification' }}
                                        </TableCell>
                                        <TableCell>{{ verif.verification_type }}</TableCell>
                                        <TableCell>
                                            <Badge :class="verif.result ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'">
                                                {{ verif.result ? 'Valid' : 'Invalid' }}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                        <CardFooter>
                            <Button as-child class="w-full">
                                <Link :href="route('verifications.create', { signature_id: signature.id })">
                                    <Shield class="mr-2 h-4 w-4" />
                                    Verify This Signature
                                </Link>
                            </Button>
                        </CardFooter>
                    </Card>
                </TabsContent>
            </Tabs>

            <!-- Dialog de validation -->
            <Dialog :open="showValidateDialog" @update:open="showValidateDialog = false">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle class="flex items-center gap-2">
                            <CheckCircle2 class="h-5 w-5 text-green-600" />
                            Validate Signature
                        </DialogTitle>
                        <DialogDescription>
                            Mark this signature as valid. This action confirms the authenticity of the signature.
                        </DialogDescription>
                    </DialogHeader>

                    <form @submit.prevent="handleValidate">
                        <div class="space-y-4 py-4">
                            <div class="space-y-2">
                                <Label for="validation_notes">Validation Notes (optional)</Label>
                                <Textarea
                                    id="validation_notes"
                                    v-model="validateForm.validation_notes"
                                    placeholder="Add any notes about this validation..."
                                    rows="3"
                                />
                            </div>

                            <Alert>
                                <Info class="h-4 w-4" />
                                <AlertTitle>Confirmation</AlertTitle>
                                <AlertDescription>
                                    Are you sure you want to mark this signature as valid? This action can be reversed later if needed.
                                </AlertDescription>
                            </Alert>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" @click="showValidateDialog = false">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="isValidating">
                                <Loader2 v-if="isValidating" class="mr-2 h-4 w-4 animate-spin" />
                                <CheckCircle2 v-else class="mr-2 h-4 w-4" />
                                {{ isValidating ? 'Validating...' : 'Confirm Validation' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- Dialog de révocation -->
            <Dialog :open="showRevokeDialog" @update:open="showRevokeDialog = false">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle class="flex items-center gap-2">
                            <Ban class="h-5 w-5 text-red-600" />
                            Revoke Signature
                        </DialogTitle>
                        <DialogDescription>
                            Revoking a signature makes it invalid. This action is usually permanent.
                        </DialogDescription>
                    </DialogHeader>

                    <form @submit.prevent="handleRevoke">
                        <div class="space-y-4 py-4">
                            <div class="space-y-2">
                                <Label for="revocation_reason">Reason for Revocation</Label>
                                <Textarea
                                    id="revocation_reason"
                                    v-model="revokeForm.revocation_reason"
                                    placeholder="Explain why this signature needs to be revoked..."
                                    rows="3"
                                    required
                                />
                            </div>

                            <Alert variant="destructive">
                                <AlertCircle class="h-4 w-4" />
                                <AlertTitle>Warning</AlertTitle>
                                <AlertDescription>
                                    Revoking a signature is usually irreversible. Make sure you have a valid reason.
                                </AlertDescription>
                            </Alert>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" @click="showRevokeDialog = false">
                                Cancel
                            </Button>
                            <Button type="submit" variant="destructive" :disabled="isRevoking">
                                <Loader2 v-if="isRevoking" class="mr-2 h-4 w-4 animate-spin" />
                                <Ban v-else class="mr-2 h-4 w-4" />
                                {{ isRevoking ? 'Revoking...' : 'Revoke Signature' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>