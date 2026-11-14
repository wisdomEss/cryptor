<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const props = withDefaults(
    defineProps<{
        pagination?: PaginationMeta | null;
        /** Nombre de pages affichées de chaque côté de la page courante. */
        span?: number;
    }>(),
    { pagination: null, span: 2 },
);

const page = usePage();

/**
 * Conserve l'URL courante et ses filtres : seul le paramètre `page` change.
 * (Indépendant du nom de route : la même barre sert /keys, /signatures/pending, etc.)
 */
const hrefFor = (target: number) => {
    const [path, query = ''] = page.url.split('?');
    const params = new URLSearchParams(query);

    if (target <= 1) {
        params.delete('page');
    } else {
        params.set('page', String(target));
    }

    const search = params.toString();
    return search ? `${path}?${search}` : path;
};

/** Fenêtre de numéros autour de la page courante : une liste complète ferait
 *  autant de boutons que de pages. */
const pages = computed(() => {
    const meta = props.pagination;
    if (!meta) return [];

    const from = Math.max(1, meta.current_page - props.span);
    const to = Math.min(meta.last_page, meta.current_page + props.span);

    return Array.from({ length: to - from + 1 }, (_, i) => from + i);
});

const range = computed(() => {
    const meta = props.pagination;
    if (!meta || meta.total === 0) return '';

    const first = (meta.current_page - 1) * meta.per_page + 1;
    const last = Math.min(meta.current_page * meta.per_page, meta.total);

    return `${first}–${last} of ${meta.total}`;
});
</script>

<template>
    <div
        v-if="pagination && pagination.last_page > 1"
        class="flex w-full flex-wrap items-center justify-between gap-3 border-t px-1 pt-4"
    >
        <p class="text-sm text-muted-foreground">{{ range }}</p>

        <nav class="flex items-center gap-1" aria-label="Pagination">
            <Button
                v-if="pagination.current_page > 1"
                variant="outline"
                size="sm"
                as-child
            >
                <Link
                    :href="hrefFor(pagination.current_page - 1)"
                    preserve-scroll
                    aria-label="Previous page"
                >
                    <ChevronLeft class="h-4 w-4" />
                </Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                <ChevronLeft class="h-4 w-4" />
            </Button>

            <template v-if="pages[0] > 1">
                <Button variant="outline" size="sm" class="min-w-8" as-child>
                    <Link :href="hrefFor(1)" preserve-scroll>1</Link>
                </Button>
                <span v-if="pages[0] > 2" class="px-1 text-muted-foreground"
                    >…</span
                >
            </template>

            <Button
                v-for="pageNumber in pages"
                :key="pageNumber"
                :variant="
                    pageNumber === pagination.current_page
                        ? 'default'
                        : 'outline'
                "
                size="sm"
                class="min-w-8"
                as-child
            >
                <Link
                    :href="hrefFor(pageNumber)"
                    preserve-scroll
                    :aria-current="
                        pageNumber === pagination.current_page
                            ? 'page'
                            : undefined
                    "
                >
                    {{ pageNumber }}
                </Link>
            </Button>

            <template v-if="pages[pages.length - 1] < pagination.last_page">
                <span
                    v-if="pages[pages.length - 1] < pagination.last_page - 1"
                    class="px-1 text-muted-foreground"
                    >…</span
                >
                <Button variant="outline" size="sm" class="min-w-8" as-child>
                    <Link :href="hrefFor(pagination.last_page)" preserve-scroll>
                        {{ pagination.last_page }}
                    </Link>
                </Button>
            </template>

            <Button
                v-if="pagination.current_page < pagination.last_page"
                variant="outline"
                size="sm"
                as-child
            >
                <Link
                    :href="hrefFor(pagination.current_page + 1)"
                    preserve-scroll
                    aria-label="Next page"
                >
                    <ChevronRight class="h-4 w-4" />
                </Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                <ChevronRight class="h-4 w-4" />
            </Button>
        </nav>
    </div>
</template>
