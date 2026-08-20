<!--
  Marketplace → eBay → kType (kompatybilność pojazdów).
  Podgląd fitmentu z eBaya + uruchamianie automatu `ebay:ktype-push` na zaznaczonych aukcjach.
  Wysyłka zawsze po dry-runie — fitment z cudzej marki jest gorszy niż jego brak.
-->
<template>
    <PageHeader title="Marketplace — eBay · kType (kompatybilność pojazdów)" />

    <PageContent fluid>
        <div class="mb-4 text-sm text-gray-500">
            Argo Connect → Marketplace → eBay → <span class="font-medium text-gray-700">kType</span>
        </div>

        <div v-if="!meta.oauth_connected" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Konto eBay nie jest połączone — fitment czyta się i zapisuje na koncie sprzedawcy.
            <Link :href="route('crafter.connect.integrations.ebay.index')" class="font-medium underline">Połącz konto</Link>.
        </div>

        <!-- POKRYCIE -->
        <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            <button v-for="c in cards" :key="c.key" type="button" @click="setFitment(c.key)"
                    class="rounded-lg border p-3 text-left transition-colors"
                    :class="f.fitment === c.key ? 'border-primary-400 bg-primary-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                <div class="text-2xl font-semibold" :class="c.color">{{ c.value }}</div>
                <div class="text-xs text-gray-600">{{ c.label }}</div>
            </button>
        </div>

        <!-- FILTRY -->
        <Card class="mb-5">
            <CardContent>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Rynek</label>
                        <select v-model="f.marketplace" class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" @change="reload">
                            <option v-for="m in marketplaces" :key="m" :value="m">{{ m }}</option>
                        </select>
                    </div>
                    <div class="min-w-[16rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Szukaj</label>
                        <input v-model="f.search" type="text" placeholder="tytuł, SKU lub ItemID"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" @keyup.enter="reload" />
                    </div>
                    <Button variant="outline" color="gray" @click="reload">Filtruj</Button>
                </div>

                <p v-if="Object.keys(registryCounts).length" class="mt-3 text-xs text-gray-500">
                    Rejestr automatu:
                    <span v-for="(n, s) in registryCounts" :key="s" class="mr-2">
                        <span class="font-medium">{{ s }}</span>: {{ n }}
                    </span>
                    <span class="text-gray-400">— statusy {{ retryable.join(', ') }} wracają do puli po poprawce dopasowania.</span>
                </p>
            </CardContent>
        </Card>

        <!-- PASEK ZAZNACZENIA -->
        <div v-if="selected.size" class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 p-3">
            <span class="text-sm font-medium text-primary-900">Zaznaczono: {{ selected.size }}</span>
            <Button size="sm" variant="outline" color="gray" :loading="refreshing" :disabled="!meta.oauth_connected" @click="refresh">
                Sprawdź fitment
            </Button>
            <Button size="sm" color="primary" :loading="running" @click="runAutomat(false)">Automat — podgląd</Button>
            <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-gray-700">
                <input v-model="fromTitle" type="checkbox" class="rounded text-primary-600 focus:ring-primary-500" />
                pojazd z tytułu aukcji
            </label>
            <Button size="sm" variant="outline" color="gray" @click="selected.clear()">Wyczyść</Button>
            <span v-if="selected.size > maxBatch" class="text-xs text-amber-700">
                Automat bierze naraz najwyżej {{ maxBatch }} aukcji.
            </span>
        </div>

        <!-- LISTA -->
        <Card>
            <CardContent class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="w-8 py-2"><input type="checkbox" :checked="allOnPage" class="rounded" @change="toggleAll" /></th>
                            <th class="py-2 pr-4">Aukcja</th>
                            <th class="py-2 pr-4">SKU</th>
                            <th class="py-2 pr-4 text-right">Fitment</th>
                            <th class="py-2 pr-4">Status automatu</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="o in offers.data" :key="o.id" :class="selected.has(o.id) ? 'bg-primary-50/40' : ''">
                            <td class="py-2"><input type="checkbox" :checked="selected.has(o.id)" class="rounded" @change="toggle(o.id)" /></td>
                            <td class="py-2 pr-4">
                                <a :href="o.listing_url ?? '#'" target="_blank" class="text-gray-900 hover:underline">{{ o.title }}</a>
                                <div class="font-mono text-[10px] text-gray-400">{{ o.item_id }}</div>
                            </td>
                            <td class="py-2 pr-4 font-mono text-xs">{{ o.sku || '—' }}</td>
                            <td class="py-2 pr-4 text-right">
                                <span v-if="o.compat_count === null" class="text-xs text-gray-400">niesprawdzone</span>
                                <span v-else-if="o.compat_count > 0" class="font-medium text-green-700">{{ o.compat_count }}</span>
                                <span v-else class="text-xs font-medium text-amber-700">brak</span>
                            </td>
                            <td class="py-2 pr-4">
                                <span v-if="o.ktype_status" class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                      :class="o.ktype_status === 'pushed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'">
                                    {{ o.ktype_status }}
                                </span>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </td>
                            <td class="py-2 text-right">
                                <Button variant="outline" color="gray" size="sm" :loading="previewId === o.id" :disabled="!meta.oauth_connected" @click="showFitment(o)">
                                    Podgląd
                                </Button>
                                <Button variant="outline" color="primary" size="sm" class="ml-1" :loading="manualLoadingId === o.id" :disabled="!meta.oauth_connected" @click="openManual(o)">
                                    Dopasuj ręcznie
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="offers.links?.length" class="mt-4 flex flex-wrap gap-1">
                    <Link v-for="(l, i) in offers.links" :key="i" :href="l.url ?? ''"
                          class="rounded px-2 py-1 text-xs" :class="l.active ? 'bg-primary-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                          v-html="l.label" />
                </div>
            </CardContent>
        </Card>

        <!-- MODAL: PODGLĄD FITMENTU -->
        <div v-if="fitmentData" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="fitmentData = null">
            <div class="max-h-[85vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold">{{ fitmentData.title }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    ItemID <span class="font-mono">{{ fitmentData.item_id }}</span> ·
                    pojazdów: <span class="font-medium">{{ fitmentData.count }}</span>
                    <span v-if="fitmentData.count > fitmentData.list.length"> (pokazuję {{ fitmentData.list.length }})</span>
                </p>

                <p v-if="!fitmentData.count" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    Ta aukcja nie ma jeszcze żadnej kompatybilności — kupujący nie znajdzie jej przez wyszukiwarkę pojazdu.
                </p>

                <table v-else class="mt-4 min-w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                        <tr><th v-for="c in fitmentColumns" :key="c" class="py-2 pr-4">{{ c }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="(row, i) in fitmentData.list" :key="i">
                            <td v-for="c in fitmentColumns" :key="c" class="py-1.5 pr-4">{{ row.props[c] ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="mt-5"><Button color="primary" @click="fitmentData = null">Zamknij</Button></div>
            </div>
        </div>

        <!-- MODAL: RĘCZNE DOPASOWANIE -->
        <div v-if="manual" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="manual = null">
            <div class="max-h-[88vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold">Ręczne dopasowanie pojazdów</h2>
                <p class="mt-1 text-sm text-gray-600">{{ manual.offer.title }}</p>
                <p class="font-mono text-xs text-gray-400">{{ manual.offer.item_id }}</p>

                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    Zapis <span class="font-semibold">zastępuje</span> całą dotychczasową listę kompatybilności
                    (tak działa eBay — nie dokłada, tylko podmienia).
                    <template v-if="manual.offer.compat_count">
                        Ta aukcja ma teraz <span class="font-semibold">{{ manual.offer.compat_count }}</span> wpisów —
                        jeśli chcesz je zachować, dodaj je poniżej razem z nowymi.
                    </template>
                </div>

                <!-- WYBÓR POJAZDU -->
                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Marka</label>
                        <select v-model="v.make" class="w-full rounded-md border-gray-300 text-sm" :disabled="loadingProp === 'make'" @change="onMakeChange">
                            <option :value="null">{{ loadingProp === 'make' ? 'ładuję…' : '— wybierz —' }}</option>
                            <option v-for="m in opts.make" :key="m" :value="m">{{ m }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Model</label>
                        <select v-model="v.model" class="w-full rounded-md border-gray-300 text-sm" :disabled="!v.make || loadingProp === 'model'" @change="onModelChange">
                            <option :value="null">{{ loadingProp === 'model' ? 'ładuję…' : '— wybierz —' }}</option>
                            <option v-for="m in opts.model" :key="m" :value="m">{{ m }}</option>
                        </select>
                    </div>
                    <div v-if="manual.properties.platform">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Platforma <span class="text-gray-400">(opcjonalnie)</span></label>
                        <select v-model="v.platform" class="w-full rounded-md border-gray-300 text-sm" :disabled="!v.model || loadingProp === 'platform'" @change="loadYears">
                            <option :value="null">{{ loadingProp === 'platform' ? 'ładuję…' : '— dowolna —' }}</option>
                            <option v-for="p in opts.platform" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Roczniki</label>
                        <div class="flex items-center gap-1">
                            <select v-model="v.yearFrom" class="w-full rounded-md border-gray-300 text-sm" :disabled="!opts.year.length">
                                <option :value="null">od</option>
                                <option v-for="y in opts.year" :key="y" :value="y">{{ y }}</option>
                            </select>
                            <span class="text-gray-400">–</span>
                            <select v-model="v.yearTo" class="w-full rounded-md border-gray-300 text-sm" :disabled="!opts.year.length">
                                <option :value="null">do</option>
                                <option v-for="y in opts.year" :key="y" :value="y">{{ y }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mt-3 flex items-center gap-3">
                    <Button size="sm" color="primary" :disabled="!canAdd" @click="addVehicle">Dodaj do listy</Button>
                    <span class="text-xs text-gray-500">
                        Bliźniaki badge'owe (np. Citroen Dispatch = Peugeot Expert = Toyota Proace) dodaj jako osobne pozycje.
                    </span>
                </div>

                <p v-if="manualError" class="mt-2 text-sm text-red-600">{{ manualError }}</p>

                <!-- LISTA DO ZAPISU -->
                <div v-if="vehicles.length" class="mt-5">
                    <h3 class="text-sm font-semibold text-gray-900">Do zapisania ({{ entryCount }} wpisów)</h3>
                    <table class="mt-2 min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(g, i) in vehicles" :key="i">
                                <td class="py-1.5">{{ g.make }} · {{ g.model }}<span v-if="g.platform" class="text-gray-500"> · {{ g.platform }}</span></td>
                                <td class="py-1.5 text-gray-600">{{ g.years[0] }}–{{ g.years[g.years.length - 1] }} ({{ g.years.length }} roczników)</td>
                                <td class="py-1.5 text-right">
                                    <button type="button" class="text-xs text-red-600 hover:underline" @click="vehicles.splice(i, 1)">usuń</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 flex items-center gap-3">
                    <Button color="primary" :loading="savingManual" :disabled="!vehicles.length" @click="saveManual">
                        Zapisz na eBay ({{ entryCount }})
                    </Button>
                    <Button variant="outline" color="gray" @click="manual = null">Anuluj</Button>
                </div>
            </div>
        </div>

        <!-- MODAL: WYNIK AUTOMATU -->
        <div v-if="runData" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="runData = null">
            <div class="max-h-[85vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold">
                    Automat kType — {{ runData.applied ? 'wysyłka' : 'podgląd (nic nie wysłano)' }}
                </h2>
                <p class="mt-1 text-sm text-gray-500">Aukcji: {{ runData.items }} · rynek {{ f.marketplace }}</p>

                <pre class="mt-4 max-h-[50vh] overflow-auto rounded-lg bg-gray-900 p-4 text-xs leading-relaxed text-gray-100">{{ runData.output }}</pre>

                <div class="mt-5 flex items-center gap-3">
                    <Button v-if="!runData.applied" color="primary" :loading="running" :disabled="!meta.oauth_connected" @click="runAutomat(true)">
                        Wyślij na eBay
                    </Button>
                    <Button variant="outline" color="gray" @click="finish">Zamknij</Button>
                    <span v-if="!runData.applied" class="text-xs text-gray-500">
                        Sprawdź powyżej, czy marki i modele się zgadzają — fitment z cudzej marki szkodzi bardziej niż jego brak.
                    </span>
                </div>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Card, CardContent } from "crafter/Components";

interface Row {
    id: number; item_id: string; sku: string | null; title: string;
    listing_url: string | null; product_id: number | null;
    compat_count: number | null; compat_checked_at: string | null; ktype_status: string | null;
}
interface Props {
    offers: { data: Row[]; total: number; links: Array<{ url: string | null; label: string; active: boolean }> };
    marketplaces: string[];
    totals: { listings: number; with: number; without: number; unknown: number };
    maxBatch: number;
    registryCounts: Record<string, number>;
    filters: { marketplace: string; fitment: string; search: string };
    meta: { oauth_connected: boolean };
}

const props = defineProps<Props>();
const toast = useToast();

const retryable = ["unmatched", "no_years", "no_platform"];
const f = reactive({ ...props.filters });
const selected = reactive(new Set<number>());
const fromTitle = ref(false);
const refreshing = ref(false);
const running = ref(false);
const previewId = ref<number | null>(null);
const fitmentData = ref<any>(null);
const runData = ref<any>(null);

const cards = computed(() => [
    { key: "", label: "Aukcji na rynku", value: props.totals.listings, color: "text-gray-900" },
    { key: "with", label: "Z fitmentem", value: props.totals.with, color: "text-green-700" },
    { key: "without", label: "Bez fitmentu", value: props.totals.without, color: "text-amber-700" },
    { key: "unknown", label: "Niesprawdzone", value: props.totals.unknown, color: "text-gray-400" },
]);

const allOnPage = computed(() => props.offers.data.length > 0 && props.offers.data.every((o) => selected.has(o.id)));

/** Nazwy właściwości różnią się per rynek (Make / FR_Make / ES_Make), więc kolumny bierzemy z danych. */
const fitmentColumns = computed(() => {
    const keys = new Set<string>();
    (fitmentData.value?.list ?? []).forEach((r: any) => Object.keys(r.props ?? {}).forEach((k) => keys.add(k)));
    return [...keys];
});

function toggle(id: number) { selected.has(id) ? selected.delete(id) : selected.add(id); }
function toggleAll() {
    allOnPage.value ? props.offers.data.forEach((o) => selected.delete(o.id)) : props.offers.data.forEach((o) => selected.add(o.id));
}
function setFitment(key: string) { f.fitment = f.fitment === key ? "" : key; reload(); }

function reload() {
    router.get(route("crafter.connect.marketplace.ebay.ktype.index"), {
        marketplace: f.marketplace,
        fitment: f.fitment || undefined,
        search: f.search || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function showFitment(o: Row) {
    previewId.value = o.id;
    axios
        .get(route("crafter.connect.marketplace.ebay.ktype.fitment", o.id))
        .then((r) => { fitmentData.value = r.data; })
        .catch((e) => toast.error(e.response?.data?.error ?? "Nie udało się pobrać fitmentu"))
        .finally(() => { previewId.value = null; });
}

function refresh() {
    refreshing.value = true;
    axios
        .post(route("crafter.connect.marketplace.ebay.ktype.refresh"), { ids: [...selected].slice(0, props.maxBatch) })
        .then((r) => {
            toast.success(`Sprawdzono ${r.data.checked}: z fitmentem ${r.data.with}, bez ${r.data.without}`);
            if (r.data.errors?.length) toast.error(r.data.errors[0]);
            router.reload({ only: ["offers", "totals"] });
        })
        .catch((e) => toast.error(e.response?.data?.error ?? "Nie udało się sprawdzić"))
        .finally(() => { refreshing.value = false; });
}

function runAutomat(apply: boolean) {
    running.value = true;
    axios
        .post(route("crafter.connect.marketplace.ebay.ktype.run"), {
            ids: [...selected].slice(0, props.maxBatch),
            marketplace: f.marketplace,
            apply,
            from_title: fromTitle.value,
        })
        .then((r) => { runData.value = r.data; })
        .catch((e) => toast.error(e.response?.data?.error ?? "Automat nie wystartował"))
        .finally(() => { running.value = false; });
}

function finish() {
    const applied = runData.value?.applied;
    runData.value = null;
    if (applied) { selected.clear(); router.reload(); }
}

// --- Ręczne dopasowanie ---------------------------------------------------
// Kaskada marka → model → platforma → rocznik, każdy krok pytany o eBaya, bo tylko jego
// baza pojazdów decyduje, jakie kombinacje w ogóle istnieją. Nazwy właściwości różnią się
// per rynek, więc trzymamy je w `manual.properties` i wysyłamy dokładnie takie, jakie przyszły.

const manual = ref<any>(null);
const manualLoadingId = ref<number | null>(null);
const manualError = ref<string | null>(null);
const savingManual = ref(false);
const loadingProp = ref<string | null>(null);

const v = reactive<{ make: string | null; model: string | null; platform: string | null; yearFrom: string | null; yearTo: string | null }>(
    { make: null, model: null, platform: null, yearFrom: null, yearTo: null },
);
const opts = reactive<Record<string, string[]>>({ make: [], model: [], platform: [], year: [] });
const vehicles = ref<Array<{ make: string; model: string; platform: string | null; years: string[] }>>([]);

const entryCount = computed(() => vehicles.value.reduce((n, g) => n + g.years.length, 0));
const canAdd = computed(() => !!v.make && !!v.model && !!v.yearFrom && !!v.yearTo);

function resetVehicleForm() {
    v.make = v.model = v.platform = v.yearFrom = v.yearTo = null;
    opts.model = []; opts.platform = []; opts.year = [];
}

function askOptions(property: string, filters: Record<string, string>, into: string) {
    loadingProp.value = into;
    manualError.value = null;
    return axios
        .post(route("crafter.connect.marketplace.ebay.ktype.vehicle-options", manual.value.offer.id), { property, filters })
        .then((r) => { opts[into] = r.data.values ?? []; })
        .catch((e) => { manualError.value = e.response?.data?.error ?? "Nie udało się pobrać listy z eBaya"; })
        .finally(() => { loadingProp.value = null; });
}

function openManual(o: Row) {
    manualLoadingId.value = o.id;
    manualError.value = null;
    axios
        .post(route("crafter.connect.marketplace.ebay.ktype.vehicle-options", o.id), {})
        .then((r) => {
            manual.value = { offer: o, properties: r.data.properties };
            vehicles.value = [];
            resetVehicleForm();
            return askOptions(r.data.properties.make, {}, "make");
        })
        .catch((e) => toast.error(e.response?.data?.error ?? "Nie udało się otworzyć dopasowania"))
        .finally(() => { manualLoadingId.value = null; });
}

function onMakeChange() {
    v.model = v.platform = v.yearFrom = v.yearTo = null;
    opts.model = []; opts.platform = []; opts.year = [];
    if (!v.make) return;
    const p = manual.value.properties;
    askOptions(p.model, { [p.make]: v.make }, "model");
}

function onModelChange() {
    v.platform = v.yearFrom = v.yearTo = null;
    opts.platform = []; opts.year = [];
    if (!v.model) return;
    const p = manual.value.properties;
    const filters = { [p.make]: v.make!, [p.model]: v.model! };
    if (p.platform) askOptions(p.platform, filters, "platform");
    loadYears();
}

function loadYears() {
    v.yearFrom = v.yearTo = null;
    const p = manual.value.properties;
    const filters: Record<string, string> = { [p.make]: v.make!, [p.model]: v.model! };
    if (p.platform && v.platform) filters[p.platform] = v.platform;
    askOptions(p.year, filters, "year").then(() => {
        // eBay zwraca roczniki nieuporządkowane — bez sortowania zakres „od–do" nie miałby sensu.
        opts.year = [...opts.year].sort();
        if (opts.year.length) { v.yearFrom = opts.year[0]; v.yearTo = opts.year[opts.year.length - 1]; }
    });
}

function addVehicle() {
    const from = opts.year.indexOf(v.yearFrom!);
    const to = opts.year.indexOf(v.yearTo!);
    if (from < 0 || to < 0 || from > to) {
        manualError.value = "Zakres roczników jest odwrotny — rocznik „od” musi być wcześniejszy niż „do”.";
        return;
    }
    vehicles.value.push({
        make: v.make!, model: v.model!, platform: v.platform,
        years: opts.year.slice(from, to + 1),
    });
    resetVehicleForm();
    askOptions(manual.value.properties.make, {}, "make");
}

function saveManual() {
    const p = manual.value.properties;
    // Jeden wpis na rocznik — eBay sam rozwija go na wersje silnikowe.
    const entries = vehicles.value.flatMap((g) =>
        g.years.map((year) => {
            const e: Record<string, string> = { [p.make]: g.make, [p.model]: g.model, [p.year]: year };
            if (p.platform && g.platform) e[p.platform] = g.platform;
            return e;
        }),
    );

    savingManual.value = true;
    axios
        .post(route("crafter.connect.marketplace.ebay.ktype.manual", manual.value.offer.id), { entries })
        .then((r) => {
            const lost = r.data.sent - r.data.count;
            toast.success(
                `Zapisano: eBay przyjął ${r.data.count} z ${r.data.sent} wpisów` +
                (lost > 0 ? ` (${lost} pominął jako nieznane kombinacje)` : ""),
            );
            if (r.data.warnings?.length) toast.error(r.data.warnings[0]);
            manual.value = null;
            router.reload({ only: ["offers", "totals"] });
        })
        .catch((e) => { manualError.value = e.response?.data?.error ?? "Zapis nie powiódł się"; })
        .finally(() => { savingManual.value = false; });
}
</script>
