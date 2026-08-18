<!--
  Marketplace → eBay → Kategorie i parametry.
  Wzorzec: Connect/Marketplace/Allegro/Categories/Index.vue z OMS ARGO — ten sam przepływ
  (szukaj → aktywuj → mapuj → zapisz), nasze komponenty (crafter/Components, bez skóry Fable).

  Różnica wobec Allegro: kluczem jest para (rynek, kategoria). Ta sama półka ma inne ID i inne
  nazwy aspektów na każdym rynku, więc wyszukiwarka ma selektor rynku, a lista jest po nim grupowana.
-->
<template>
    <PageHeader title="Marketplace — eBay · Kategorie i parametry" />

    <PageContent fluid>
        <div class="mb-4 text-sm text-gray-500">
            Argo Connect → Marketplace → eBay → <span class="font-medium text-gray-700">Kategorie i parametry</span>
        </div>

        <div v-if="!hasCredentials" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Brak kluczy eBay — uzupełnij je w
            <Link :href="route('crafter.connect.integrations.ebay.index')" class="font-medium underline">Integracje → Ebay</Link>.
            Ten ekran czyta Taxonomy API tokenem aplikacyjnym, więc <span class="font-medium">nie wymaga połączonego konta OAuth</span>.
        </div>

        <!-- WYSZUKIWARKA -->
        <Card class="mb-6">
            <CardHeader>
                <h2 class="text-lg font-semibold">Znajdź kategorię</h2>
                <p class="text-sm text-gray-500">
                    Wpisz frazę (np. <code>Unterfahrschutz</code>) albo ID kategorii z API. Aktywowanie zaciąga
                    aspekty (Item Specifics) — czyli to, czego eBay wymaga przy wystawianiu.
                </p>
            </CardHeader>
            <CardContent>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Rynek</label>
                        <select v-model="marketplace" class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option v-for="m in marketplaces" :key="m" :value="m">{{ m }}</option>
                        </select>
                    </div>
                    <div class="min-w-[18rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Nazwa lub ID kategorii</label>
                        <input
                            v-model="query"
                            type="text"
                            placeholder="Unterfahrschutz albo 14769"
                            class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                            @keyup.enter="search"
                        />
                    </div>
                    <Button :leftIcon="MagnifyingGlassIcon" color="primary" :loading="searching" :disabled="!query || !hasCredentials" @click="search">
                        Szukaj
                    </Button>
                </div>

                <p v-if="searchError" class="mt-3 text-sm text-red-600">{{ searchError }}</p>

                <div v-if="results.length" class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="py-2 pr-4">ID</th>
                                <th class="py-2 pr-4">Nazwa</th>
                                <th class="py-2 pr-4">Ścieżka</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="r in results" :key="r.id">
                                <td class="py-2 pr-4 font-mono text-xs">{{ r.id }}</td>
                                <td class="py-2 pr-4 font-medium text-gray-900">{{ r.name }}</td>
                                <td class="py-2 pr-4 text-xs text-gray-500">{{ r.path }}</td>
                                <td class="py-2 text-right">
                                    <span v-if="isLearned(r.id)" class="text-xs font-medium text-green-700">✓ nauczona</span>
                                    <Button v-else variant="outline" color="gray" size="sm" :loading="activatingId === r.id" @click="activate(r)">
                                        Aktywuj
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else-if="searchedOnce && !searchError" class="mt-3 text-sm text-gray-500">Brak wyników.</p>
            </CardContent>
        </Card>

        <!-- NAUCZONE KATEGORIE -->
        <Card v-if="!categories.length">
            <CardContent>
                <p class="py-6 text-center text-sm text-gray-500">
                    Żadna kategoria nie jest jeszcze nauczona. Znajdź ją wyżej i kliknij „Aktywuj".
                </p>
            </CardContent>
        </Card>

        <Card v-for="cat in categories" :key="cat.id" class="mb-4">
            <CardHeader>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ cat.marketplace }}</span>
                            <h3 class="text-base font-semibold text-gray-900">{{ cat.category_name }}</h3>
                            <span class="font-mono text-xs text-gray-400">#{{ cat.category_id }}</span>
                        </div>
                        <p v-if="cat.category_path" class="mt-1 text-xs text-gray-500">{{ cat.category_path }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            Aspektów: {{ cat.aspects.length }} · wymaganych: {{ requiredOf(cat).length }} ·
                            <span :class="missing(cat).length ? 'font-medium text-amber-700' : 'font-medium text-green-700'">
                                {{ missing(cat).length ? `brakuje źródła: ${missing(cat).join(', ')}` : 'gotowa do wystawiania' }}
                            </span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button variant="outline" color="gray" size="sm" @click="toggle(cat.id)">
                            {{ expanded[cat.id] ? 'Zwiń' : 'Mapowanie' }}
                        </Button>
                        <Button variant="outline" color="gray" size="sm" :leftIcon="ArrowPathIcon" @click="refresh(cat)">Odśwież</Button>
                        <Button variant="outline" color="red" size="sm" @click="destroy(cat)">Usuń</Button>
                    </div>
                </div>
            </CardHeader>

            <CardContent v-if="expanded[cat.id]">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-4">Aspekt eBay</th>
                            <th class="py-2 pr-4">Tryb</th>
                            <th class="py-2 pr-4">Skąd wartość</th>
                            <th class="py-2">Wartość / pole</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="a in sortedAspects(cat)" :key="a.name" :class="a.required ? 'bg-amber-50/40' : ''">
                            <td class="py-2 pr-4">
                                <span class="font-medium text-gray-900">{{ a.name }}</span>
                                <span v-if="a.required" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-800">wymagany</span>
                                <span v-if="a.cardinality === 'MULTI'" class="ml-1 text-[10px] text-gray-400">wiele wartości</span>
                            </td>
                            <td class="py-2 pr-4 text-xs text-gray-500">
                                {{ a.mode === 'SELECTION_ONLY' ? 'ze słownika' : 'dowolny tekst' }}
                                <span v-if="a.value_count" class="text-gray-400">({{ a.value_count }})</span>
                            </td>
                            <td class="py-2 pr-4">
                                <select
                                    :value="maps[cat.id]?.[a.name]?.source || ''"
                                    class="w-40 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                                    @change="setSource(cat.id, a.name, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">— nie wysyłaj —</option>
                                    <option value="fixed">Stała wartość</option>
                                    <option value="attribute">Atrybut produktu</option>
                                    <option value="product_field">Pole produktu</option>
                                </select>
                            </td>
                            <td class="py-2">
                                <!-- Stała: przy SELECTION_ONLY dajemy listę dopuszczalnych wartości, inaczej eBay odrzuci ofertę. -->
                                <template v-if="maps[cat.id]?.[a.name]?.source === 'fixed'">
                                    <select
                                        v-if="a.mode === 'SELECTION_ONLY' && a.values.length"
                                        :value="maps[cat.id][a.name].value || ''"
                                        class="w-64 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                                        @change="setField(cat.id, a.name, 'value', ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="">— wybierz —</option>
                                        <option v-for="v in a.values" :key="v" :value="v">{{ v }}</option>
                                    </select>
                                    <input
                                        v-else
                                        :value="maps[cat.id][a.name].value || ''"
                                        type="text"
                                        :list="a.values.length ? `vals-${cat.id}-${slug(a.name)}` : undefined"
                                        placeholder="np. BSP"
                                        class="w-64 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                                        @input="setField(cat.id, a.name, 'value', ($event.target as HTMLInputElement).value)"
                                    />
                                    <datalist v-if="a.values.length && a.mode !== 'SELECTION_ONLY'" :id="`vals-${cat.id}-${slug(a.name)}`">
                                        <option v-for="v in a.values" :key="v" :value="v" />
                                    </datalist>
                                </template>

                                <select
                                    v-else-if="maps[cat.id]?.[a.name]?.source === 'attribute'"
                                    :value="maps[cat.id][a.name].attribute_id || ''"
                                    class="w-64 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                                    @change="setField(cat.id, a.name, 'attribute_id', Number(($event.target as HTMLSelectElement).value) || null)"
                                >
                                    <option value="">— wybierz atrybut —</option>
                                    <option v-for="at in attributes" :key="at.id" :value="at.id">{{ at.name }}</option>
                                </select>

                                <select
                                    v-else-if="maps[cat.id]?.[a.name]?.source === 'product_field'"
                                    :value="maps[cat.id][a.name].field || ''"
                                    class="w-64 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                                    @change="setField(cat.id, a.name, 'field', ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">— wybierz pole —</option>
                                    <option v-for="f in productFields" :key="f.key" :value="f.key">{{ f.label }}</option>
                                </select>

                                <span v-else class="text-xs text-gray-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="mt-4 flex items-center gap-3">
                    <Button color="primary" @click="saveMapping(cat)">Zapisz mapowanie</Button>
                    <label class="inline-flex cursor-pointer items-center gap-2">
                        <input v-model="activeFlags[cat.id]" type="checkbox" class="rounded text-primary-600 focus:ring-primary-500" />
                        <span class="text-sm" :class="activeFlags[cat.id] ? 'text-green-700' : 'text-gray-400'">
                            {{ activeFlags[cat.id] ? 'Aktywna (widoczna w schematach)' : 'Nieaktywna' }}
                        </span>
                    </label>
                    <span v-if="cat.last_synced_at" class="ml-auto text-xs text-gray-400">
                        aspekty z: {{ new Date(cat.last_synced_at).toLocaleString('pl-PL') }}
                    </span>
                </div>
            </CardContent>
        </Card>
    </PageContent>
</template>

<script setup lang="ts">
import { reactive, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import axios from "axios";
import { MagnifyingGlassIcon, ArrowPathIcon } from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Card, CardHeader, CardContent } from "crafter/Components";

interface Aspect {
    name: string;
    required: boolean;
    mode: string;
    cardinality: string;
    value_count: number;
    values: string[];
}
interface MapEntry { source?: string; attribute_id?: number | null; value?: string; field?: string }
interface Category {
    id: number;
    marketplace: string;
    category_id: string;
    category_name: string | null;
    category_path: string | null;
    leaf: boolean;
    active: boolean;
    aspects: Aspect[];
    aspect_map: Record<string, MapEntry>;
    unmapped_required: string[];
    last_synced_at: string | null;
}
interface Props {
    categories: Category[];
    attributes: Array<{ id: number; name: string }>;
    productFields: Array<{ key: string; label: string }>;
    marketplaces: string[];
    hasCredentials: boolean;
}

const props = defineProps<Props>();
const toast = useToast();

// --- Wyszukiwarka ---
const marketplace = ref(props.marketplaces[0] ?? "EBAY_DE");
const query = ref("");
const results = ref<Array<{ id: string; name: string; path: string }>>([]);
const searching = ref(false);
const searchedOnce = ref(false);
const searchError = ref<string | null>(null);
const activatingId = ref<string | null>(null);

// Nauczona = ta sama para (rynek, kategoria). To samo ID na innym rynku to inna kategoria.
const isLearned = (categoryId: string) =>
    props.categories.some((c) => c.category_id === categoryId && c.marketplace === marketplace.value);

function search() {
    if (!query.value) return;
    searching.value = true;
    searchError.value = null;
    axios
        .post(route("crafter.connect.marketplace.ebay.categories.search"), { q: query.value, marketplace: marketplace.value })
        .then((r) => {
            results.value = r.data.results ?? [];
            searchError.value = r.data.error ?? null;
            searchedOnce.value = true;
        })
        .catch(() => toast.error("Błąd wyszukiwania"))
        .finally(() => { searching.value = false; });
}

function activate(r: { id: string; name: string; path: string }) {
    activatingId.value = r.id;
    axios
        .post(route("crafter.connect.marketplace.ebay.categories.activate"), {
            marketplace: marketplace.value,
            category_id: r.id,
            category_name: r.name,
            category_path: r.path,
        })
        .then((res) => {
            toast.success(`Nauczono „${r.name}" — ${res.data.aspects_count} aspektów, wymaganych ${res.data.required_count}`);
            router.reload({ only: ["categories"] });
        })
        .catch((e) => toast.error(e.response?.data?.error ?? "Nie udało się aktywować"))
        .finally(() => { activatingId.value = null; });
}

// --- Mapowanie (lokalna kopia aspect_map per kategoria) ---
const maps = reactive<Record<number, Record<string, MapEntry>>>({});
const activeFlags = reactive<Record<number, boolean>>({});
const expanded = reactive<Record<number, boolean>>({});

props.categories.forEach((c) => {
    maps[c.id] = JSON.parse(JSON.stringify(c.aspect_map || {}));
    activeFlags[c.id] = c.active;
});

const toggle = (catId: number) => { expanded[catId] = !expanded[catId]; };

/** Wymagane na górze — to one blokują wystawianie, więc nie chowamy ich pod resztą listy. */
function sortedAspects(cat: Category): Aspect[] {
    return [...(cat.aspects || [])].sort((a, b) => Number(b.required) - Number(a.required));
}

const requiredOf = (cat: Category) => (cat.aspects || []).filter((a) => a.required);

/** Wymagane bez KOMPLETNEGO źródła — liczone na żywo, żeby licznik reagował na edycję przed zapisem. */
function missing(cat: Category): string[] {
    return requiredOf(cat)
        .filter((a) => {
            const e = maps[cat.id]?.[a.name];
            if (!e?.source) return true;
            if (e.source === "fixed") return !String(e.value ?? "").trim();
            if (e.source === "attribute") return !e.attribute_id;
            if (e.source === "product_field") return !e.field;
            return true;
        })
        .map((a) => a.name);
}

function setSource(catId: number, aspect: string, source: string) {
    if (!maps[catId]) maps[catId] = {};
    if (!source) { delete maps[catId][aspect]; return; }
    maps[catId][aspect] = { source };
}

function setField(catId: number, aspect: string, field: keyof MapEntry, value: any) {
    if (!maps[catId]) maps[catId] = {};
    if (!maps[catId][aspect]) maps[catId][aspect] = {};
    (maps[catId][aspect] as any)[field] = value;
}

/** id do datalistu — nazwy aspektów mają spacje i znaki narodowe („Oberflächenbeschaffenheit"). */
const slug = (s: string) => s.replace(/[^a-zA-Z0-9]/g, "");

function saveMapping(cat: Category) {
    router.put(
        route("crafter.connect.marketplace.ebay.categories.update", cat.id),
        { aspect_map: maps[cat.id] || {}, active: activeFlags[cat.id] },
        { preserveScroll: true, onError: () => toast.error("Nie udało się zapisać") },
    );
}

function refresh(cat: Category) {
    router.post(route("crafter.connect.marketplace.ebay.categories.refresh", cat.id), {}, { preserveScroll: true });
}

function destroy(cat: Category) {
    if (!window.confirm(`Usunąć kategorię „${cat.category_name}" (${cat.marketplace})? Mapowanie przepadnie.`)) return;
    router.delete(route("crafter.connect.marketplace.ebay.categories.destroy", cat.id), { preserveScroll: true });
}
</script>
