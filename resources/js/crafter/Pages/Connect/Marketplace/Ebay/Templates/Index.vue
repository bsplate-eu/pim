<!--
  Marketplace → eBay → Szablony.
  NIE duplikuje edytora (ten jest w admin/templates) — pokazuje to, czego z edytora nie widać:
  który schemat eBay używa którego szablonu, jak opis wychodzi na konkretnym produkcie
  i ile pozycji katalogu ten szablon psuje.
-->
<template>
    <PageHeader title="Marketplace — eBay · Szablony" />

    <PageContent fluid>
        <div class="mb-4 text-sm text-gray-500">
            Argo Connect → Marketplace → eBay → <span class="font-medium text-gray-700">Szablony</span>
        </div>

        <div class="mb-5 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
            Tytuł i opis aukcji powstają z <span class="font-medium">szablonów PIM</span> — tych samych,
            które zasilają Selly, PrestaShop i OpenCart. Dzięki temu aukcja mówi to samo co sklep,
            a każdy rynek bierze szablon w swoim języku.
            <span class="font-medium">Schemat</span> wskazuje, którego użyć.
        </div>

        <Card v-for="t in templates" :key="t.id" class="mb-3">
            <CardContent>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-semibold text-gray-900">{{ t.slug }}</h3>
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ t.locale }}</span>
                            <span v-if="!t.has_description" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-800">
                                bez opisu
                            </span>
                        </div>

                        <p class="mt-1 text-sm text-gray-600">
                            <template v-if="t.schemes.length">
                                Używany przez:
                                <span v-for="(s, i) in t.schemes" :key="s.id">
                                    <a :href="schemesUrl" class="text-primary-700 hover:underline">{{ s.name }}</a>
                                    <span class="text-gray-400"> ({{ s.marketplace }})</span><span v-if="i < t.schemes.length - 1">, </span>
                                </span>
                            </template>
                            <span v-else class="text-gray-400">Żaden schemat eBay go nie używa.</span>
                        </p>

                        <!-- Audyt: policzony na żądanie, bo to render Blade × cały katalog. -->
                        <p v-if="audits[t.id]" class="mt-2 text-xs">
                            <span class="font-medium text-green-700">{{ audits[t.id].clean }}</span>
                            <span class="text-gray-500"> / {{ audits[t.id].checked }} produktów bez zastrzeżeń</span>
                            <span v-for="p in audits[t.id].problems" :key="p.label" class="ml-2 text-amber-700">
                                · {{ p.label }}: {{ p.count }}<span class="text-gray-400"> ({{ p.sample }})</span>
                            </span>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <Button variant="outline" color="gray" size="sm" :loading="previewId === t.id" @click="openPreview(t)">
                            Podgląd
                        </Button>
                        <Button variant="outline" color="gray" size="sm" :loading="auditId === t.id" @click="runAudit(t)">
                            {{ audits[t.id] ? 'Przelicz' : 'Sprawdź katalog' }}
                        </Button>
                        <a :href="t.edit_url"><Button variant="outline" color="primary" size="sm">Edytuj</Button></a>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- MODAL: PODGLĄD -->
        <div v-if="preview" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="preview = null">
            <div class="max-h-[88vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold">
                    Podgląd — {{ preview.template.slug }} <span class="text-sm font-normal text-gray-500">({{ preview.template.locale }})</span>
                </h2>

                <div class="mt-3 flex flex-wrap items-end gap-2">
                    <div class="min-w-[16rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Produkt (kod lub nazwa)</label>
                        <input v-model="productSearch" type="text" placeholder="np. 07.043"
                               class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                               @keyup.enter="reloadPreview" />
                    </div>
                    <Button size="sm" variant="outline" color="gray" :loading="previewLoading" @click="reloadPreview">Pokaż</Button>
                    <span class="text-xs text-gray-500">
                        #{{ preview.product.id }} · {{ preview.product.product_code }} · {{ preview.product.name }}
                    </span>
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
                    <div class="text-xs font-medium uppercase text-gray-500">Opis ({{ preview.description.length }} B HTML)</div>
                    <!-- Treść pochodzi z naszego szablonu i przeszła białą listę tagów w rendererze. -->
                    <div class="prose prose-sm mt-1 max-w-none overflow-x-auto rounded-lg border border-gray-200 p-3" v-html="preview.description" />
                </div>

                <div class="mt-4">
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
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Card, CardContent } from "crafter/Components";

interface TemplateRow {
    id: number; slug: string; locale: string;
    has_title: boolean; has_description: boolean;
    schemes: Array<{ id: number; name: string; marketplace: string }>;
    edit_url: string;
    audit: any | null;
}
interface Props { templates: TemplateRow[]; schemesUrl: string }

const props = defineProps<Props>();
const toast = useToast();

const preview = ref<any>(null);
const previewId = ref<number | null>(null);
const previewLoading = ref(false);
const previewError = ref<string | null>(null);
const productSearch = ref("");
const auditId = ref<number | null>(null);

// Audyty policzone wcześniej przychodzą z backendu (cache godzinny) — przepisujemy je do
// reaktywnej mapy, żeby świeży wynik nadpisywał stary bez przeładowania strony.
const audits = reactive<Record<number, any>>(
    Object.fromEntries(props.templates.filter((t) => t.audit).map((t) => [t.id, t.audit])),
);

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
    fetchPreview(t.id, (v) => { previewId.value = v ? t.id : null; });
}

function reloadPreview() {
    if (!preview.value) return;
    const id = props.templates.find((t) => t.slug === preview.value.template.slug)?.id;
    if (id) fetchPreview(id, (v) => { previewLoading.value = v; });
}

function runAudit(t: TemplateRow) {
    auditId.value = t.id;
    axios
        .post(route("crafter.connect.marketplace.ebay.templates.audit"), { template_id: t.id })
        .then((r) => {
            audits[t.id] = r.data;
            toast.success(`${t.slug}: ${r.data.clean} z ${r.data.checked} produktów bez zastrzeżeń`);
        })
        .catch(() => toast.error("Audyt się nie powiódł"))
        .finally(() => { auditId.value = null; });
}
</script>
