<!-- resources/js/pages/Dashboard.vue -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';
import { ref, computed } from 'vue';
import {
    KeyRound,
    FileSignature,
    Shield,
    FileText,
    CheckCircle2,
    XCircle,
    Clock,
    AlertCircle,
    TrendingUp,
    Activity,
    Calendar,
    Users,
    Download,
    Eye,
    MoreHorizontal,
    ArrowUpRight,
    ArrowDownRight,
    Fingerprint,
    Globe,
    User,
    Bell,
    Settings,
    LogOut,
    Menu,
    Plus,
    History,
    Archive,
    BarChart3,
    PieChart,
    LineChart,
    Lock,
    Unlock
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
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

interface DashboardStats {
    totalKeys: number;
    activeKeys: number;
    defaultKey: {
        id: number;
        key_name: string;
        fingerprint: string;
    } | null;
    expiringKeys: number;
    totalSignatures: number;
    validSignatures: number;
    pendingSignatures: number;
    revokedSignatures: number;
    totalFiles: number;
    totalVerifications: number;
    successfulVerifications: number;
    publicVerifications: number;
    verificationSuccessRate: number;
}

interface RecentActivity {
    id: number;
    file_name: string;
    status: 'pending' | 'valid' | 'invalid' | 'revoked';
    signed_at: string;
    is_valid: boolean;
}

interface ChartData {
    month: string;
    total: number;
}

interface AlgorithmData {
    algorithm_used: string;
    total: number;
}

interface RecentFile {
    id: number;
    name: string;
    size: string;
    created_at: string;
    status: string;
}

const props = defineProps<{
    stats: DashboardStats;
    recentActivity: RecentActivity[];
    charts: {
        signaturesByMonth: ChartData[];
        verificationsByMonth: ChartData[];
        algorithms: AlgorithmData[];
    };
    recentFiles: RecentFile[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
];

const formatNumber = (num: number) => {
    return new Intl.NumberFormat().format(num);
};

const getStatusBadge = (status: string, isValid: boolean) => {
    switch (status) {
        case 'valid':
            return { color: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300', icon: CheckCircle2, label: 'Valid' };
        case 'pending':
            return { color: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300', icon: Clock, label: 'Pending' };
        case 'invalid':
            return { color: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300', icon: XCircle, label: 'Invalid' };
        case 'revoked':
            return { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', icon: Archive, label: 'Revoked' };
        default:
            return { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', icon: AlertCircle, label: 'Unknown' };
    }
};

const getAlgorithmColor = (algorithm: string) => {
    const colors: Record<string, string> = {
        'rsa': 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        'ecdsa': 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    };
    return colors[algorithm.toLowerCase()] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
};

// Calculer les pourcentages pour les graphiques
const signatureTrend = computed(() => {
    const data = props.charts.signaturesByMonth;
    if (data.length < 2) return 0;
    const last = data[data.length - 1].total;
    const previous = data[data.length - 2].total;
    return previous > 0 ? Math.round(((last - previous) / previous) * 100) : 0;
});

const verificationTrend = computed(() => {
    const data = props.charts.verificationsByMonth;
    if (data.length < 2) return 0;
    const last = data[data.length - 1].total;
    const previous = data[data.length - 2].total;
    return previous > 0 ? Math.round(((last - previous) / previous) * 100) : 0;
});

// Couleurs pour les graphiques
const chartColors = [
    'bg-blue-500',
    'bg-purple-500',
    'bg-green-500',
    'bg-yellow-500',
    'bg-red-500',
    'bg-indigo-500',
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header avec bienvenue -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Welcome back!</h1>
                    <p class="text-sm text-muted-foreground">
                        Here's an overview of your digital signature activity
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('keys.create')">
                            <KeyRound class="mr-2 h-4 w-4" />
                            New Key
                        </Link>
                    </Button>
                    <Button as-child>
                        <Link :href="route('signatures.create')">
                            <FileSignature class="mr-2 h-4 w-4" />
                            Sign Document
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Cartes de statistiques principales -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <!-- Signatures -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Total Signatures</CardTitle>
                        <FileSignature class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ formatNumber(stats.totalSignatures) }}</div>
                        <div class="flex items-center gap-2 mt-1">
                            <Badge class="bg-green-100 text-green-800">+{{ stats.validSignatures }} valid</Badge>
                            <Badge class="bg-yellow-100 text-yellow-800">{{ stats.pendingSignatures }} pending</Badge>
                        </div>
                        <div class="flex items-center gap-1 mt-2 text-xs text-muted-foreground">
                            <Activity class="h-3 w-3" />
                            <span>{{ stats.revokedSignatures }} revoked</span>
                        </div>
                    </CardContent>
                </Card>

                <!-- Clés -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Active Keys</CardTitle>
                        <KeyRound class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.activeKeys }}/{{ stats.totalKeys }}</div>
                        <div class="flex items-center gap-2 mt-1">
                            <Badge v-if="stats.defaultKey" variant="outline" class="text-xs">
                                <Fingerprint class="h-3 w-3 mr-1" />
                                {{ stats.defaultKey.key_name }}
                            </Badge>
                            <Badge v-if="stats.expiringKeys > 0" class="bg-yellow-100 text-yellow-800">
                                {{ stats.expiringKeys }} expiring
                            </Badge>
                        </div>
                        <Progress :model-value="(stats.activeKeys / stats.totalKeys) * 100" class="h-1 mt-2" />
                    </CardContent>
                </Card>

                <!-- Vérifications -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Verifications</CardTitle>
                        <Shield class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ formatNumber(stats.totalVerifications) }}</div>
                        <div class="flex items-center gap-2 mt-1">
                            <Badge class="bg-green-100 text-green-800">{{ stats.verificationSuccessRate }}% success</Badge>
                            <Badge v-if="stats.publicVerifications > 0" variant="outline">
                                <Globe class="h-3 w-3 mr-1" />
                                {{ stats.publicVerifications }} public
                            </Badge>
                        </div>
                        <Progress :model-value="stats.verificationSuccessRate" class="h-1 mt-2" />
                    </CardContent>
                </Card>

                <!-- Documents -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Documents</CardTitle>
                        <FileText class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ formatNumber(stats.totalFiles) }}</div>
                        <p class="text-xs text-muted-foreground mt-1">
                            Files uploaded for signing
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Graphiques et tendances -->
            <div class="grid gap-4 md:grid-cols-2">
                <!-- Graphique des signatures par mois -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm font-medium">Signature Activity</CardTitle>
                            <Badge :class="signatureTrend >= 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'">
                                <component :is="signatureTrend >= 0 ? ArrowUpRight : ArrowDownRight" class="h-3 w-3 mr-1" />
                                {{ Math.abs(signatureTrend) }}%
                            </Badge>
                        </div>
                        <CardDescription>Last 6 months</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="h-[200px] flex items-end gap-2">
                            <div v-for="(item, index) in charts.signaturesByMonth" :key="index" class="flex-1 flex flex-col items-center gap-2">
                                <div 
                                    class="w-full bg-primary/20 rounded-t transition-all duration-500 hover:bg-primary/30"
                                    :style="{ height: `${(item.total / Math.max(...charts.signaturesByMonth.map(d => d.total), 1)) * 150}px` }"
                                >
                                    <div 
                                        class="w-full bg-primary rounded-t transition-all"
                                        :style="{ height: `${(item.total / Math.max(...charts.signaturesByMonth.map(d => d.total), 1)) * 150}px` }"
                                    ></div>
                                </div>
                                <span class="text-xs text-muted-foreground">{{ item.month.split('-')[1] }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Graphique des vérifications par mois -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm font-medium">Verification Activity</CardTitle>
                            <Badge :class="verificationTrend >= 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'">
                                <component :is="verificationTrend >= 0 ? ArrowUpRight : ArrowDownRight" class="h-3 w-3 mr-1" />
                                {{ Math.abs(verificationTrend) }}%
                            </Badge>
                        </div>
                        <CardDescription>Last 6 months</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="h-[200px] flex items-end gap-2">
                            <div v-for="(item, index) in charts.verificationsByMonth" :key="index" class="flex-1 flex flex-col items-center gap-2">
                                <div 
                                    class="w-full bg-purple-500/20 rounded-t transition-all duration-500 hover:bg-purple-500/30"
                                    :style="{ height: `${(item.total / Math.max(...charts.verificationsByMonth.map(d => d.total), 1)) * 150}px` }"
                                >
                                    <div 
                                        class="w-full bg-purple-500 rounded-t transition-all"
                                        :style="{ height: `${(item.total / Math.max(...charts.verificationsByMonth.map(d => d.total), 1)) * 150}px` }"
                                    ></div>
                                </div>
                                <span class="text-xs text-muted-foreground">{{ item.month.split('-')[1] }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Section inférieure : Activité récente et répartition -->
            <div class="grid gap-4 md:grid-cols-3">
                <!-- Activité récente -->
                <Card class="md:col-span-2">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Recent Activity</CardTitle>
                        <CardDescription>Your latest signatures and verifications</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div v-for="activity in recentActivity" :key="activity.id" 
                                class="flex items-center justify-between p-2 hover:bg-muted/50 rounded-lg transition-colors"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-primary/10 rounded-full">
                                        <component 
                                            :is="getStatusBadge(activity.status, activity.is_valid).icon" 
                                            class="h-4 w-4"
                                            :class="{
                                                'text-green-600': activity.status === 'valid',
                                                'text-yellow-600': activity.status === 'pending',
                                                'text-red-600': activity.status === 'invalid' || activity.status === 'revoked'
                                            }"
                                        />
                                    </div>
                                    <div>
                                        <p class="font-medium text-sm">{{ activity.file_name }}</p>
                                        <div class="flex items-center gap-2 mt-1">
                                            <Badge :class="getStatusBadge(activity.status, activity.is_valid).color" class="text-xs">
                                                {{ activity.status }}
                                            </Badge>
                                            <span class="text-xs text-muted-foreground">{{ activity.signed_at }}</span>
                                        </div>
                                    </div>
                                </div>
                                <Button variant="ghost" size="icon" as-child>
                                    <Link :href="route('signatures.show', activity.id)">
                                        <Eye class="h-4 w-4" />
                                    </Link>
                                </Button>
                            </div>

                            <div v-if="recentActivity.length === 0" class="text-center py-6 text-muted-foreground">
                                <FileSignature class="h-8 w-8 mx-auto mb-2 opacity-50" />
                                <p>No recent activity</p>
                                <Button variant="link" as-child class="mt-2">
                                    <Link :href="route('signatures.create')">Sign your first document</Link>
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                    <CardFooter>
                        <Button variant="outline" class="w-full" as-child>
                            <Link :href="route('signatures.index')">
                                <History class="mr-2 h-4 w-4" />
                                View All Signatures
                            </Link>
                        </Button>
                    </CardFooter>
                </Card>

                <!-- Répartition des algorithmes et fichiers récents -->
                <div class="space-y-4">
                    <!-- Répartition des algorithmes -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-sm font-medium">Key Algorithms</CardTitle>
                            <CardDescription>Distribution by algorithm type</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-3">
                                <div v-for="(algo, index) in charts.algorithms" :key="algo.algorithm_used" class="space-y-1">
                                    <div class="flex items-center justify-between text-sm">
                                        <Badge :class="getAlgorithmColor(algo.algorithm_used)">
                                            {{ algo.algorithm_used }}
                                        </Badge>
                                        <span class="font-medium">{{ algo.total }}</span>
                                    </div>
                                    <Progress 
                                        :model-value="(algo.total / charts.algorithms.reduce((acc, a) => acc + a.total, 0)) * 100" 
                                        class="h-1.5"
                                        :class="chartColors[index % chartColors.length]"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Fichiers récents -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-sm font-medium">Recent Files</CardTitle>
                            <CardDescription>Latest uploaded documents</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-2">
                                <div v-for="file in recentFiles" :key="file.id" 
                                    class="flex items-center justify-between p-2 hover:bg-muted/50 rounded-lg"
                                >
                                    <div class="flex items-center gap-2">
                                        <FileText class="h-4 w-4 text-muted-foreground" />
                                        <div>
                                            <p class="text-sm font-medium truncate max-w-[120px]">{{ file.name }}</p>
                                            <p class="text-xs text-muted-foreground">{{ file.size }} • {{ file.created_at }}</p>
                                        </div>
                                    </div>
                                    <Badge variant="outline" class="text-xs">
                                        {{ file.status }}
                                    </Badge>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Alertes et notifications -->
            <div v-if="stats.expiringKeys > 0 || stats.pendingSignatures > 0" class="grid gap-4 md:grid-cols-2">
                <!-- Alertes pour clés expirantes -->
                <Alert v-if="stats.expiringKeys > 0" class="border-yellow-500/50 bg-yellow-50 dark:bg-yellow-950/20">
                    <AlertCircle class="h-4 w-4 text-yellow-600" />
                    <AlertTitle>Keys Expiring Soon</AlertTitle>
                    <AlertDescription>
                        You have {{ stats.expiringKeys }} key{{ stats.expiringKeys > 1 ? 's' : '' }} expiring within 30 days.
                        <Button variant="link" class="p-0 h-auto font-normal" as-child>
                            <Link :href="route('keys.index')">Review now →</Link>
                        </Button>
                    </AlertDescription>
                </Alert>

                <!-- Alertes pour signatures en attente -->
                <Alert v-if="stats.pendingSignatures > 0" class="border-blue-500/50 bg-blue-50 dark:bg-blue-950/20">
                    <Clock class="h-4 w-4 text-blue-600" />
                    <AlertTitle>Pending Signatures</AlertTitle>
                    <AlertDescription>
                        You have {{ stats.pendingSignatures }} signature{{ stats.pendingSignatures > 1 ? 's' : '' }} waiting for validation.
                        <Button variant="link" class="p-0 h-auto font-normal" as-child>
                            <Link :href="route('signatures.pending')">View pending →</Link>
                        </Button>
                    </AlertDescription>
                </Alert>
            </div>
        </div>
    </AppLayout>
</template>