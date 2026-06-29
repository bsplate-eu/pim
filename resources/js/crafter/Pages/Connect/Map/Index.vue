<template>
    <PageHeader title="Mapa zamówień">
        <template #subtitle>
            <span class="text-sm text-gray-500">
                {{ totalOrders }} zamówień na mapie
                <span v-if="unmappedCount > 0">· {{ unmappedCount }} bez lokalizacji</span>
            </span>
        </template>
    </PageHeader>

    <PageContent fluid>
        <!-- Filtry -->
        <div class="mb-4 p-4 bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Zakres dat</label>
                    <DateRangePicker
                        name="date_range"
                        v-model="dateRange"
                        placeholder="Wybierz zakres"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">
                        Kraje ({{ selectedCountries.length || 'wszystkie' }})
                    </label>
                    <select
                        multiple
                        v-model="selectedCountries"
                        class="w-full rounded-md border-gray-300 text-sm h-[42px]"
                        size="1"
                    >
                        <option v-for="c in countries" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">
                        Kanały ({{ selectedSources.length || 'wszystkie' }})
                    </label>
                    <select
                        multiple
                        v-model="selectedSources"
                        class="w-full rounded-md border-gray-300 text-sm h-[42px]"
                        size="1"
                    >
                        <option v-for="s in sources" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">
                        Base ({{ selectedBases.length || 'wszystkie' }})
                    </label>
                    <select
                        multiple
                        v-model="selectedBases"
                        class="w-full rounded-md border-gray-300 text-sm h-[42px]"
                        size="1"
                    >
                        <option v-for="b in bases" :key="b.id" :value="b.id">{{ b.label }}</option>
                    </select>
                </div>
            </div>
            <div class="mt-3 flex justify-end gap-2">
                <button
                    @click="clearFilters"
                    type="button"
                    class="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50"
                >
                    Wyczyść
                </button>
                <button
                    @click="loadPoints"
                    type="button"
                    :disabled="loading"
                    class="px-4 py-1.5 text-sm bg-primary-600 text-white rounded-md hover:bg-primary-700 disabled:opacity-50"
                >
                    {{ loading ? 'Ładowanie…' : 'Zastosuj' }}
                </button>
            </div>
        </div>

        <!-- Stats bar -->
        <div v-if="!loading && (points.length > 0 || unmappedCount > 0)" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <div class="px-3 py-1.5 bg-blue-50 text-blue-700 rounded-md">
                Pinezek: <strong>{{ points.length }}</strong>
            </div>
            <div class="px-3 py-1.5 bg-green-50 text-green-700 rounded-md">
                Zamówień: <strong>{{ totalOrders }}</strong>
            </div>
            <div
                v-if="unmappedCount > 0"
                class="px-3 py-1.5 bg-amber-50 text-amber-700 rounded-md"
                title="Zamówienia bez dopasowanego kodu pocztowego w geo_postal_codes"
            >
                Bez lokalizacji: <strong>{{ unmappedCount }}</strong>
            </div>
            <div
                v-for="(value, currency) in totalsByCurrency"
                :key="currency"
                class="px-3 py-1.5 bg-gray-50 text-gray-700 rounded-md"
            >
                {{ formatMoney(value as number) }} <strong>{{ currency }}</strong>
            </div>
        </div>

        <!-- Mapa -->
        <div
            class="rounded-lg overflow-hidden shadow-sm border border-gray-200 relative bg-gray-100"
            style="height: 70vh; min-height: 500px;"
        >
            <div ref="mapContainer" class="w-full h-full"></div>
            <div
                v-if="loading"
                class="absolute inset-0 bg-white/60 flex items-center justify-center z-[1000]"
            >
                <div class="text-sm text-gray-700 px-4 py-2 bg-white rounded-md shadow">
                    Ładowanie pinezek…
                </div>
            </div>
        </div>

        <!-- Side panel z zamówieniami -->
        <div
            v-if="selectedPoint"
            class="fixed inset-y-0 right-0 w-full max-w-md bg-white shadow-2xl border-l border-gray-200 z-[2000] flex flex-col"
        >
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <div>
                    <div class="text-xs text-gray-500">
                        {{ selectedPoint.country_code }} · {{ selectedPoint.postal_code }}
                    </div>
                    <div class="text-lg font-semibold text-gray-900">
                        {{ selectedPoint.place_name || selectedPoint.postal_code }}
                    </div>
                    <div class="text-sm text-gray-600">
                        {{ selectedPoint.orders_count }} zamówień
                    </div>
                </div>
                <button
                    @click="closePanel"
                    type="button"
                    class="p-2 hover:bg-gray-100 rounded-md"
                >
                    <XMarkIcon class="w-5 h-5 text-gray-500" />
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-4">
                <div v-if="ordersLoading" class="text-sm text-gray-500 text-center py-8">
                    Ładowanie zamówień…
                </div>
                <div v-else-if="selectedOrders.length === 0" class="text-sm text-gray-500 text-center py-8">
                    Brak zamówień (z aktywnymi filtrami).
                </div>
                <ul v-else class="divide-y divide-gray-100">
                    <li v-for="o in selectedOrders" :key="o.id" class="py-2">
                        <Link
                            :href="route('crafter.connect.orders.show', o.id)"
                            class="block hover:bg-gray-50 -mx-2 px-2 py-1.5 rounded"
                        >
                            <div class="flex items-center justify-between">
                                <span class="font-mono text-sm font-semibold text-primary-600">
                                    #{{ o.baselinker_order_id }}
                                </span>
                                <span class="text-xs text-gray-500">{{ formatDate(o.date_add) }}</span>
                            </div>
                            <div class="text-sm text-gray-800 truncate">
                                {{ o.delivery_fullname || '—' }}
                            </div>
                            <div class="flex items-center justify-between text-xs text-gray-500 mt-0.5">
                                <span>{{ o.order_source || '—' }}</span>
                                <span class="font-medium">
                                    {{ formatMoney(o.total_amount) }} {{ o.currency }}
                                </span>
                            </div>
                        </Link>
                    </li>
                </ul>
                <div v-if="selectedOrders.length === 100" class="mt-3 text-xs text-gray-500 text-center">
                    Pokazano pierwsze 100 zamówień.
                </div>
            </div>
        </div>
        <div
            v-if="selectedPoint"
            @click="closePanel"
            class="fixed inset-0 bg-black/20 z-[1999]"
        ></div>
    </PageContent>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import { Link } from "@inertiajs/vue3";
import { XMarkIcon } from "@heroicons/vue/24/outline";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import "leaflet.markercluster/dist/MarkerCluster.css";
import "leaflet.markercluster/dist/MarkerCluster.Default.css";
import "leaflet.markercluster";
import markerIcon from "leaflet/dist/images/marker-icon.png";
import markerIcon2x from "leaflet/dist/images/marker-icon-2x.png";
import markerShadow from "leaflet/dist/images/marker-shadow.png";
import dayjs from "dayjs";
import { PageHeader, PageContent, DateRangePicker } from "crafter/Components";

// Fix dla Leaflet marker icons w Vite (default getIconUrl nie znajduje plików)
delete (L.Icon.Default.prototype as any)._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

interface BaseRow {
    id: number;
    label: string;
}

interface Props {
    countries: string[];
    sources: string[];
    bases: BaseRow[];
}

const props = defineProps<Props>();

interface MapPoint {
    country_code: string;
    postal_code: string;
    place_name: string | null;
    lat: number;
    lng: number;
    orders_count: number;
    values: Array<{ currency: string; total: number }>;
}

interface OrderRow {
    id: number;
    baselinker_order_id: string;
    external_order_id: string | null;
    order_source: string | null;
    delivery_fullname: string | null;
    delivery_city: string | null;
    date_add: string | null;
    total_amount: number;
    currency: string;
}

const mapContainer = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let markersGroup: any = null; // L.MarkerClusterGroup nie ma typów w @types/leaflet

const dateRange = ref<{ start: string; end: string } | null>({
    start: dayjs().subtract(90, "day").format("YYYY-MM-DD"),
    end: dayjs().format("YYYY-MM-DD"),
});
const selectedCountries = ref<string[]>([]);
const selectedSources = ref<string[]>([]);
const selectedBases = ref<number[]>([]);

const points = ref<MapPoint[]>([]);
const unmappedCount = ref(0);
const loading = ref(false);

const selectedPoint = ref<MapPoint | null>(null);
const selectedOrders = ref<OrderRow[]>([]);
const ordersLoading = ref(false);

const totalOrders = computed(() =>
    points.value.reduce((sum, p) => sum + p.orders_count, 0)
);

const totalsByCurrency = computed(() => {
    const totals: Record<string, number> = {};
    for (const p of points.value) {
        for (const v of p.values) {
            totals[v.currency] = (totals[v.currency] || 0) + v.total;
        }
    }
    return totals;
});

onMounted(() => {
    initMap();
    loadPoints();
});

onBeforeUnmount(() => {
    if (map) {
        map.remove();
        map = null;
    }
});

function initMap() {
    if (!mapContainer.value) return;
    map = L.map(mapContainer.value, {
        center: [52.0, 19.0],
        zoom: 5,
        minZoom: 2,
        worldCopyJump: true,
    });
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "&copy; OpenStreetMap",
        maxZoom: 19,
    }).addTo(map);
    markersGroup = (L as any).markerClusterGroup({
        showCoverageOnHover: false,
        chunkedLoading: true,
        spiderfyOnMaxZoom: true,
    });
    map.addLayer(markersGroup);
}

async function loadPoints() {
    loading.value = true;
    try {
        const params = buildFilterParams();
        const response = await fetch(
            `/admin/connect/map/points?${params.toString()}`,
            { headers: { Accept: "application/json" } }
        );
        if (!response.ok) {
            console.error("Błąd ładowania pinezek:", response.status);
            return;
        }
        const data = await response.json();
        points.value = data.points;
        unmappedCount.value = data.unmapped_count;
        renderMarkers();
    } catch (e) {
        console.error("Błąd ładowania pinezek:", e);
    } finally {
        loading.value = false;
    }
}

function buildFilterParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (dateRange.value?.start) params.append("date_from", dateRange.value.start);
    if (dateRange.value?.end) params.append("date_to", dateRange.value.end);
    selectedCountries.value.forEach((c) => params.append("countries[]", c));
    selectedSources.value.forEach((s) => params.append("sources[]", s));
    selectedBases.value.forEach((b) => params.append("bases[]", String(b)));
    return params;
}

function renderMarkers() {
    if (!markersGroup || !map) return;
    markersGroup.clearLayers();

    for (const point of points.value) {
        const marker = L.marker([point.lat, point.lng]);
        const valueStr = point.values
            .map((v) => `${v.total.toFixed(2)} ${v.currency}`)
            .join(", ");
        const tooltipHtml = `
            <div style="font-size: 12px; line-height: 1.4;">
                <div style="font-weight: 600;">${escapeHtml(point.place_name || point.postal_code)} (${point.country_code})</div>
                <div style="color: #6b7280;">${escapeHtml(point.postal_code)}</div>
                <div style="margin-top: 4px;"><strong>${point.orders_count}</strong> zamówień</div>
                <div>${escapeHtml(valueStr)}</div>
                <div style="margin-top: 4px; color: #6b7280; font-size: 11px;">Kliknij, aby zobaczyć listę.</div>
            </div>
        `;
        marker.bindTooltip(tooltipHtml, { sticky: true });
        marker.on("click", () => {
            selectedPoint.value = point;
            loadOrdersForPoint(point);
        });
        markersGroup.addLayer(marker);
    }
}

async function loadOrdersForPoint(point: MapPoint) {
    ordersLoading.value = true;
    selectedOrders.value = [];
    try {
        const params = buildFilterParams();
        params.append("country_code", point.country_code);
        params.append("postal_code", point.postal_code);
        const response = await fetch(
            `/admin/connect/map/orders?${params.toString()}`,
            { headers: { Accept: "application/json" } }
        );
        if (!response.ok) return;
        const data = await response.json();
        selectedOrders.value = data.orders;
    } finally {
        ordersLoading.value = false;
    }
}

function closePanel() {
    selectedPoint.value = null;
    selectedOrders.value = [];
}

function clearFilters() {
    selectedCountries.value = [];
    selectedSources.value = [];
    selectedBases.value = [];
    dateRange.value = {
        start: dayjs().subtract(90, "day").format("YYYY-MM-DD"),
        end: dayjs().format("YYYY-MM-DD"),
    };
}

function formatMoney(value: number): string {
    return new Intl.NumberFormat("pl-PL", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    return dayjs(iso).format("DD.MM.YYYY");
}

function escapeHtml(value: string): string {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}
</script>
