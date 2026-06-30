<template>
    <PageHeader title="eBay — Oferta (nasze aukcje)">
        <div class="flex items-center gap-2">
            <Link :href="route('crafter.connect.integrations.ebay.index')">
                <Button variant="outline" color="gray">← Ustawienia</Button>
            </Link>
            <Button :leftIcon="ArrowPathIcon" color="primary" @click="fetch" :loading="fetching" :disabled="!meta.oauth_connected">
                Pobierz oferty
            </Button>
        </div>
    </PageHeader>

    <PageContent fluid>
        <div class="mb-4 text-sm text-gray-500">Argo Connect → Integracja eBay → <span class="font-medium text-gray-700">Oferta</span></div>

        <div v-if="!meta.oauth_connected"
            class="mb-4 rounded-md bg-yellow-50 border border-yellow-200 p-3 text-sm text-yellow-800">
            Konto eBay nie jest połączone.
            <Link :href="route('crafter.connect.integrations.ebay.index')" class="underline font-medium">
                Połącz je w ustawieniach
            </Link>, potem wróć i kliknij „Pobierz oferty".
        </div>

        <!-- Taby rynków -->
        <div class="mb-5 border-b border-gray-200">
            <nav class="-mb-px flex gap-6 flex-wrap">
                <button type="button" @click="setMarketplace(null)"
                    :class="tabClass(activeMarketplace === null)">
                    Wszystkie <span class="ml-1 text-xs text-gray-400">({{ total }})</span>
                </button>
                <button v-for="(count, mp) in marketplaces" :key="mp" type="button" @click="setMarketplace(mp)"
                    :class="tabClass(activeMarketplace === mp)">
                    {{ marketLabel(mp) }} <span class="ml-1 text-xs text-gray-400">({{ count }})</span>
                </button>
            </nav>
        </div>

        <!-- Pasek Operacje (gdy coś zaznaczone) -->
        <div v-if="selectionActive" class="mb-4 rounded-lg border border-primary-200 bg-primary-50 p-3">
            <div class="flex items-center gap-3 flex-wrap text-sm">
                <span class="font-medium text-primary-800">
                    <template v-if="selectAllMatching">Zaznaczono: wszystkie pasujące (do {{ offers.total }})</template>
                    <template v-else>Zaznaczono: {{ selected.size }}</template>
                </span>
                <button v-if="!selectAllMatching && pageAllSelected && offers.total > offers.data.length"
                    type="button" @click="selectAllMatchingNow" class="text-primary-600 hover:underline">
                    Zaznacz wszystkie pasujące filtrowi ({{ offers.total }})
                </button>
                <button type="button" @click="clearSelection" class="text-gray-500 hover:text-gray-700 underline">Wyczyść</button>
                <span class="text-gray-300">|</span>
                <span class="font-medium text-gray-800">Operacja: zmień cenę na cenę z cennika</span>
                <select v-model.number="opForm.pricelist_id"
                    class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                    <option :value="null">— wybierz cennik —</option>
                    <option v-for="pl in pricelists" :key="pl.id" :value="pl.id">{{ pl.name }}</option>
                </select>
                <span class="text-gray-600">VAT</span>
                <input type="number" v-model.number="opForm.vat" min="0" max="100" step="1"
                    class="w-16 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                <span class="text-gray-500">%</span>
                <Button type="button" color="primary" @click="doPreview" :loading="previewing" :disabled="!opForm.pricelist_id">
                    Podgląd zmian
                </Button>
            </div>
        </div>

        <Card>
            <CardHeader>
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-3 flex-wrap">
                        <h2 class="text-lg font-semibold whitespace-nowrap">Oferty ({{ offers.total }})</h2>
                        <button v-if="unmapped > 0" type="button" @click="setFilter('mapped', '0')"
                            :class="[
                                'inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium transition whitespace-nowrap',
                                filters.mapped === '0' ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100 border border-amber-200',
                            ]"
                            title="Pokaż tylko oferty bez przypisanego produktu">
                            Pokaż nieprzypisane ({{ unmapped }})
                        </button>
                    </div>
                    <div class="flex items-center gap-x-5 gap-y-2 flex-wrap">
                        <div class="flex items-center gap-1.5">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Mapowanie</span>
                            <div class="inline-flex rounded-md border border-gray-300 overflow-hidden text-xs divide-x divide-gray-300">
                                <button type="button" @click="setFilter('mapped', '1')" :class="filterBtn(filters.mapped === '1')">Przypisane</button>
                                <button type="button" @click="setFilter('mapped', '0')" :class="filterBtn(filters.mapped === '0')">Nieprzypisane</button>
                            </div>
                        </div>
                        <input type="search" v-model="search" @keyup.enter="applySearch"
                            placeholder="Szukaj: tytuł / SKU / ItemID…"
                            class="w-56 rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm" />
                        <button v-if="hasActiveFilters" type="button" @click="clearFilters"
                            class="text-xs text-gray-500 hover:text-gray-700 underline whitespace-nowrap">✕ Wyczyść</button>
                        <div class="flex items-center gap-1.5">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Na stronie</span>
                            <select v-model.number="perPage" @change="go()"
                                class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="250">250</option>
                                <option :value="500">500</option>
                            </select>
                        </div>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="p-0">
                <div v-if="offers.data.length === 0" class="p-8 text-center text-sm text-gray-500">
                    <template v-if="hasActiveFilters">
                        Brak wyników. <button type="button" @click="clearFilters" class="ml-1 text-primary-600 hover:underline">✕ Wyczyść</button>
                    </template>
                    <template v-else>
                        Brak ofert. Kliknij „Pobierz oferty" (wymaga połączonego konta).
                    </template>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 w-8">
                                    <input type="checkbox" :checked="pageAllSelected" :indeterminate.prop="pageSomeSelected && !pageAllSelected"
                                        @change="toggleSelectPage" :disabled="eligibleIds.length === 0"
                                        class="rounded text-primary-600 focus:ring-primary-500 disabled:opacity-30"
                                        title="Zaznacz zmapowane na tej stronie" />
                                </th>
                                <th @click="toggleSort('title')" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                    Tytuł <span class="text-gray-400">{{ sortIcon('title') }}</span>
                                </th>
                                <th @click="toggleSort('sku')" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                    SKU <span class="text-gray-400">{{ sortIcon('sku') }}</span>
                                </th>
                                <th @click="toggleSort('product_id')" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                    Nasz produkt <span class="text-gray-400">{{ sortIcon('product_id') }}</span>
                                </th>
                                <th @click="toggleSort('price')" class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                    Cena <span class="text-gray-400">{{ sortIcon('price') }}</span>
                                </th>
                                <th @click="toggleSort('quantity')" class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                    Ilość <span class="text-gray-400">{{ sortIcon('quantity') }}</span>
                                </th>
                                <th v-if="activeMarketplace === null" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Rynek</th>
                                <th @click="toggleSort('listing_status')" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                    Status <span class="text-gray-400">{{ sortIcon('listing_status') }}</span>
                                </th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Link</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr v-for="o in offers.data" :key="o.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <input type="checkbox" :checked="selectAllMatching || selected.has(o.id)" @change="toggleSel(o.id)"
                                        :disabled="!o.product"
                                        :title="!o.product ? 'Tylko zmapowane można zaznaczyć' : ''"
                                        class="rounded text-primary-600 focus:ring-primary-500 disabled:opacity-30" />
                                </td>
                                <td class="px-4 py-3 max-w-md truncate" :title="o.title">{{ o.title }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ o.sku || '—' }}</td>
                                <td class="px-4 py-3 text-xs relative">
                                    <template v-if="o.product">
                                        <span class="font-mono font-medium text-green-700">{{ o.product.product_code }}</span>
                                        <span class="text-gray-500"> · {{ plName(o.product.name) }}</span>
                                        <button type="button" @click="startMap(o)" class="ml-2 text-gray-400 hover:text-gray-600">zmień</button>
                                    </template>
                                    <template v-else-if="mapRow === o.id">
                                        <input v-model="mapQuery" @input="doSearch" placeholder="kod / nazwa…"
                                            class="w-64 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                        <div v-if="mapResults.length" class="absolute z-20 mt-1 w-[30rem] bg-white border border-gray-200 rounded-md shadow-lg max-h-80 overflow-auto">
                                            <button v-for="r in mapResults" :key="r.id" type="button" @click="assign(o, r)"
                                                class="block w-full text-left px-3 py-2 hover:bg-primary-50 border-b border-gray-100 last:border-0">
                                                <div class="font-mono font-semibold text-sm text-gray-900">{{ r.product_code }}</div>
                                                <div class="text-xs text-gray-600 truncate">{{ plName(r.name) }}</div>
                                            </button>
                                        </div>
                                    </template>
                                    <button v-else type="button" @click="startMap(o)" class="text-primary-600 hover:underline">+ przypisz</button>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap font-medium">
                                    {{ o.price != null ? `${o.price} ${o.currency ?? ''}` : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">{{ o.quantity ?? '—' }}</td>
                                <td v-if="activeMarketplace === null" class="px-4 py-3 text-xs text-gray-500">{{ marketLabel(o.marketplace) }}</td>
                                <td class="px-4 py-3 text-xs">
                                    <span :class="o.listing_status === 'Active' ? 'text-green-700' : 'text-gray-500'">{{ o.listing_status ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a v-if="o.listing_url" :href="o.listing_url" target="_blank" rel="noopener" class="text-primary-600 hover:underline text-xs">otwórz ↗</a>
                                    <span v-else class="text-gray-400 text-xs">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="offers.last_page > 1" class="flex flex-wrap gap-1 p-4 border-t border-gray-100">
                    <Link v-for="(link, i) in offers.links" :key="i" :href="link.url ?? ''"
                        :class="[
                            'px-3 py-1 rounded text-sm',
                            link.active ? 'bg-primary-600 text-white' : 'text-gray-600 hover:bg-gray-100',
                            !link.url ? 'pointer-events-none text-gray-300' : '',
                        ]"
                        v-html="link.label" preserve-scroll />
                </div>
            </CardContent>
        </Card>

        <!-- Modal podglądu zmiany cen -->
        <div v-if="showPreview" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" @click.self="showPreview = false">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[85vh] flex flex-col">
                <div class="px-5 py-4 border-b">
                    <h3 class="text-lg font-semibold">Podgląd zmiany cen</h3>
                    <p class="text-sm text-gray-500">Cennik: <span class="font-medium">{{ preview?.pricelist ?? '—' }}</span> · VAT {{ opForm.vat }}%</p>
                </div>
                <div class="px-5 py-3 text-sm">
                    <span class="font-semibold text-green-700">{{ preview?.count ?? 0 }}</span> ofert do zmiany,
                    <span class="text-gray-500">{{ preview?.skipped ?? 0 }} pominiętych (brak ceny w cenniku)</span>.
                </div>
                <div class="px-5 overflow-auto flex-1">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase sticky top-0 bg-white">
                            <tr><th class="text-left py-1">Tytuł / SKU</th><th class="text-right">Obecna</th><th class="text-right">Nowa (brutto)</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(r, i) in preview?.sample ?? []" :key="i">
                                <td class="py-1.5 pr-2">
                                    <span class="truncate block max-w-xs">{{ r.title }}</span>
                                    <span class="font-mono text-xs text-gray-400">{{ r.sku || '—' }}</span>
                                </td>
                                <td class="text-right text-gray-500 whitespace-nowrap">{{ r.old ?? '—' }} {{ r.currency }}</td>
                                <td class="text-right font-medium whitespace-nowrap">{{ r.new }} {{ r.currency }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="(preview?.count ?? 0) > (preview?.sample?.length ?? 0)" class="text-xs text-gray-400 py-2">
                        …i {{ (preview?.count ?? 0) - (preview?.sample?.length ?? 0) }} więcej.
                    </p>
                </div>
                <div class="px-5 py-4 border-t flex items-center justify-between gap-3 flex-wrap">
                    <p class="text-xs text-amber-600">⚠️ „Zastosuj" zmienia REALNE ceny na eBay. Najpierw testuj na Sandboxie.</p>
                    <div class="flex gap-2">
                        <Button type="button" variant="outline" color="gray" @click="showPreview = false">Anuluj</Button>
                        <Button type="button" color="primary" @click="doApply" :loading="applying" :disabled="(preview?.count ?? 0) === 0">
                            Zastosuj na eBay ({{ preview?.count ?? 0 }})
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import axios from "axios";
import { ArrowPathIcon } from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Card, CardHeader, CardContent } from "crafter/Components";

interface OurProduct { id: number; name: string; product_code: string }
interface OfferRow {
    id: number;
    item_id: string;
    sku: string | null;
    marketplace: string;
    title: string;
    price: string | null;
    currency: string | null;
    quantity: number | null;
    listing_status: string | null;
    listing_url: string | null;
    product: OurProduct | null;
}
interface Paginated<T> {
    data: T[];
    total: number;
    last_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}
interface Props {
    offers: Paginated<OfferRow>;
    marketplaces: Record<string, number>;
    pricelists: Array<{ id: number; name: string; currency: string }>;
    total: number;
    unmapped: number;
    sort: string;
    per_page: number;
    filters: { search: string | null; mapped: string | null; marketplace: string | null };
    meta: { oauth_connected: boolean; has_credentials: boolean };
}

const props = defineProps<Props>();
const toast = useToast();

const search = ref(props.filters?.search ?? "");
const perPage = ref<number>(props.per_page ?? 50);
const activeMarketplace = computed(() => props.filters?.marketplace ?? null);

const filters = reactive({
    mapped: (props.filters?.mapped ?? null) as string | null,
});

const MARKET_LABELS: Record<string, string> = {
    EBAY_DE: "Niemcy (DE)", EBAY_PL: "Polska (PL)", EBAY_US: "USA", EBAY_GB: "UK",
    EBAY_FR: "Francja", EBAY_IT: "Włochy", EBAY_ES: "Hiszpania", EBAY_AT: "Austria",
};
function marketLabel(mp: string): string {
    return MARKET_LABELS[mp] ?? mp;
}

function tabClass(active: boolean): string {
    return [
        "border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap",
        active ? "border-primary-500 text-primary-600" : "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300",
    ].join(" ");
}
function filterBtn(active: boolean): string {
    return active ? "px-3 py-1.5 bg-primary-600 text-white" : "px-3 py-1.5 bg-white text-gray-600 hover:bg-gray-50";
}

function go(extra: Record<string, any> = {}) {
    router.get(route("crafter.connect.integrations.ebay.offers.index"), {
        sort: props.sort,
        "filter[search]": search.value || undefined,
        "filter[mapped]": filters.mapped ?? undefined,
        "filter[marketplace]": activeMarketplace.value ?? undefined,
        per_page: perPage.value,
        ...extra,
    }, { preserveState: true, preserveScroll: true, replace: true });
}
function applySearch() { go(); }
function setMarketplace(mp: string | null) { go({ "filter[marketplace]": mp ?? undefined }); }
function setFilter(key: "mapped", val: string) {
    filters[key] = filters[key] === val ? null : val;
    go();
}
const hasActiveFilters = computed(() => !!search.value || filters.mapped !== null);
function clearFilters() {
    search.value = "";
    filters.mapped = null;
    go();
}
function sortIcon(col: string): string {
    if (props.sort === col) return "▲";
    if (props.sort === "-" + col) return "▼";
    return "↕";
}
function toggleSort(col: string) { go({ sort: props.sort === col ? "-" + col : col }); }

/** Polska nazwa — obiekt {pl,…}, string-JSON lub zwykły tekst. */
function plName(v: any): string {
    if (!v) return "";
    if (typeof v === "object") return (v.pl as string) || (Object.values(v)[0] as string) || "";
    try {
        const o = JSON.parse(v);
        if (o && typeof o === "object") return o.pl || (Object.values(o)[0] as string) || v;
    } catch { /* zwykły tekst */ }
    return v;
}

// --- Mapowanie SKU ↔ nasz produkt ---
const mapRow = ref<number | null>(null);
const mapQuery = ref("");
const mapResults = ref<OurProduct[]>([]);
let mapTimer: ReturnType<typeof setTimeout> | null = null;

function startMap(o: OfferRow) {
    mapRow.value = o.id;
    mapQuery.value = "";
    mapResults.value = [];
}
function doSearch() {
    if (mapTimer) clearTimeout(mapTimer);
    mapTimer = setTimeout(async () => {
        const { data } = await axios.get(route("crafter.scope.rumuni.product-search"), { params: { q: mapQuery.value } });
        mapResults.value = data;
    }, 300);
}
async function assign(o: OfferRow, r: OurProduct) {
    try {
        const { data } = await axios.post(route("crafter.connect.integrations.ebay.offers.assign", { offer: o.id }), { product_id: r.id });
        if (data.ok) {
            o.product = data.product;
            mapRow.value = null;
            toast.success(`Przypisano ${r.product_code}`);
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd przypisania.");
    }
}

const fetching = ref(false);
async function fetch() {
    fetching.value = true;
    try {
        const { data } = await axios.post(route("crafter.connect.integrations.ebay.offers.fetch"), {
            marketplace: activeMarketplace.value || undefined,
        });
        data.ok ? toast.success(data.message) : toast.error(data.message);
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd pobierania ofert.");
    } finally {
        fetching.value = false;
    }
}

// --- Zaznaczanie (cross-page) ---
const selected = ref<Set<number>>(new Set());
const selectAllMatching = ref(false); // true = WSZYSTKIE zmapowane pasujące filtrowi (nie tylko zaznaczone strony)

const eligibleIds = computed(() => props.offers.data.filter((o) => o.product).map((o) => o.id));
const pageAllSelected = computed(() => eligibleIds.value.length > 0 && eligibleIds.value.every((id) => selected.value.has(id)));
const pageSomeSelected = computed(() => eligibleIds.value.some((id) => selected.value.has(id)));
const selectionActive = computed(() => selectAllMatching.value || selected.value.size > 0);

function toggleSel(id: number) {
    selectAllMatching.value = false;
    selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id);
    selected.value = new Set(selected.value);
}
function toggleSelectPage() {
    selectAllMatching.value = false;
    const all = pageAllSelected.value;
    const s = new Set(selected.value);
    eligibleIds.value.forEach((id) => (all ? s.delete(id) : s.add(id)));
    selected.value = s;
}
function selectAllMatchingNow() {
    selectAllMatching.value = true;
}
function clearSelection() {
    selected.value = new Set();
    selectAllMatching.value = false;
}

// --- Operacja: zmień cenę na cenę z cennika ---
const opForm = reactive({ pricelist_id: null as number | null, vat: 0 });
const preview = ref<{ count: number; skipped: number; pricelist: string | null; sample: any[] } | null>(null);
const previewing = ref(false);
const applying = ref(false);
const showPreview = ref(false);

function opPayload(): Record<string, any> {
    const base = { pricelist_id: opForm.pricelist_id, vat: opForm.vat };
    return selectAllMatching.value
        ? { ...base, all: true, marketplace: activeMarketplace.value || undefined, search: search.value || undefined }
        : { ...base, ids: [...selected.value] };
}

async function doPreview() {
    if (!opForm.pricelist_id) { toast.error("Wybierz cennik."); return; }
    if (!selectAllMatching.value && selected.value.size === 0) { toast.error("Zaznacz oferty lub „wszystkie pasujące"."); return; }
    previewing.value = true;
    try {
        const { data } = await axios.post(route("crafter.connect.integrations.ebay.offers.price-preview"), opPayload());
        preview.value = data;
        showPreview.value = true;
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd podglądu.");
    } finally {
        previewing.value = false;
    }
}

async function doApply() {
    if (!window.confirm(`Zmienić ceny ${preview.value?.count ?? ""} ofert NA ŻYWO na eBay? To realne ceny aukcji.`)) return;
    applying.value = true;
    try {
        const { data } = await axios.post(route("crafter.connect.integrations.ebay.offers.price-apply"), opPayload());
        if (data.ok) {
            toast.success(data.message);
            showPreview.value = false;
            clearSelection();
        } else {
            toast.error(data.message);
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd zmiany cen.");
    } finally {
        applying.value = false;
    }
}
</script>
