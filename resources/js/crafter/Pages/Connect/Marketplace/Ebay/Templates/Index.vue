<!--
  Marketplace → eBay → Szablony.
  Szablony treści aukcji to WŁASNOŚĆ integracji eBay — osobne od szablonów sklepowych
  (admin/templates), żeby strojenie pod aukcje nie przestawiało Selly/Presty/OpenCarta.
  Jeden szablon = jeden rynek; od powielania jest „Kopiuj".
-->
<template>
    <PageHeader title="Marketplace — eBay · Szablony">
        <Button :leftIcon="PlusIcon" color="primary" @click="startNew">Nowy szablon</Button>
    </PageHeader>

    <PageContent fluid>
        <div class="mb-4 text-sm text-gray-500">
            Argo Connect → Marketplace → eBay → <span class="font-medium text-gray-700">Szablony</span>
        </div>

        <div class="mb-5 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
            Tytuł i opis aukcji powstają z tych szablonów. Każdy należy do <span class="font-medium">jednego rynku</span>,
            a <span class="font-medium">schemat</span> wskazuje, którego użyć.
            Treść składasz ze zmiennych produktu w składni Blade — <code>{{ exampleVariable }}</code>.
            To są szablony <span class="font-medium">wyłącznie eBaya</span>: zmiana tutaj nie rusza sklepów.
        </div>

        <!-- FORMULARZ -->
        <Card v-if="form" class="mb-6">
            <CardHeader>
                <h2 class="text-lg font-semibold">{{ form.id ? 'Edycja szablonu' : 'Nowy szablon' }}</h2>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Nazwa</label>
                        <input v-model="form.name" type="text" placeholder="Osłony — DE"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Rynek</label>
                        <select v-model="form.marketplace" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option v-for="m in marketplaces" :key="m" :value="m">{{ m }} → {{ marketplaceLocales[m] }}</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
                    <div class="lg:col-span-3">
                        <label class="mb-1 block text-xs font-medium text-gray-600">
                            Tytuł aukcji <span class="text-gray-400">(limit eBaya: 80 znaków po wyrenderowaniu)</span>
                        </label>
                        <textarea v-model="form.title" rows="2" ref="titleRef"
                                  class="w-full rounded-md border-gray-300 font-mono text-xs focus:border-primary-500 focus:ring-primary-500"
                                  :placeholder="exampleTitle" />

                        <label class="mb-1 mt-4 block text-xs font-medium text-gray-600">Opis aukcji (HTML + Blade)</label>
                        <textarea v-model="form.description" rows="16" ref="descRef"
                                  class="w-full rounded-md border-gray-300 font-mono text-xs focus:border-primary-500 focus:ring-primary-500" />
                    </div>

                    <!-- PALETA ZMIENNYCH -->
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Zmienne produktu</label>
                        <p class="mb-2 text-[11px] text-gray-400">Klik wstawia do opisu (albo do tytułu, jeśli w nim ostatnio pisałeś).</p>
                        <div class="max-h-[26rem] space-y-1 overflow-y-auto rounded-md border border-gray-200 p-2">
                            <button v-for="v in variables" :key="v.name" type="button"
                                    class="block w-full rounded px-2 py-1 text-left text-xs hover:bg-gray-100"
                                    @click="insertVariable(v.name)">
                                <span class="font-mono text-primary-700">${{ v.name }}</span>
                                <span class="block text-[10px] text-gray-500">{{ v.label }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex items-center gap-3">
                    <Button color="primary" :disabled="!form.name" @click="save">Zapisz</Button>
                    <Button variant="outline" color="gray" @click="form = null">Anuluj</Button>
                    <label class="ml-2 inline-flex cursor-pointer items-center gap-2">
                        <input v-model="form.enabled" type="checkbox" class="rounded text-primary-600 focus:ring-primary-500" />
                        <span class="text-sm" :class="form.enabled ? 'text-green-700' : 'text-gray-400'">
                            {{ form.enabled ? 'Włączony' : 'Wyłączony' }}
                        </span>
                    </label>
                </div>
            </CardContent>
        </Card>

        <!-- LISTA -->
        <Card v-if="!templates.length">
            <CardContent>
                <p class="py-6 text-center text-sm text-gray-500">Nie ma jeszcze żadnego szablonu eBay. Kliknij „Nowy szablon".</p>
            </CardContent>
        </Card>

        <Card v-for="t in templates" :key="t.id" class="mb-3">
            <CardContent>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ t.marketplace }}</span>
                            <h3 class="text-base font-semibold text-gray-900">{{ t.name }}</h3>
                            <span v-if="!t.enabled" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-500">wyłączony</span>
                            <span v-if="!t.has_title" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-800">bez tytułu</span>
                            <span v-if="!t.has_description" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-800">bez opisu</span>
                        </div>

                        <p class="mt-1 text-sm text-gray-600">
                            <template v-if="t.schemes.length">
                                Używany przez:
                                <span v-for="(s, i) in t.schemes" :key="s.id">
                                    <a :href="schemesUrl" class="text-primary-700 hover:underline">{{ s.name }}</a><span v-if="i < t.schemes.length - 1">, </span>
                                </span>
                            </template>
                            <span v-else class="text-gray-400">Żaden schemat go nie używa.</span>
                        </p>

                        <p v-if="audits[t.id]" class="mt-2 text-xs">
                            <span class="font-medium text-green-700">{{ audits[t.id].clean }}</span>
                            <span class="text-gray-500"> / {{ audits[t.id].checked }} produktów bez zastrzeżeń</span>
                            <span v-for="p in audits[t.id].problems" :key="p.label" class="ml-2 text-amber-700">
                                · {{ p.label }}: {{ p.count }}<span class="text-gray-400"> ({{ p.sample }})</span>
                            </span>
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <Button variant="outline" color="gray" size="sm" :loading="previewId === t.id" @click="openPreview(t)">Podgląd</Button>
                        <Button variant="outline" color="gray" size="sm" :loading="auditId === t.id" @click="runAudit(t)">
                            {{ audits[t.id] ? 'Przelicz' : 'Sprawdź katalog' }}
                        </Button>
                        <Button variant="outline" color="primary" size="sm" @click="startEdit(t)">Edytuj</Button>
                        <Button variant="outline" color="gray" size="sm" @click="duplicate(t)">Kopiuj</Button>
                        <Button variant="outline" color="red" size="sm" @click="destroy(t)">Usuń</Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- MODAL: PODGLĄD -->
        <div v-if="preview" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="preview = null">
            <div class="max-h-[88vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold">
                    Podgląd — {{ preview.template.name }}
                    <span class="text-sm font-normal text-gray-500">({{ preview.template.marketplace }})</span>
                </h2>

                <div class="mt-3 flex flex-wrap items-end gap-2">
                    <div class="min-w-[16rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Produkt (kod lub nazwa)</label>
                        <input v-model="productSearch" type="text" placeholder="np. 07.043"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                               @keyup.enter="reloadPreview" />
                    </div>
                    <Button size="sm" variant="outline" color="gray" :loading="previewLoading" @click="reloadPreview">Pokaż</Button>
                    <span class="text-xs text-gray-500">#{{ preview.product.id }} · {{ preview.product.product_code }}</span>
                </div>

                <p v-if="previewError" class="mt-2 text-sm text-red-600">{{ previewError }}</p>

                <div class="mt-4">
                    <div class="text-xs font-medium uppercase text-gray-500">Tytuł aukcji</div>
                    <div class="mt-1 rounded-lg border border-gray-200 p-3">
                        <div class="text-gray-900">{{ preview.title }}</div>
                        <div class="mt-1 text-xs" :class="preview.title_truncated ? 'text-red-600' : 'text-gray-400'">
                            {{ preview.title_length }} / {{ preview.title_max }} znaków
                            <span v-if="preview.title_truncated"> — przycięty z {{ preview.title_original_length }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-xs font-medium uppercase text-gray-500">
                            {{ showAuction ? 'Aukcja — tak zobaczy to kupujący' : `Sam opis (${preview.description.length} B HTML)` }}
                        </div>
                        <div v-if="preview.auction_html" class="flex gap-1">
                            <button type="button" class="rounded px-2 py-1 text-xs"
                                    :class="showAuction ? 'bg-primary-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                                    @click="showAuction = true">Podgląd aukcji</button>
                            <button type="button" class="rounded px-2 py-1 text-xs"
                                    :class="!showAuction ? 'bg-primary-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                                    @click="showAuction = false">Sam opis</button>
                        </div>
                    </div>

                    <p v-if="!preview.auction_html" class="mt-1 text-xs text-amber-700">
                        Brak skóry aukcji dla rynku {{ preview.template.marketplace }} — pokazuję sam opis.
                        Wgraj plik <code>resources/ebay/skins/{{ preview.template.marketplace }}.html</code>, żeby zobaczyć całą aukcję.
                    </p>

                    <!-- Skóra aukcji ma CSS ograniczony do `.bspx`, więc nie rozjeżdża panelu.
                         Treść jest nasza (szablon PIM + szablon BaseLinkera z repo), nie zewnętrzna. -->
                    <div v-if="showAuction && preview.auction_html"
                         class="mt-2 overflow-x-auto rounded-lg border border-gray-200 bg-white p-2"
                         v-html="preview.auction_html" />
                    <div v-else
                         class="prose prose-sm mt-2 max-w-none overflow-x-auto rounded-lg border border-gray-200 p-3"
                         v-html="preview.description" />
                </div>

                <div v-if="!showAuction" class="mt-4">
                    <div class="text-xs font-medium uppercase text-gray-500">Zdjęcia ({{ preview.images.length }})</div>
                    <div class="mt-1 flex flex-wrap gap-2">
                        <img v-for="(src, i) in preview.images.slice(0, 8)" :key="i" :src="src"
                             class="h-16 w-16 rounded border border-gray-200 object-contain" alt="" />
                        <span v-if="!preview.images.length" class="text-sm text-amber-700">Produkt nie ma zdjęć.</span>
                    </div>
                </div>

                <p v-if="preview.problems.length" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    {{ preview.problems.join(' · ') }}
                </p>

                <div class="mt-5"><Button color="primary" @click="preview = null">Zamknij</Button></div>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { reactive, ref } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import { PlusIcon } from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Card, CardHeader, CardContent } from "crafter/Components";

interface TemplateRow {
    id: number | null; name: string; marketplace: string; locale?: string;
    enabled: boolean; title: string | null; description: string | null;
    has_title?: boolean; has_description?: boolean;
    schemes?: Array<{ id: number; name: string; marketplace: string }>;
    audit?: any | null;
}
interface Props {
    templates: TemplateRow[];
    marketplaces: string[];
    marketplaceLocales: Record<string, string>;
    schemesUrl: string;
    variables: Array<{ name: string; label: string }>;
}

const props = defineProps<Props>();
const toast = useToast();

// Przykłady składni Blade trzymamy w zmiennych, a nie wprost w szablonie — Vue nie umie
// zagnieździć `{{ }}` w `{{ }}` i wywraca się na parsowaniu.
const exampleVariable = "{{ $name }}";
const exampleTitle = "{{ $name }} ({{ $attribute_year_start }}-{{ $attribute_year_stop }})";

const form = ref<TemplateRow | null>(null);
const titleRef = ref<HTMLTextAreaElement | null>(null);
const descRef = ref<HTMLTextAreaElement | null>(null);
const lastFocused = ref<"title" | "description">("description");

const preview = ref<any>(null);
const previewId = ref<number | null>(null);
const previewLoading = ref(false);
const previewError = ref<string | null>(null);
// Domyślnie pokazujemy złożoną aukcję — sam opis mówi mało o tym, jak to wyjdzie kupującemu.
const showAuction = ref(true);
const productSearch = ref("");
const auditId = ref<number | null>(null);

const audits = reactive<Record<number, any>>(
    Object.fromEntries(props.templates.filter((t) => t.audit).map((t) => [t.id as number, t.audit])),
);

function startNew() {
    form.value = {
        id: null, name: "", marketplace: props.marketplaces[0] ?? "EBAY_DE",
        title: "", description: "", enabled: true,
    };
}
function startEdit(t: TemplateRow) {
    form.value = { id: t.id, name: t.name, marketplace: t.marketplace, title: t.title, description: t.description, enabled: t.enabled };
}

/** Wstawia zmienną w miejsce kursora — pisanie `{{ $attribute_year_start }}` z palca jest karkołomne. */
function insertVariable(name: string) {
    if (!form.value) return;
    const snippet = `{{ $${name} }}`;
    const el = lastFocused.value === "title" ? titleRef.value : descRef.value;
    const field = lastFocused.value;

    if (!el) {
        form.value[field] = (form.value[field] ?? "") + snippet;
        return;
    }
    const start = el.selectionStart ?? el.value.length;
    const end = el.selectionEnd ?? start;
    const current = form.value[field] ?? "";
    form.value[field] = current.slice(0, start) + snippet + current.slice(end);

    // Kursor za wstawką, żeby dało się pisać dalej bez sięgania po mysz.
    requestAnimationFrame(() => {
        el.focus();
        el.selectionStart = el.selectionEnd = start + snippet.length;
    });
}

// Zapamiętujemy, w którym polu użytkownik ostatnio był — inaczej klik w paletę zawsze
// trafiałby w opis, nawet gdy właśnie poprawiał tytuł.
function trackFocus() {
    titleRef.value?.addEventListener("focus", () => (lastFocused.value = "title"));
    descRef.value?.addEventListener("focus", () => (lastFocused.value = "description"));
}
setTimeout(trackFocus, 0);

function save() {
    if (!form.value) return;
    const payload = {
        name: form.value.name,
        marketplace: form.value.marketplace,
        title: form.value.title,
        description: form.value.description,
        enabled: form.value.enabled,
    };
    const opts = {
        preserveScroll: true,
        onSuccess: () => { form.value = null; },
        onError: () => toast.error("Nie udało się zapisać"),
    };

    form.value.id
        ? router.put(route("crafter.connect.marketplace.ebay.templates.update", form.value.id), payload, opts)
        : router.post(route("crafter.connect.marketplace.ebay.templates.store"), payload, opts);
}

function duplicate(t: TemplateRow) {
    router.post(route("crafter.connect.marketplace.ebay.templates.duplicate", t.id), {}, { preserveScroll: true });
}

function destroy(t: TemplateRow) {
    if (!window.confirm(`Usunąć szablon „${t.name}"?`)) return;
    router.delete(route("crafter.connect.marketplace.ebay.templates.destroy", t.id), { preserveScroll: true });
}

function fetchPreview(templateId: number, loader: (v: boolean) => void) {
    loader(true);
    previewError.value = null;
    return axios
        .post(route("crafter.connect.marketplace.ebay.templates.preview"), {
            template_id: templateId,
            search: productSearch.value || undefined,
        })
        .then((r) => { preview.value = r.data; })
        .catch((e) => {
            const msg = e.response?.data?.error ?? "Nie udało się wyrenderować podglądu";
            preview.value ? (previewError.value = msg) : toast.error(msg);
        })
        .finally(() => loader(false));
}

function openPreview(t: TemplateRow) {
    productSearch.value = "";
    fetchPreview(t.id as number, (v) => { previewId.value = v ? (t.id as number) : null; });
}

function reloadPreview() {
    if (!preview.value) return;
    fetchPreview(preview.value.template.id, (v) => { previewLoading.value = v; });
}

function runAudit(t: TemplateRow) {
    auditId.value = t.id as number;
    axios
        .post(route("crafter.connect.marketplace.ebay.templates.audit"), { template_id: t.id })
        .then((r) => {
            audits[t.id as number] = r.data;
            toast.success(`${t.name}: ${r.data.clean} z ${r.data.checked} produktów bez zastrzeżeń`);
        })
        .catch(() => toast.error("Audyt się nie powiódł"))
        .finally(() => { auditId.value = null; });
}
</script>
