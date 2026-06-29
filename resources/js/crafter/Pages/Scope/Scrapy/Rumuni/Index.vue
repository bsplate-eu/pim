<template>
    <PageHeader title="Argo Scope — Rumuni">
        <Button
            v-if="activeTab !== 'raport'"
            :leftIcon="ArrowPathIcon"
            color="primary"
            @click="pobierz"
            :loading="syncing"
        >
            {{ activeTab === 'ebay' ? 'Pełny pomiar' : `Pobierz z ${currentLabel}` }}
        </Button>
    </PageHeader>

    <PageContent fluid>
        <div class="mb-4 text-sm text-gray-500">Scrapy → <span class="font-medium text-gray-700">Rumuni</span> (Scut Protection)</div>

        <div class="mb-5 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                <button v-for="t in tabs" :key="t.key" type="button" @click="activeTab = t.key"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium',
                        activeTab === t.key ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                    ]">
                    {{ t.label }}
                    <span v-if="t.count != null" class="ml-1 text-xs text-gray-400">({{ t.count }})</span>
                </button>
            </nav>
        </div>

        <!-- TAB: Raport -->
        <Card v-if="activeTab === 'raport'">
            <CardContent class="p-8 text-center text-sm text-gray-500">
                <p class="font-medium text-gray-700 mb-1">Raport porównawczy cen</p>
                <p>Porównanie cen tego samego produktu między kanałami (eBay ↔ Sklep) po Herstellernummer / EAN.</p>
                <p class="mt-2 text-xs">Zbudujemy, gdy będą dane z co najmniej dwóch kanałów.</p>
            </CardContent>
        </Card>

        <template v-else>
            <!-- Kafelki monitoringu (Ebay / Niemcy, po pierwszym pomiarze) -->
            <div v-if="currentMeta && currentMeta.last_sync_at" class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500 uppercase">Nowości</div>
                    <div class="mt-1 text-2xl font-semibold" :class="(currentMeta.new_count ?? 0) > 0 ? 'text-green-600' : 'text-gray-700'">
                        +{{ currentMeta.new_count ?? 0 }}
                    </div>
                    <div v-if="newPct !== null" class="text-xs" :class="newPct >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ newPct >= 0 ? '▲' : '▼' }} {{ Math.abs(newPct) }}% vs poprzedni
                    </div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500 uppercase">Wycofane</div>
                    <div class="mt-1 text-2xl font-semibold" :class="(currentMeta.removed_count ?? 0) > 0 ? 'text-red-600' : 'text-gray-700'">
                        −{{ currentMeta.removed_count ?? 0 }}
                    </div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500 uppercase">Ceny w górę</div>
                    <div class="mt-1 text-2xl font-semibold text-red-600">↑ {{ currentMeta.price_up ?? 0 }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="text-xs text-gray-500 uppercase">Ceny w dół</div>
                    <div class="mt-1 text-2xl font-semibold text-green-600">↓ {{ currentMeta.price_down ?? 0 }}</div>
                </div>
            </div>
            <div v-if="currentMeta && currentMeta.last_sync_at" class="mb-4 text-xs text-gray-400">
                Ostatni pomiar: {{ formatDate(currentMeta.last_sync_at) }} · ofert: {{ currentMeta.last_sync_count ?? '—' }}
            </div>

            <div v-if="activeTab === 'ebay' && !meta.ebay.has_integration"
                class="mb-4 rounded-md bg-yellow-50 border border-yellow-200 p-3 text-sm text-yellow-800">
                Brak integracji eBay.
                <Link :href="route('crafter.connect.integrations.ebay.index')" class="underline font-medium">
                    Skonfiguruj w Connect → Integracje → Ebay
                </Link>, potem wróć i kliknij „Pełny pomiar".
            </div>

            <!-- Pasek: cennik docelowy + cennik porównawczy -->
            <div class="mb-4 flex items-center gap-3 flex-wrap text-sm">
                <Button type="button" variant="outline" color="gray" @click="createPricelist">+ Utwórz cennik</Button>
                <span class="text-gray-300">|</span>
                <span class="text-gray-600">Cennik do porównania:</span>
                <select v-model="compareForm.pricelist_id" @change="saveCompare"
                    class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                    <option :value="null">— brak —</option>
                    <option v-for="pl in pricelists" :key="pl.id" :value="pl.id">{{ pl.name }}</option>
                </select>
                <span class="text-gray-600">VAT</span>
                <input type="number" v-model.number="compareForm.vat" @change="saveCompare" min="0" max="100" step="1"
                    class="w-16 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                <span class="text-gray-500">%</span>
                <span class="text-gray-300">|</span>
                <span class="text-gray-600">Cennik docelowy:</span>
                <select v-model="targetPricelistId" @change="saveTarget"
                    class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                    <option :value="null">— wybierz —</option>
                    <option v-for="pl in pricelists" :key="pl.id" :value="pl.id">{{ pl.name }}</option>
                </select>
                <Button type="button" variant="outline" color="gray" @click="matchSku" :loading="matching">
                    Przypisz do SKU
                </Button>
                <Button type="button" variant="outline" color="gray" @click="updateAll" :disabled="!targetPricelistId">
                    Aktualizuj cennik
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div class="flex items-center gap-3 flex-wrap">
                            <h2 class="text-lg font-semibold whitespace-nowrap">{{ currentLabel }} — oferty ({{ current.total }})</h2>
                            <button v-if="currentUnmapped > 0" type="button" @click="setFilter('mapped', '0')"
                                :class="[
                                    'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition whitespace-nowrap',
                                    filters.mapped === '0' ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100 border border-amber-200',
                                ]"
                                title="Pokaż tylko oferty bez przypisanego naszego produktu">
                                Pokaż nieprzypisane ({{ currentUnmapped }})
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
                            <div class="flex items-center gap-1.5">
                                <span class="text-[11px] font-medium uppercase tracking-wide text-gray-400">SKU</span>
                                <div class="inline-flex rounded-md border border-gray-300 overflow-hidden text-xs divide-x divide-gray-300">
                                    <button type="button" @click="setFilter('has_hn', '1')" :class="filterBtn(filters.has_hn === '1')">Z SKU</button>
                                    <button type="button" @click="setFilter('has_hn', '0')" :class="filterBtn(filters.has_hn === '0')">Bez SKU</button>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Cena</span>
                                <div class="inline-flex rounded-md border border-gray-300 overflow-hidden text-xs divide-x divide-gray-300">
                                    <button type="button" @click="setFilter('has_compare', '1')" :class="filterBtn(filters.has_compare === '1')">Z ceną</button>
                                    <button type="button" @click="setFilter('has_compare', '0')" :class="filterBtn(filters.has_compare === '0')">Bez ceny</button>
                                </div>
                            </div>
                            <input type="search" v-model="search" @keyup.enter="applySearch" @search="applySearch"
                                placeholder="Szukaj: nazwa / HN / EAN…"
                                class="w-56 rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm" />
                            <button v-if="hasActiveFilters" type="button" @click="clearFilters"
                                class="text-xs text-gray-500 hover:text-gray-700 underline whitespace-nowrap" title="Wyczyść wyszukiwanie i filtry">
                                ✕ Wyczyść
                            </button>
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
                    <div v-if="current.data.length === 0" class="p-8 text-center text-sm text-gray-500">
                        <template v-if="hasActiveFilters">
                            Brak wyników dla bieżącego wyszukiwania/filtra.
                            <button type="button" @click="clearFilters" class="ml-1 text-primary-600 hover:underline">✕ Wyczyść</button>
                        </template>
                        <template v-else>
                            Brak ofert. Kliknij „{{ activeTab === 'ebay' ? 'Pełny pomiar' : `Pobierz z ${currentLabel}` }}".
                        </template>
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th @click="toggleSort('title')" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                        Nazwa <span class="text-gray-400">{{ sortIcon('title') }}</span>
                                    </th>
                                    <th @click="toggleSort('herstellernummer')" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                        Herstellernummer <span class="text-gray-400">{{ sortIcon('herstellernummer') }}</span>
                                    </th>
                                    <th @click="toggleSort('product_id')" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                        Nasz produkt <span class="text-gray-400">{{ sortIcon('product_id') }}</span>
                                    </th>
                                    <th @click="toggleSort('ean')" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                        EAN <span class="text-gray-400">{{ sortIcon('ean') }}</span>
                                    </th>
                                    <th @click="toggleSort('price')" class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                        Cena <span class="text-gray-400">{{ sortIcon('price') }}</span>
                                    </th>
                                    <th @click="toggleSort('compare_price')" class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                        Cena cennik <span class="text-gray-400">{{ sortIcon('compare_price') }}</span>
                                    </th>
                                    <th @click="toggleSort('diff')" class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700">
                                        Różnica <span class="text-gray-400">{{ sortIcon('diff') }}</span>
                                    </th>
                                    <th @click="toggleSort('individual_price')" class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700 whitespace-nowrap" title="Ręczna cena BRUTTO (jak eBay) — gdy wpisana, to ona (po odjęciu VAT, jako netto) idzie do cennika zamiast ceny eBay">
                                        Indywidualna <span class="text-gray-400">{{ sortIcon('individual_price') }}</span>
                                    </th>
                                    <th @click="toggleSort('diff_pct')" class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap cursor-pointer select-none hover:text-gray-700" title="Różnica procentowa: o ile konkurent jest droższy (+) / tańszy (−) od naszego cennika">
                                        Różnica % <span class="text-gray-400">{{ sortIcon('diff_pct') }}</span>
                                    </th>
                                    <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase" title="Zaznaczone = WYKLUCZONE z aktualizacji (w cenniku zostaje oryginalna cena)">
                                        <label class="inline-flex items-center gap-1.5 cursor-pointer" title="Wyklucz / odznacz wszystkie na tej stronie">
                                            <input type="checkbox" :checked="allSelected" :indeterminate.prop="someSelected && !allSelected"
                                                @change="toggleSelectAll" :disabled="eligibleIds.length === 0"
                                                class="rounded text-red-600 focus:ring-red-500 disabled:opacity-30" />
                                            Wyklucz
                                        </label>
                                    </th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Link</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="p in current.data" :key="p.id" class="hover:bg-gray-50">
                                    <td class="px-4 py-3 max-w-md truncate" :title="p.title">{{ p.title }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ p.herstellernummer ?? '—' }}</td>
                                    <td class="px-4 py-3 text-xs relative">
                                        <template v-if="mapRow === p.id">
                                            <input v-model="mapQuery" @input="doSearch" placeholder="kod / nazwa…"
                                                class="w-64 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                            <button type="button" @click="mapRow = null" class="ml-2 text-gray-400 hover:text-gray-600" title="Anuluj">✕</button>
                                            <div v-if="mapResults.length" class="absolute z-20 mt-1 w-[30rem] bg-white border border-gray-200 rounded-md shadow-lg max-h-80 overflow-auto">
                                                <button v-for="r in mapResults" :key="r.id" type="button" @click="assign(p, r)"
                                                    class="block w-full text-left px-3 py-2 hover:bg-primary-50 border-b border-gray-100 last:border-0">
                                                    <div class="font-mono font-semibold text-sm text-gray-900">{{ r.product_code }}</div>
                                                    <div class="text-xs text-gray-600 truncate">{{ plName(r.name) }}</div>
                                                </button>
                                            </div>
                                        </template>
                                        <template v-else-if="p.product">
                                            <span class="font-mono font-medium text-green-700">{{ p.product.product_code }}</span>
                                            <span class="text-gray-500"> · {{ plName(p.product.name) }}</span>
                                            <button type="button" @click="startMap(p)" class="ml-2 text-gray-400 hover:text-gray-600">zmień</button>
                                            <button type="button" @click="unassign(p)" class="ml-1 text-red-400 hover:text-red-600" title="Usuń przypisanie">✕</button>
                                        </template>
                                        <button v-else type="button" @click="startMap(p)" class="text-primary-600 hover:underline">+ przypisz</button>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ p.ean ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap font-medium" :title="p.price != null ? `${p.price} ${p.currency}` : ''">
                                        {{ p.price_eur != null ? `${p.price_eur} EUR` : (p.price != null ? `${p.price} ${p.currency}` : '—') }}
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap" :class="compareCellClass(p)">
                                        {{ p.compare_price != null ? `${p.compare_price} EUR` : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap font-medium" :class="diffClass(p)">
                                        {{ priceDiff(p) }}
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <input type="number" min="0" step="0.01" inputmode="decimal"
                                            :value="p.individual_price ?? ''"
                                            @change="saveIndividual(p, ($event.target as HTMLInputElement).value)"
                                            placeholder="—"
                                            :class="[
                                                'w-24 text-right rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500',
                                                Number(p.individual_price) > 0 ? 'border-primary-400 bg-primary-50 font-medium text-primary-700' : '',
                                            ]" />
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap font-medium" :class="diffPctClass(p)">
                                        {{ priceDiffPct(p) }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <input type="checkbox" :checked="selected.has(p.id)" @change="toggleSel(p.id)"
                                            :disabled="!p.product"
                                            :title="!p.product ? 'Tylko zmapowane można wykluczyć' : 'Wyklucz — w cenniku zostaje oryginalna cena tej pozycji'"
                                            class="rounded text-red-600 focus:ring-red-500 disabled:opacity-30" />
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <a v-if="p.url" :href="p.url" target="_blank" rel="noopener" class="text-primary-600 hover:underline text-xs">otwórz ↗</a>
                                        <span v-else class="text-gray-400 text-xs">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="current.last_page > 1" class="flex flex-wrap gap-1 p-4 border-t border-gray-100">
                        <Link v-for="(link, i) in current.links" :key="i" :href="link.url ?? ''"
                            :class="[
                                'px-3 py-1 rounded text-sm',
                                link.active ? 'bg-primary-600 text-white' : 'text-gray-600 hover:bg-gray-100',
                                !link.url ? 'pointer-events-none text-gray-300' : '',
                            ]"
                            v-html="link.label" preserve-scroll />
                    </div>
                </CardContent>
            </Card>
        </template>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { Link, router } from "@inertiajs/vue3";
import axios from "axios";
import { ArrowPathIcon } from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Card, CardHeader, CardContent } from "crafter/Components";

interface OurProduct {
    id: number;
    name: string;
    product_code: string;
}
interface ProductRow {
    id: number;
    title: string;
    herstellernummer: string | null;
    ean: string | null;
    price: string | null;
    currency: string;
    price_eur: number | string | null;
    url: string | null;
    product: OurProduct | null;
    compare_price: string | null;
    individual_price: string | null;
    excluded: boolean;
}
interface Paginated<T> {
    data: T[];
    total: number;
    last_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}
interface EbayMeta {
    has_integration: boolean;
    seller: string | null;
    last_sync_at: string | null;
    last_sync_count: number | null;
    prev_offer_count: number | null;
    new_count: number | null;
    removed_count: number | null;
    price_up: number | null;
    price_down: number | null;
}
interface Props {
    ebay: Paginated<ProductRow>;
    stahl: Paginated<ProductRow>;
    wegry: Paginated<ProductRow>;
    rumunia: Paginated<ProductRow>;
    francja: Paginated<ProductRow>;
    czechy: Paginated<ProductRow>;
    hiszpania: Paginated<ProductRow>;
    meta: { ebay: EbayMeta; stahl: EbayMeta; wegry: EbayMeta; rumunia: EbayMeta; francja: EbayMeta; czechy: EbayMeta; hiszpania: EbayMeta };
    sort: string;
    filters: { search: string | null; mapped: string | null; has_hn: string | null; has_compare: string | null };
    pricelists: Array<{ id: number; name: string; currency: string }>;
    configs: Record<string, { pricelist_id: number | null; vat: string | null; target_pricelist_id: number | null }>;
    unmapped: Record<string, number>;
    per_page: number;
}

const props = defineProps<Props>();
const toast = useToast();

const activeTab = ref<"raport" | "ebay" | "stahl" | "rumunia" | "wegry" | "francja" | "czechy" | "hiszpania">("ebay");
const syncing = ref(false);
const search = ref(props.filters?.search ?? "");
const perPage = ref<number>(props.per_page ?? 50);

const tabs = computed(() => [
    { key: "raport", label: "Raport", count: null as number | null },
    { key: "ebay", label: "Ebay", count: props.ebay.total },
    { key: "stahl", label: "Niemcy", count: props.stahl.total },
    { key: "rumunia", label: "Rumunia", count: props.rumunia.total },
    { key: "wegry", label: "Węgry", count: props.wegry.total },
    { key: "francja", label: "Francja", count: props.francja.total },
    { key: "czechy", label: "Czechy", count: props.czechy.total },
    { key: "hiszpania", label: "Hiszpania", count: props.hiszpania.total },
]);

const current = computed<Paginated<ProductRow>>(() => {
    if (activeTab.value === "stahl") return props.stahl;
    if (activeTab.value === "rumunia") return props.rumunia;
    if (activeTab.value === "wegry") return props.wegry;
    if (activeTab.value === "francja") return props.francja;
    if (activeTab.value === "czechy") return props.czechy;
    if (activeTab.value === "hiszpania") return props.hiszpania;
    return props.ebay;
});


/** Liczba ofert bez przypisanego naszego produktu w bieżącym kanale (do przycisku „Pokaż nieprzypisane"). */
const currentUnmapped = computed(() => props.unmapped?.[activeTab.value] ?? 0);

const currentLabel = computed(() => {
    if (activeTab.value === "stahl") return "Niemcy";
    if (activeTab.value === "rumunia") return "Rumunia";
    if (activeTab.value === "wegry") return "Węgry";
    if (activeTab.value === "francja") return "Francja";
    if (activeTab.value === "czechy") return "Czechy";
    if (activeTab.value === "hiszpania") return "Hiszpania";
    return "eBay";
});

/** Meta monitoringu bieżącego kanału (kafelki nowe/wycofane/ceny). null = kanał bez statystyk. */
const currentMeta = computed<EbayMeta | null>(() => {
    if (activeTab.value === "ebay") return props.meta.ebay;
    if (activeTab.value === "stahl") return props.meta.stahl;
    if (activeTab.value === "rumunia") return props.meta.rumunia;
    if (activeTab.value === "wegry") return props.meta.wegry;
    if (activeTab.value === "francja") return props.meta.francja;
    if (activeTab.value === "czechy") return props.meta.czechy;
    if (activeTab.value === "hiszpania") return props.meta.hiszpania;
    return null;
});

const newPct = computed<number | null>(() => {
    const prev = currentMeta.value?.prev_offer_count;
    const nw = currentMeta.value?.new_count;
    if (prev == null || prev === 0 || nw == null) return null;
    return Math.round((nw / prev) * 100);
});

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    return new Date(iso).toLocaleString("pl-PL", { year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
}

async function pobierz() {
    syncing.value = true;
    try {
        const { data } = await axios.post(route("crafter.scope.rumuni.sync", { source: activeTab.value }));
        data.ok ? toast.success(data.message) : toast.error(data.message);
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd pobierania.");
    } finally {
        syncing.value = false;
    }
}

const filters = reactive({
    mapped: (props.filters?.mapped ?? null) as string | null,
    has_hn: (props.filters?.has_hn ?? null) as string | null,
    has_compare: (props.filters?.has_compare ?? null) as string | null,
});

function go(extra: Record<string, any> = {}) {
    router.get(route("crafter.scope.rumuni.index"), {
        sort: props.sort,
        "filter[search]": search.value || undefined,
        "filter[mapped]": filters.mapped ?? undefined,
        "filter[has_hn]": filters.has_hn ?? undefined,
        "filter[has_compare]": filters.has_compare ?? undefined,
        per_page: perPage.value,
        ...extra,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function applySearch() {
    go();
}

const hasActiveFilters = computed(
    () => !!search.value || filters.mapped !== null || filters.has_hn !== null || filters.has_compare !== null,
);

/** „Wyczyść" — kasuje wyszukiwanie i filtry, przeładowuje pełną listę. */
function clearFilters() {
    search.value = "";
    filters.mapped = null;
    filters.has_hn = null;
    filters.has_compare = null;
    go();
}

function sortIcon(col: string): string {
    if (props.sort === col) return "▲";
    if (props.sort === "-" + col) return "▼";
    return "↕";
}

function toggleSort(col: string) {
    go({ sort: props.sort === col ? "-" + col : col });
}

function setFilter(key: "mapped" | "has_hn" | "has_compare", val: string) {
    filters[key] = filters[key] === val ? null : val;
    go();
}

function filterBtn(active: boolean): string {
    return active
        ? "px-3 py-1.5 bg-primary-600 text-white"
        : "px-3 py-1.5 bg-white text-gray-600 hover:bg-gray-50";
}

// --- Mapowanie oferty ↔ nasz produkt ---
const mapRow = ref<number | null>(null);
const mapQuery = ref("");
const mapResults = ref<OurProduct[]>([]);
let mapTimer: ReturnType<typeof setTimeout> | null = null;

function startMap(p: ProductRow) {
    mapRow.value = p.id;
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

async function assign(p: ProductRow, r: OurProduct) {
    try {
        const { data } = await axios.post(route("crafter.scope.rumuni.assign", { scrapProduct: p.id }), { product_id: r.id });
        if (data.ok) {
            p.product = data.product;
            mapRow.value = null;
            toast.success(`Przypisano ${r.product_code}`);
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd przypisania.");
    }
}

async function unassign(p: ProductRow) {
    try {
        const { data } = await axios.post(route("crafter.scope.rumuni.assign", { scrapProduct: p.id }), { product_id: null });
        if (data.ok) {
            p.product = null;
            mapRow.value = null;
            toast.success("Usunięto przypisanie");
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd usuwania przypisania.");
    }
}

/** Polska nazwa z pola name — obsługuje obiekt {pl,de,…} (translatable), string-JSON i zwykły tekst. */
function plName(v: any): string {
    if (!v) return "";
    if (typeof v === "object") {
        return (v.pl as string) || (Object.values(v)[0] as string) || "";
    }
    try {
        const o = JSON.parse(v);
        if (o && typeof o === "object") {
            return o.pl || (Object.values(o)[0] as string) || v;
        }
    } catch {
        /* zwykły tekst */
    }
    return v;
}

// --- Etap 2: cennik docelowy + cennik porównawczy ---
const compareForm = reactive({
    pricelist_id: null as number | null,
    vat: 23,
});

async function createPricelist() {
    const name = window.prompt("Nazwa nowego cennika:", "Rumuni - eBay");
    if (!name) return;
    try {
        const { data } = await axios.post(route("crafter.scope.rumuni.pricelist"), { name, currency: "EUR" });
        if (data.ok) {
            toast.success(`Utworzono cennik: ${data.pricelist.name}`);
            router.reload({ only: ["pricelists"] });
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Nie udało się utworzyć cennika.");
    }
}

async function saveCompare() {
    try {
        await axios.post(route("crafter.scope.rumuni.compare"), {
            source: activeTab.value,
            pricelist_id: compareForm.pricelist_id,
            vat: compareForm.vat,
        });
        router.reload({ only: ["ebay", "stahl", "wegry", "rumunia", "francja", "czechy", "hiszpania", "configs"] });
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd zapisu cennika porównawczego.");
    }
}

/** Cena oferty w EUR: przeliczona (price_eur) dla walut obcych (HUF/RON), surowa gdy kanał już w EUR. */
function offerPrice(p: ProductRow): number | null {
    if (p.price_eur != null) return Number(p.price_eur);
    return p.price != null ? parseFloat(p.price) : null;
}

/** Tło komórki „Cena cennik": nasza (brutto) wyższa od ich (w EUR) = czerwone, niższa = zielone. */
function compareCellClass(p: ProductRow): string {
    const our = offerPrice(p);
    if (p.compare_price == null || our == null) return "";
    const c = parseFloat(p.compare_price);
    if (c > our) return "bg-red-100 text-red-800 font-semibold";
    if (c < our) return "bg-green-100 text-green-800 font-semibold";
    return "";
}

/** Różnica: cena oferty (ZAWSZE w EUR) − cena cennik (nasza). + gdy konkurent drożej, − gdy taniej. */
function priceDiff(p: ProductRow): string {
    const our = offerPrice(p);
    if (our == null || p.compare_price == null) return "—";
    const d = our - parseFloat(p.compare_price);
    return (d > 0 ? "+" : "") + d.toFixed(2) + " EUR";
}

function diffClass(p: ProductRow): string {
    const our = offerPrice(p);
    if (our == null || p.compare_price == null) return "text-gray-400";
    const d = our - parseFloat(p.compare_price);
    if (d > 0) return "text-green-700";
    if (d < 0) return "text-red-700";
    return "text-gray-500";
}

/** Różnica %: o ile cena oferty (EUR) jest wyższa/niższa od naszego cennika. + drożej, − taniej. „—" gdy brak cennika lub 0. */
function priceDiffPct(p: ProductRow): string {
    const our = offerPrice(p);
    const cmp = p.compare_price != null ? parseFloat(p.compare_price) : null;
    if (our == null || cmp == null || cmp === 0) return "—";
    const pct = ((our - cmp) / cmp) * 100;
    return (pct > 0 ? "+" : "") + pct.toFixed(1) + "%";
}

function diffPctClass(p: ProductRow): string {
    const our = offerPrice(p);
    const cmp = p.compare_price != null ? parseFloat(p.compare_price) : null;
    if (our == null || cmp == null || cmp === 0) return "text-gray-400";
    const d = our - cmp;
    return d > 0 ? "text-green-700" : d < 0 ? "text-red-700" : "text-gray-500";
}

/** Cena efektywna: indywidualna (gdy > 0) ma pierwszeństwo nad ceną eBay. */
function effPrice(p: ProductRow): number {
    const ind = Number(p.individual_price);
    if (ind > 0) return ind;
    return p.price != null ? parseFloat(p.price) : 0;
}

/** Zapis ceny indywidualnej. Pusta/0 = brak wpływu (null). Wpisana (> 0) → auto-zaznacz do cennika. */
async function saveIndividual(p: ProductRow, value: string) {
    const num = value.trim() === "" ? null : Number(value);
    const payload = num != null && Number.isFinite(num) && num > 0 ? num : null;
    try {
        const { data } = await axios.post(
            route("crafter.scope.rumuni.individual", { scrapProduct: p.id }),
            { individual_price: payload },
        );
        if (data.ok) {
            p.individual_price = data.individual_price;
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd zapisu ceny indywidualnej.");
    }
}

// --- Etap 3: zatwierdzanie cen do cennika docelowego ---
const targetPricelistId = ref<number | null>(null);

/** Cennik porównawczy/docelowy + VAT są PER KANAŁ — wczytaj config bieżącego tabu z props.configs. */
function loadChannelConfig() {
    const c = props.configs?.[activeTab.value] ?? null;
    compareForm.pricelist_id = c?.pricelist_id ?? null;
    compareForm.vat = c?.vat != null ? Number(c.vat) : 23;
    targetPricelistId.value = c?.target_pricelist_id ?? null;
}
watch(activeTab, loadChannelConfig, { immediate: true });
watch(() => props.configs, loadChannelConfig);

const selected = ref<Set<number>>(new Set());

// selected = zbiór WYKLUCZONYCH ofert (UI mirror kolumny `excluded`). Domyślnie nic nie jest wykluczone.
function defaultSelection() {
    const s = new Set<number>();
    for (const p of current.value.data) {
        if (p.excluded) s.add(p.id);
    }
    selected.value = s;
}
watch(current, defaultSelection, { immediate: true });

/** Zapis trwałego stanu „Wyklucz" w bazie (przeżywa odświeżenie). */
async function persistExcluded(ids: number[], excluded: boolean) {
    if (!ids.length) return;
    try {
        await axios.post(route("crafter.scope.rumuni.excluded"), { ids, excluded });
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd zapisu wykluczenia.");
    }
}

function toggleSel(id: number) {
    const willExclude = !selected.value.has(id);
    willExclude ? selected.value.add(id) : selected.value.delete(id);
    selected.value = new Set(selected.value);
    const p = current.value.data.find((x) => x.id === id);
    if (p) p.excluded = willExclude;
    persistExcluded([id], willExclude);
}

// Master „wyklucz wszystkie" — kwalifikujące się (zmapowane) wiersze na bieżącej stronie.
const eligibleIds = computed(() => current.value.data.filter((p) => p.product).map((p) => p.id));
const allSelected = computed(() => eligibleIds.value.length > 0 && eligibleIds.value.every((id) => selected.value.has(id)));
const someSelected = computed(() => eligibleIds.value.some((id) => selected.value.has(id)));
function toggleSelectAll() {
    const exclude = !allSelected.value;
    const ids = eligibleIds.value;
    const s = new Set(selected.value);
    ids.forEach((id) => (exclude ? s.add(id) : s.delete(id)));
    selected.value = s;
    for (const p of current.value.data) {
        if (ids.includes(p.id)) p.excluded = exclude;
    }
    persistExcluded(ids, exclude);
}

async function saveTarget() {
    try {
        await axios.post(route("crafter.scope.rumuni.target"), { source: activeTab.value, target_pricelist_id: targetPricelistId.value });
    } catch {
        /* ciche */
    }
}

const matching = ref(false);
async function matchSku() {
    matching.value = true;
    try {
        const { data } = await axios.post(route("crafter.scope.rumuni.match"), { source: activeTab.value });
        if (data.ok) {
            toast.success(data.message);
            router.reload({ only: ["ebay", "stahl", "wegry", "rumunia", "francja", "czechy", "hiszpania", "unmapped"] });
        } else {
            toast.error(data.message);
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd przypisywania do SKU.");
    } finally {
        matching.value = false;
    }
}

async function updateAll() {
    if (!targetPricelistId.value) {
        toast.error("Najpierw wybierz cennik docelowy.");
        return;
    }
    if (!window.confirm(`Wgrać ceny zmapowanych ofert (${currentLabel.value}) do cennika — POMIJAJĄC wykluczone? Idzie niższa z (eBay netto, nasza). Nadpisze istniejące pozycje.`)) {
        return;
    }
    try {
        const { data } = await axios.post(route("crafter.scope.rumuni.update-all"), {
            source: activeTab.value,
            target_pricelist_id: targetPricelistId.value,
        });
        if (data.ok) toast.success(`Zaktualizowano ${data.count} pozycji w cenniku.`);
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd aktualizacji cennika.");
    }
}
</script>
