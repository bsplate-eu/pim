<!--
  Marketplace → eBay → Wystawianie.
  Wzorzec: Connect/Marketplace/Allegro/Listing/Index.vue z OMS ARGO — lista produktów,
  multi-select, „Wystaw wg schematu" → PODGLĄD → dopiero potem wysyłka.
-->
<template>
    <PageHeader title="Marketplace — eBay · Wystawianie" />

    <PageContent fluid>
        <div class="mb-4 text-sm text-gray-500">
            Argo Connect → Marketplace → eBay → <span class="font-medium text-gray-700">Wystawianie</span>
        </div>

        <div v-if="!meta.oauth_connected" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Konto eBay nie jest połączone — podgląd zadziała, ale wystawianie nie.
            <Link :href="route('crafter.connect.integrations.ebay.index')" class="font-medium underline">Połącz konto</Link>.
        </div>

        <!-- SCHEMAT + FILTRY -->
        <Card class="mb-5">
            <CardContent>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[16rem]">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Schemat</label>
                        <select v-model="schemeId" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" @change="reload">
                            <option :value="null">— wybierz schemat —</option>
                            <option v-for="s in schemes" :key="s.id" :value="s.id">
                                {{ s.name }} ({{ s.marketplace }}){{ s.ready ? '' : ' ⚠ niekompletny' }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-[14rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Szukaj</label>
                        <input v-model="f.search" type="text" placeholder="kod lub nazwa" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" @keyup.enter="reload" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Wystawione</label>
                        <select v-model="f.listed" class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" @change="reload">
                            <option value="">wszystkie</option>
                            <option value="0">niewystawione</option>
                            <option value="1">wystawione</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Cena</label>
                        <select v-model="f.priced" class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" @change="reload">
                            <option value="">wszystkie</option>
                            <option value="1">z ceną</option>
                            <option value="0">bez ceny</option>
                        </select>
                    </div>
                    <Button variant="outline" color="gray" @click="reload">Filtruj</Button>
                </div>

                <p v-if="priceSource" class="mt-3 text-xs text-gray-500">
                    Ceny wg cennika <span class="font-medium">{{ priceSource.pricelist ?? '—' }}</span>
                    × {{ priceSource.multiplier }} + {{ priceSource.tax }}% VAT (schemat „{{ priceSource.scheme }}").
                    Wystawionych na tym rynku: {{ listedCount }}.
                </p>
                <p v-if="activeScheme && !activeScheme.ready" class="mt-2 text-xs text-amber-700">
                    Schemat niekompletny: {{ activeScheme.problems.join(' · ') }}
                </p>
            </CardContent>
        </Card>

        <!-- PASEK ZAZNACZENIA -->
        <div v-if="selected.size" class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 p-3">
            <span class="text-sm font-medium text-primary-900">Zaznaczono: {{ selected.size }}</span>
            <Button size="sm" color="primary" :disabled="!schemeId" :loading="previewing" @click="preview">Wystaw wg schematu…</Button>
            <Button size="sm" variant="outline" color="gray" @click="selected.clear()">Wyczyść</Button>
            <span v-if="selected.size > maxBatch" class="text-xs text-amber-700">
                Limit jednej porcji to {{ maxBatch }} — nadmiar trzeba wystawić drugą turą.
            </span>
        </div>

        <!-- LISTA -->
        <Card>
            <CardContent class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="w-8 py-2"><input type="checkbox" :checked="allOnPage" class="rounded" @change="toggleAll" /></th>
                            <th class="w-14 py-2">Foto</th>
                            <th class="py-2 pr-4">Kod</th>
                            <th class="py-2 pr-4">Nazwa</th>
                            <th class="py-2 pr-4 text-right">Cena brutto</th>
                            <th class="py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="p in products.data" :key="p.id" :class="selected.has(p.id) ? 'bg-primary-50/40' : ''">
                            <td class="py-2"><input type="checkbox" :checked="selected.has(p.id)" class="rounded" @change="toggle(p.id)" /></td>
                            <td class="py-2">
                                <img v-if="p.thumbnail" :src="p.thumbnail" class="h-10 w-10 rounded border border-gray-200 object-contain" alt="" />
                                <div v-else class="flex h-10 w-10 items-center justify-center rounded border border-dashed border-gray-300 text-[10px] text-gray-400">brak</div>
                            </td>
                            <td class="py-2 pr-4 font-mono text-xs">{{ p.product_code }}</td>
                            <td class="py-2 pr-4">
                                <a :href="p.edit_url" class="text-gray-900 hover:underline">{{ p.name }}</a>
                            </td>
                            <td class="py-2 pr-4 text-right">
                                <span v-if="p.price" class="font-medium">{{ p.price.toFixed(2) }}</span>
                                <span v-else class="text-xs text-amber-700">brak ceny</span>
                            </td>
                            <td class="py-2">
                                <template v-if="p.listings.length">
                                    <a v-for="(l, i) in p.listings" :key="i" :href="l.url ?? '#'" target="_blank"
                                       class="mr-1 rounded bg-green-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-green-800">
                                        {{ l.status ?? 'szkic' }}
                                    </a>
                                </template>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="products.links?.length" class="mt-4 flex flex-wrap gap-1">
                    <Link v-for="(l, i) in products.links" :key="i" :href="l.url ?? ''"
                          class="rounded px-2 py-1 text-xs" :class="l.active ? 'bg-primary-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                          v-html="l.label" />
                </div>
            </CardContent>
        </Card>

        <!-- MODAL: PODGLĄD -->
        <div v-if="previewData" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="previewData = null">
            <div class="max-h-[85vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold">Podgląd wystawienia — {{ previewData.scheme }} ({{ previewData.marketplace }})</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Tryb: <span class="font-medium">{{ previewData.publication_mode === 'active' ? 'od razu aktywne' : 'szkic' }}</span> ·
                    produktów: {{ previewData.count }}
                </p>

                <div v-if="previewData.scheme_problems.length" class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    Schemat niekompletny: {{ previewData.scheme_problems.join(' · ') }}
                </div>
                <div v-if="previewData.blocked" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    {{ previewData.blocked }} pozycji zostanie odrzuconych (brak wymaganych danych),
                    {{ previewData.no_price }} bez ceny.
                </div>
                <div v-if="previewData.publication_mode === 'active'" class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    Uwaga: ten schemat wystawia <span class="font-semibold">od razu aktywne</span> aukcje — trafią do sprzedaży natychmiast.
                </div>

                <table class="mt-4 min-w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs uppercase text-gray-500">
                        <tr><th class="py-2 pr-3">Kod</th><th class="py-2 pr-3">Tytuł</th><th class="py-2 pr-3">Aspekty</th><th class="py-2 pr-3">Foto</th><th class="py-2 pr-3 text-right">Cena</th><th class="py-2">Uwagi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="(it, i) in previewData.items" :key="i" :class="it.blocking.length ? 'bg-red-50/50' : ''">
                            <td class="py-2 pr-3 font-mono text-xs">{{ it.product_code }}</td>
                            <td class="py-2 pr-3">{{ it.title }} <span class="text-xs text-gray-400">({{ it.title_length }}/80)</span></td>
                            <td class="py-2 pr-3">{{ it.aspects_count }}</td>
                            <td class="py-2 pr-3">{{ it.images_count }}</td>
                            <td class="py-2 pr-3 text-right">{{ it.price ? it.price.toFixed(2) : '—' }}</td>
                            <td class="py-2 text-xs">
                                <span v-if="it.blocking.length" class="text-red-700">{{ it.blocking.join(' ') }}</span>
                                <span v-else-if="it.notes.length" class="text-amber-700">{{ it.notes.join(' ') }}</span>
                                <span v-else class="text-green-700">ok</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="mt-5 flex items-center gap-3">
                    <Button color="primary" :loading="publishing" :disabled="!meta.oauth_connected || previewData.scheme_problems.length > 0" @click="doPublish">
                        Wystaw na eBay ({{ previewData.count - previewData.blocked }})
                    </Button>
                    <Button variant="outline" color="gray" @click="previewData = null">Anuluj</Button>
                    <span v-if="!meta.oauth_connected" class="text-xs text-amber-700">Konto eBay nie jest połączone.</span>
                </div>
            </div>
        </div>

        <!-- MODAL: WYNIK -->
        <div v-if="result" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="result = null">
            <div class="max-h-[85vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold">Wynik wystawiania</h2>
                <div class="mt-3 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg bg-green-50 p-3"><div class="text-2xl font-semibold text-green-700">{{ result.published.length }}</div><div class="text-xs text-green-800">wystawionych</div></div>
                    <div class="rounded-lg bg-red-50 p-3"><div class="text-2xl font-semibold text-red-700">{{ result.failed.length }}</div><div class="text-xs text-red-800">błędów</div></div>
                    <div class="rounded-lg bg-gray-50 p-3"><div class="text-2xl font-semibold text-gray-600">{{ result.skipped.length }}</div><div class="text-xs text-gray-600">pominiętych</div></div>
                </div>
                <div v-if="result.failed.length" class="mt-4">
                    <h3 class="text-sm font-semibold text-red-800">Błędy</h3>
                    <ul class="mt-1 space-y-1 text-xs text-gray-700">
                        <li v-for="(x, i) in result.failed" :key="i"><span class="font-mono">{{ x.product_code }}</span> — {{ x.error }}</li>
                    </ul>
                </div>
                <div v-if="result.skipped.length" class="mt-4">
                    <h3 class="text-sm font-semibold text-gray-700">Pominięte</h3>
                    <ul class="mt-1 space-y-1 text-xs text-gray-600">
                        <li v-for="(x, i) in result.skipped" :key="i"><span class="font-mono">{{ x.product_code }}</span> — {{ x.reason }}</li>
                    </ul>
                </div>
                <div class="mt-5"><Button color="primary" @click="finish">Zamknij</Button></div>
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

interface Listing { status: string | null; url: string | null }
interface Row { id: number; product_code: string; name: string; thumbnail: string | null; is_listed: boolean; price: number | null; edit_url: string; listings: Listing[] }
interface Scheme { id: number; name: string; marketplace: string; publication_mode: string; ready: boolean; problems: string[] }
interface Props {
    products: { data: Row[]; total: number; links: Array<{ url: string | null; label: string; active: boolean }> };
    schemes: Scheme[];
    selectedSchemeId: number | null;
    priceSource: { scheme: string; pricelist: string | null; multiplier: number; tax: number } | null;
    listedCount: number;
    sources: Array<{ id: number; name: string }>;
    maxBatch: number;
    meta: { oauth_connected: boolean; has_credentials: boolean };
    filters: Record<string, any>;
}

const props = defineProps<Props>();
const toast = useToast();

const schemeId = ref<number | null>(props.selectedSchemeId);
const f = reactive({
    search: props.filters.search ?? "",
    listed: props.filters.listed ?? "",
    priced: props.filters.priced ?? "",
});
const selected = reactive(new Set<number>());
const previewing = ref(false);
const publishing = ref(false);
const previewData = ref<any>(null);
const result = ref<any>(null);

const activeScheme = computed(() => props.schemes.find((s) => s.id === schemeId.value) ?? null);
const allOnPage = computed(() => props.products.data.length > 0 && props.products.data.every((p) => selected.has(p.id)));

function toggle(id: number) {
    selected.has(id) ? selected.delete(id) : selected.add(id);
}
function toggleAll() {
    allOnPage.value ? props.products.data.forEach((p) => selected.delete(p.id)) : props.products.data.forEach((p) => selected.add(p.id));
}

function reload() {
    router.get(route("crafter.connect.marketplace.ebay.listing.index"), {
        scheme_id: schemeId.value,
        "filter[search]": f.search || undefined,
        "filter[listed]": f.listed === "" ? undefined : f.listed,
        "filter[priced]": f.priced === "" ? undefined : f.priced,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function preview() {
    if (!schemeId.value) return;
    previewing.value = true;
    axios
        .post(route("crafter.connect.marketplace.ebay.listing.publish-preview"), {
            ids: [...selected].slice(0, props.maxBatch),
            scheme_id: schemeId.value,
        })
        .then((r) => { previewData.value = r.data; })
        .catch((e) => toast.error(e.response?.data?.error ?? "Nie udało się zbudować podglądu"))
        .finally(() => { previewing.value = false; });
}

function doPublish() {
    if (!schemeId.value) return;
    publishing.value = true;
    axios
        .post(route("crafter.connect.marketplace.ebay.listing.publish"), {
            ids: [...selected].slice(0, props.maxBatch),
            scheme_id: schemeId.value,
        })
        .then((r) => { previewData.value = null; result.value = r.data; })
        .catch((e) => toast.error(e.response?.data?.error ?? "Wystawianie nie powiodło się"))
        .finally(() => { publishing.value = false; });
}

function finish() {
    result.value = null;
    selected.clear();
    router.reload();
}
</script>
