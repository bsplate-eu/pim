<template>
    <PageHeader title="Zamówienia">
        <template #subtitle>
            <span class="text-sm text-gray-500">{{ orders.total }} zamówień</span>
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
            :baseUrl="route('crafter.connect.orders.index')"
            :data="orders"
            dataKey="orders"
            striped
        >
            <template #filters>
                <select
                    v-model="filters.order_status_id"
                    @change="applyFilters"
                    class="rounded-md border-gray-300 text-sm"
                >
                    <option value="">Wszystkie statusy</option>
                    <option v-for="s in statuses" :key="s.id" :value="s.id">
                        {{ s.name }}
                    </option>
                </select>
                <select
                    v-model="filters.order_source"
                    @change="applyFilters"
                    class="rounded-md border-gray-300 text-sm"
                >
                    <option value="">Wszystkie źródła</option>
                    <option v-for="src in sources" :key="src" :value="src">{{ src }}</option>
                </select>
            </template>

            <template #tableHead>
                <ListingHeaderCell sortBy="baselinker_order_id">Nr</ListingHeaderCell>
                <ListingHeaderCell class="w-full">Produkty</ListingHeaderCell>
                <ListingHeaderCell>Szczegóły</ListingHeaderCell>
                <ListingHeaderCell sortBy="total_amount" class="text-right">Kwota</ListingHeaderCell>
                <ListingHeaderCell>Status</ListingHeaderCell>
                <ListingHeaderCell>Stan</ListingHeaderCell>
                <ListingHeaderCell sortBy="date_add">Daty</ListingHeaderCell>
                <ListingHeaderCell>
                    <span class="sr-only">Akcje</span>
                </ListingHeaderCell>
            </template>

            <template #tableRow="{ item }: any">
                <!-- Nr: gwiazdka + numer + ext + flaga + źródło -->
                <ListingDataCell>
                    <div class="flex items-start gap-1.5">
                        <StarIcon v-if="item.star" class="w-4 h-4 text-yellow-400 shrink-0 mt-0.5" />
                        <div class="min-w-0">
                            <Link
                                :href="route('crafter.connect.orders.show', item.id)"
                                class="font-mono text-sm font-semibold text-primary-600 hover:underline"
                            >
                                #{{ item.baselinker_order_id }}
                            </Link>
                            <div v-if="item.external_order_id" class="text-xs text-gray-400 font-mono">
                                ({{ item.external_order_id }})
                            </div>
                            <div class="flex items-center gap-1.5 mt-1">
                                <img
                                    v-if="item.delivery_country_code"
                                    :src="flagUrl(item.delivery_country_code)"
                                    :alt="item.delivery_country_code"
                                    class="w-5 h-3.5 rounded-sm object-cover shadow-sm ring-1 ring-black/5"
                                />
                                <span v-else class="w-5 h-3.5 inline-block rounded-sm bg-gray-100"></span>
                                <span class="text-sm font-medium text-gray-800 truncate max-w-[170px]">
                                    {{ item.delivery_fullname || '—' }}
                                </span>
                            </div>
                            <div class="flex items-center gap-1 mt-0.5 text-xs text-gray-500">
                                <UserIcon class="w-3.5 h-3.5 shrink-0" />
                                <span class="truncate max-w-[160px]">{{ sourceLabel(item) }}</span>
                            </div>
                        </div>
                    </div>
                </ListingDataCell>

                <!-- Produkty: miniatura + nazwa + SKU -->
                <ListingDataCell class="w-full">
                    <div class="space-y-1.5 max-w-md">
                        <div
                            v-for="(p, idx) in item.products.slice(0, 3)"
                            :key="idx"
                            class="flex items-center gap-2"
                        >
                            <div class="shrink-0 w-12 h-12 rounded-md border border-gray-200 bg-white overflow-hidden flex items-center justify-center">
                                <img
                                    v-if="p.thumbnail"
                                    :src="p.thumbnail"
                                    :alt="p.name"
                                    class="w-full h-full object-contain"
                                    loading="lazy"
                                />
                                <PhotoIcon v-else class="w-5 h-5 text-gray-300" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm truncate">
                                    <span class="text-gray-500">{{ p.quantity }}×</span>
                                    <span class="ml-1">{{ p.name }}</span>
                                </div>
                                <div v-if="p.sku" class="text-xs text-red-600 font-mono font-medium">SKU: {{ p.sku }}</div>
                            </div>
                        </div>
                        <div v-if="item.products.length > 3" class="text-xs text-gray-400 pl-14">
                            + {{ item.products.length - 3 }} więcej…
                        </div>
                    </div>
                </ListingDataCell>

                <!-- Szczegóły: płatność / uwagi / pole dodatkowe / miasto / kod / email -->
                <ListingDataCell>
                    <div class="text-xs text-gray-900 space-y-0.5 max-w-xs">
                        <div v-if="item.payment_method">Płatność {{ item.payment_method }}</div>
                        <div v-if="item.user_comments || item.admin_comments">
                            <span class="font-medium">Uwagi:</span> {{ item.user_comments || item.admin_comments }}
                        </div>
                        <div v-if="item.invoice_company">
                            <span class="font-medium">Firma:</span> {{ item.invoice_company }}
                        </div>
                        <div v-if="item.extra_field_1">
                            <span class="font-medium">Pole dodatkowe 1:</span> {{ item.extra_field_1 }}
                        </div>
                        <div v-if="item.delivery_city">
                            <span class="font-medium">Miasto:</span> {{ item.delivery_city }}
                        </div>
                        <div v-if="item.delivery_postcode">
                            <span class="font-medium">Kod:</span> {{ item.delivery_postcode }}
                        </div>
                        <div v-if="item.email" class="truncate">{{ item.email }}</div>
                    </div>
                </ListingDataCell>

                <!-- Kwota -->
                <ListingDataCell class="text-right">
                    <div class="font-semibold text-gray-900 whitespace-nowrap">
                        {{ formatMoney(item.total_amount, item.currency) }}
                    </div>
                    <div
                        v-if="item.payment_done < item.total_amount"
                        class="text-xs text-red-600 whitespace-nowrap"
                    >
                        brak: {{ formatMoney(item.total_amount - item.payment_done, item.currency) }}
                    </div>
                    <div v-else class="text-xs text-green-600">opłacone</div>
                </ListingDataCell>

                <!-- Status + metoda dostawy -->
                <ListingDataCell>
                    <span
                        v-if="item.status_name"
                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                        :style="statusStyle(item.status_color)"
                    >
                        {{ item.status_name }}
                    </span>
                    <span v-else class="text-xs text-gray-400">#{{ item.order_status_id }}</span>
                    <div v-if="item.delivery_method" class="text-xs text-primary-600 mt-1 whitespace-nowrap">
                        {{ item.delivery_method }}
                    </div>
                </ListingDataCell>

                <!-- Stan: P / S / F -->
                <ListingDataCell>
                    <div class="flex gap-1">
                        <span
                            v-if="item.pick_state"
                            class="text-[10px] px-1 rounded bg-green-100 text-green-700"
                            title="Skompletowane"
                        >P</span>
                        <span
                            v-if="item.pack_state"
                            class="text-[10px] px-1 rounded bg-green-100 text-green-700"
                            title="Spakowane"
                        >S</span>
                        <span
                            v-if="item.want_invoice"
                            class="text-[10px] px-1 rounded bg-yellow-100 text-yellow-700"
                            title="Chce fakturę"
                        >F</span>
                    </div>
                </ListingDataCell>

                <!-- Daty: dodania + w statusie -->
                <ListingDataCell>
                    <div class="text-xs text-gray-600 whitespace-nowrap">{{ formatDate(item.date_add) }}</div>
                    <div v-if="item.date_in_status" class="text-xs text-gray-400 whitespace-nowrap">
                        {{ formatDate(item.date_in_status) }}
                    </div>
                </ListingDataCell>

                <ListingDataCell>
                    <Link
                        :href="route('crafter.connect.orders.show', item.id)"
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
import { EyeIcon, PhotoIcon, UserIcon } from "@heroicons/vue/24/outline";
import { StarIcon } from "@heroicons/vue/24/solid";
import {
    PageHeader,
    PageContent,
    Listing,
    ListingHeaderCell,
    ListingDataCell,
} from "crafter/Components";

interface OrderRow {
    id: number;
    baselinker_order_id: number;
    external_order_id: string | null;
    order_source: string | null;
    order_source_info: string | null;
    order_status_id: number;
    status_name: string | null;
    status_color: string | null;
    date_add: string | null;
    date_in_status: string | null;
    email: string | null;
    delivery_fullname: string | null;
    delivery_country: string | null;
    delivery_country_code: string | null;
    delivery_city: string | null;
    delivery_postcode: string | null;
    delivery_method: string | null;
    payment_method: string | null;
    payment_done: number;
    total_amount: number;
    currency: string | null;
    pick_state: number;
    pack_state: number;
    want_invoice: boolean;
    star: number;
    user_comments: string | null;
    admin_comments: string | null;
    extra_field_1: string | null;
    invoice_company: string | null;
    invoice_fullname: string | null;
    products: Array<{ name: string; sku: string; quantity: number; price_brutto: number; thumbnail: string | null }>;
}

interface Props {
    orders: { data: OrderRow[]; total: number; current_page: number; last_page: number };
    statuses: Array<{ id: number; name: string; color: string | null }>;
    sources: string[];
    bases: Array<{ id: number; label: string }>;
}

const props = defineProps<Props>();

const filters = reactive({
    order_status_id: (new URLSearchParams(window.location.search).get("filter[order_status_id]") ?? "") as string,
    order_source: (new URLSearchParams(window.location.search).get("filter[order_source]") ?? "") as string,
    base_settings_id: (new URLSearchParams(window.location.search).get("filter[base_settings_id]") ?? "") as string,
});

function applyFilters() {
    router.get(
        route("crafter.connect.orders.index"),
        {
            "filter[order_status_id]": filters.order_status_id || undefined,
            "filter[order_source]": filters.order_source || undefined,
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

function sourceLabel(item: OrderRow): string {
    const info = (item.order_source_info ?? "").trim();
    const src = (item.order_source ?? "").trim();
    if (info && info !== "-" && info !== "—") return info;
    if (src && src !== "-" && src !== "—") return src;
    return "—";
}

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    const d = new Date(iso);
    return d.toLocaleString("pl-PL", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

function formatMoney(amount: number, currency: string | null): string {
    try {
        return new Intl.NumberFormat("pl-PL", {
            style: "currency",
            currency: currency || "PLN",
        }).format(amount);
    } catch {
        return `${amount.toFixed(2)} ${currency ?? ""}`;
    }
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

function statusStyle(color: string | null) {
    if (!color) return { backgroundColor: "#e5e7eb", color: "#374151" };
    return {
        backgroundColor: color + "22",
        color: color,
        border: `1px solid ${color}66`,
    };
}
</script>
