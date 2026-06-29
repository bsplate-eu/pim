<template>
    <PageHeader title="Klienci">
        <template #subtitle>
            <span class="text-sm text-gray-500">{{ customers.total }} klientów</span>
        </template>
    </PageHeader>

    <PageContent fluid>
        <!-- Taby per Base -->
        <div v-if="bases.length > 0" class="mb-4 border-b border-gray-200">
            <nav class="-mb-px flex gap-6 overflow-x-auto">
                <button
                    type="button"
                    @click="setBaseTab('')"
                    :class="tabClass('')"
                >
                    Wszystkie
                </button>
                <button
                    v-for="b in bases"
                    :key="b.id"
                    type="button"
                    @click="setBaseTab(String(b.id))"
                    :class="tabClass(String(b.id))"
                >
                    {{ b.label }}
                </button>
            </nav>
        </div>

        <Listing
            :baseUrl="route('crafter.connect.customers.index')"
            :data="customers"
            dataKey="customers"
        >
            <template #filters>
                <select
                    v-model="filters.country_code"
                    @change="applyFilters"
                    class="rounded-md border-gray-300 text-sm"
                >
                    <option value="">Wszystkie kraje</option>
                    <option
                        v-for="c in countries"
                        :key="c.country_code"
                        :value="c.country_code"
                    >
                        {{ c.country || c.country_code }}
                    </option>
                </select>
                <select
                    v-model="filters.primary_source"
                    @change="applyFilters"
                    class="rounded-md border-gray-300 text-sm"
                >
                    <option value="">Wszystkie źródła</option>
                    <option v-for="src in sources" :key="src" :value="src">{{ src }}</option>
                </select>
            </template>

            <template #tableHead>
                <ListingHeaderCell sortBy="country_code">Kraj</ListingHeaderCell>
                <ListingHeaderCell>Źródło</ListingHeaderCell>
                <ListingHeaderCell sortBy="full_name">Imię i nazwisko</ListingHeaderCell>
                <ListingHeaderCell>Miejscowość</ListingHeaderCell>
                <ListingHeaderCell>E-mail</ListingHeaderCell>
                <ListingHeaderCell>Telefon</ListingHeaderCell>
                <ListingHeaderCell sortBy="orders_count" class="text-right">Zam.</ListingHeaderCell>
                <ListingHeaderCell sortBy="last_order_at">Ostatnie</ListingHeaderCell>
                <ListingHeaderCell>
                    <span class="sr-only">Akcje</span>
                </ListingHeaderCell>
            </template>

            <template #tableRow="{ item }: any">
                <ListingDataCell>
                    <div class="flex items-center gap-2">
                        <img
                            v-if="item.country_code"
                            :src="flagUrl(item.country_code)"
                            :alt="item.country_code"
                            class="w-6 h-4 rounded-sm object-cover shadow-sm ring-1 ring-black/5"
                        />
                        <span v-else class="w-6 h-4 inline-block rounded-sm bg-gray-100"></span>
                        <div class="text-sm">
                            <div class="text-gray-700">{{ item.country || '—' }}</div>
                            <div class="text-xs font-mono text-gray-400">
                                {{ item.country_code || '—' }}
                            </div>
                        </div>
                    </div>
                </ListingDataCell>

                <ListingDataCell>
                    <span
                        v-if="item.primary_source"
                        class="inline-flex items-center px-2 py-0.5 rounded bg-gray-100 text-xs text-gray-700"
                    >
                        {{ item.primary_source }}
                    </span>
                    <span v-else class="text-xs text-gray-400">—</span>
                </ListingDataCell>

                <ListingDataCell>
                    <div class="flex items-center gap-2">
                        <div
                            class="w-8 h-8 rounded-full flex items-center justify-center text-white font-medium text-xs shrink-0"
                            :style="{ backgroundColor: avatarColor(item.display_name || item.email) }"
                        >
                            {{ initials(item.display_name || item.email) }}
                        </div>
                        <div class="min-w-0">
                            <Link
                                :href="route('crafter.connect.customers.show', item.id)"
                                class="text-sm font-medium text-primary-600 hover:underline truncate block"
                            >
                                {{ item.display_name || '—' }}
                            </Link>
                            <div v-if="item.company" class="text-xs text-gray-500 truncate">
                                {{ item.company }}
                            </div>
                        </div>
                    </div>
                </ListingDataCell>

                <ListingDataCell>
                    <div class="text-sm">
                        <div class="font-mono text-xs text-gray-500">{{ item.postcode || '' }}</div>
                        <div class="text-gray-700 truncate max-w-[160px]">
                            {{ item.city || '—' }}
                        </div>
                    </div>
                </ListingDataCell>

                <ListingDataCell>
                    <a
                        v-if="item.email"
                        :href="`mailto:${item.email}`"
                        class="text-sm text-gray-700 hover:text-primary-600 truncate max-w-[220px] block"
                    >
                        {{ item.email }}
                    </a>
                    <span v-else class="text-xs text-gray-400">—</span>
                </ListingDataCell>

                <ListingDataCell>
                    <a
                        v-if="item.phone"
                        :href="`tel:${item.phone}`"
                        class="text-sm text-gray-700 hover:text-primary-600 font-mono"
                    >
                        {{ item.phone }}
                    </a>
                    <span v-else class="text-xs text-gray-400">—</span>
                </ListingDataCell>

                <ListingDataCell class="text-right">
                    <span class="inline-flex items-center justify-center min-w-[1.75rem] px-2 py-0.5 rounded bg-primary-50 text-primary-700 text-xs font-semibold">
                        {{ item.orders_count }}
                    </span>
                </ListingDataCell>

                <ListingDataCell>
                    <div class="text-xs text-gray-500">{{ formatDate(item.last_order_at) }}</div>
                </ListingDataCell>

                <ListingDataCell>
                    <Link
                        :href="route('crafter.connect.customers.show', item.id)"
                        class="text-primary-600 hover:text-primary-700"
                    >
                        <EyeIcon class="w-5 h-5" />
                    </Link>
                </ListingDataCell>
            </template>
        </Listing>
    </PageContent>
</template>

<script setup lang="ts">
import { reactive } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { EyeIcon } from "@heroicons/vue/24/outline";
import {
    PageHeader,
    PageContent,
    Listing,
    ListingHeaderCell,
    ListingDataCell,
} from "crafter/Components";

interface CustomerRow {
    id: number;
    full_name: string | null;
    display_name: string;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    phone: string | null;
    company: string | null;
    city: string | null;
    postcode: string | null;
    country: string | null;
    country_code: string | null;
    primary_source: string | null;
    orders_count: number;
    last_order_at: string | null;
}

interface Props {
    customers: { data: CustomerRow[]; total: number; current_page: number; last_page: number };
    countries: Array<{ country_code: string; country: string | null }>;
    sources: string[];
    bases: Array<{ id: number; label: string }>;
}

const props = defineProps<Props>();

const filters = reactive({
    country_code: (new URLSearchParams(window.location.search).get("filter[country_code]") ?? "") as string,
    primary_source: (new URLSearchParams(window.location.search).get("filter[primary_source]") ?? "") as string,
    base_settings_id: (new URLSearchParams(window.location.search).get("filter[base_settings_id]") ?? "") as string,
});

function applyFilters() {
    router.get(
        route("crafter.connect.customers.index"),
        {
            "filter[country_code]": filters.country_code || undefined,
            "filter[primary_source]": filters.primary_source || undefined,
            "filter[base_settings_id]": filters.base_settings_id || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function setBaseTab(baseId: string) {
    filters.base_settings_id = baseId;
    applyFilters();
}

function tabClass(value: string): string {
    const active = filters.base_settings_id === value;
    return [
        "whitespace-nowrap py-2 px-1 text-sm font-medium border-b-2 transition",
        active
            ? "border-primary-600 text-primary-600"
            : "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300",
    ].join(" ");
}

function flagUrl(code: string | null): string {
    if (!code || code.length !== 2) return "";
    return `https://flagcdn.com/w40/${code.toLowerCase()}.png`;
}

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    const d = new Date(iso);
    return d.toLocaleDateString("pl-PL", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    });
}

function initials(value: string | null): string {
    if (!value) return "??";
    return value
        .split(/[\s@.]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((s) => s[0]!.toUpperCase())
        .join("");
}

function avatarColor(value: string | null): string {
    if (!value) return "#64748b";
    let hash = 0;
    for (const ch of value) hash = ch.charCodeAt(0) + ((hash << 5) - hash);
    const palette = [
        "#ef4444", "#f97316", "#f59e0b", "#10b981",
        "#14b8a6", "#06b6d4", "#3b82f6", "#6366f1",
        "#8b5cf6", "#ec4899",
    ];
    return palette[Math.abs(hash) % palette.length];
}
</script>
