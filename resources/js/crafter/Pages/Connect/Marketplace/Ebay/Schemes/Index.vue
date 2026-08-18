<!--
  Marketplace → eBay → Schematy.
  Wzorzec: Connect/Marketplace/Allegro/Schemes/Index.vue z OMS ARGO — lista z edycją w miejscu,
  bez osobnej podstrony. Schemat spina kategorię (etap A), szablon treści (etap B) i cennik.
-->
<template>
    <PageHeader title="Marketplace — eBay · Schematy">
        <Button :leftIcon="PlusIcon" color="primary" @click="startNew">Nowy schemat</Button>
    </PageHeader>

    <PageContent fluid>
        <div class="mb-4 text-sm text-gray-500">
            Argo Connect → Marketplace → eBay → <span class="font-medium text-gray-700">Schematy</span>
        </div>

        <div class="mb-5 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
            Schemat to przepis wystawiania: <span class="font-medium">rynek + kategoria + szablon treści + cennik</span>.
            Przy wystawianiu zaznaczasz produkty i wybierasz schemat — resztę składa automat.
            Nowe oferty domyślnie powstają jako <span class="font-medium">szkice</span>.
        </div>

        <!-- FORMULARZ (nowy / edycja) -->
        <Card v-if="form" class="mb-6">
            <CardHeader>
                <h2 class="text-lg font-semibold">{{ form.id ? 'Edycja schematu' : 'Nowy schemat' }}</h2>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Nazwa</label>
                        <input v-model="form.name" type="text" placeholder="Osłony silnika DE" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Rynek</label>
                        <select v-model="form.marketplace" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option v-for="m in marketplaces" :key="m" :value="m">{{ m }} → {{ marketplaceLocales[m] }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Kategoria eBay</label>
                        <select v-model="form.ebay_category_id" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option :value="null">— wybierz —</option>
                            <option v-for="c in categoriesForMarket" :key="c.id" :value="c.id">
                                {{ c.label }}{{ c.ready ? '' : ' ⚠ niezmapowana' }}
                            </option>
                        </select>
                        <p v-if="form.marketplace && !categoriesForMarket.length" class="mt-1 text-xs text-amber-700">
                            Brak nauczonych kategorii dla {{ form.marketplace }} —
                            <Link :href="route('crafter.connect.marketplace.ebay.categories.index')" class="underline">naucz ją najpierw</Link>.
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Szablon treści</label>
                        <select v-model="form.template_id" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option :value="null">— wybierz —</option>
                            <option v-for="t in templates" :key="t.id" :value="t.id">
                                {{ t.label }}{{ t.locale === expectedLocale ? '' : ' ⚠ inne locale' }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400">Rynek {{ form.marketplace }} oczekuje locale <span class="font-medium">{{ expectedLocale }}</span>.</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Cennik PIM (netto)</label>
                        <select v-model="form.pricelist_id" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option :value="null">— wybierz —</option>
                            <option v-for="p in pricelists" :key="p.id" :value="p.id">{{ p.name }} ({{ p.currency }})</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Mnożnik ceny</label>
                        <input v-model.number="form.price_multiplier" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">VAT %</label>
                        <input v-model.number="form.tax_percent" type="number" step="0.01" min="0" max="100" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Stan startowy</label>
                        <input v-model.number="form.default_stock" type="number" min="0" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Publikacja</label>
                        <select v-model="form.publication_mode" class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                            <option value="draft">Szkic (bezpiecznie)</option>
                            <option value="active">Od razu aktywna</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex cursor-pointer items-center gap-2">
                            <input v-model="form.enabled" type="checkbox" class="rounded text-primary-600 focus:ring-primary-500" />
                            <span class="text-sm" :class="form.enabled ? 'text-green-700' : 'text-gray-400'">
                                {{ form.enabled ? 'Włączony' : 'Wyłączony' }}
                            </span>
                        </label>
                    </div>
                </div>

                <div v-if="form.publication_mode === 'active'" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    Ten schemat wystawi oferty <span class="font-medium">od razu aktywne</span> — trafią do sprzedaży bez ręcznej akceptacji.
                </div>

                <div class="mt-5 flex items-center gap-3">
                    <Button color="primary" :disabled="!form.name || !form.marketplace" @click="save">Zapisz</Button>
                    <Button variant="outline" color="gray" @click="form = null">Anuluj</Button>
                </div>
            </CardContent>
        </Card>

        <!-- LISTA -->
        <Card v-if="!schemes.length">
            <CardContent>
                <p class="py-6 text-center text-sm text-gray-500">Nie ma jeszcze żadnego schematu. Kliknij „Nowy schemat".</p>
            </CardContent>
        </Card>

        <Card v-for="s in schemes" :key="s.id" class="mb-3">
            <CardContent>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ s.marketplace }}</span>
                            <h3 class="text-base font-semibold text-gray-900">{{ s.name }}</h3>
                            <span v-if="!s.enabled" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-500">wyłączony</span>
                            <span v-if="s.publication_mode === 'active'" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-800">od razu aktywne</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ s.category_label ?? '— brak kategorii —' }} ·
                            {{ s.template_label ?? '— brak szablonu —' }} ·
                            cena × {{ s.price_multiplier }} + {{ s.tax_percent }}% VAT · stan {{ s.default_stock }}
                        </p>
                        <p class="mt-1 text-xs" :class="s.problems.length ? 'text-amber-700' : 'text-green-700'">
                            {{ s.problems.length ? `Do uzupełnienia: ${s.problems.join(' · ')}` : 'Gotowy do wystawiania' }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button variant="outline" color="gray" size="sm" @click="startEdit(s)">Edytuj</Button>
                        <Button variant="outline" color="red" size="sm" @click="destroy(s)">Usuń</Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { PlusIcon } from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Card, CardHeader, CardContent } from "crafter/Components";

interface Scheme {
    id: number | null;
    name: string;
    marketplace: string;
    locale?: string;
    ebay_category_id: number | null;
    category_label?: string | null;
    template_id: number | null;
    template_label?: string | null;
    pricelist_id: number | null;
    price_multiplier: number;
    tax_percent: number;
    default_stock: number;
    publication_mode: string;
    enabled: boolean;
    problems: string[];
}
interface Props {
    schemes: Scheme[];
    categories: Array<{ id: number; marketplace: string; label: string; ready: boolean }>;
    templates: Array<{ id: number; label: string; locale: string }>;
    pricelists: Array<{ id: number; name: string; currency: string }>;
    marketplaces: string[];
    marketplaceLocales: Record<string, string>;
}

const props = defineProps<Props>();
const toast = useToast();
const form = ref<Scheme | null>(null);

// VAT domyślny per rynek — inaczej każdy nowy schemat trzeba by poprawiać ręcznie.
const DEFAULT_VAT: Record<string, number> = {
    EBAY_DE: 19, EBAY_AT: 20, EBAY_FR: 20, EBAY_ES: 21, EBAY_IT: 22, EBAY_PL: 23, EBAY_GB: 20,
};

const expectedLocale = computed(() => props.marketplaceLocales[form.value?.marketplace ?? ""] ?? "en");

// Kategoria z innego rynku dałaby ofertę odrzuconą przez eBay — nie pokazujemy jej w ogóle.
const categoriesForMarket = computed(() =>
    props.categories.filter((c) => c.marketplace === form.value?.marketplace),
);

function startNew() {
    form.value = {
        id: null, name: "", marketplace: props.marketplaces[0] ?? "EBAY_DE",
        ebay_category_id: null, template_id: null, pricelist_id: null,
        price_multiplier: 1, tax_percent: DEFAULT_VAT[props.marketplaces[0] ?? "EBAY_DE"] ?? 19,
        default_stock: 5, publication_mode: "draft", enabled: true, problems: [],
    };
}

function startEdit(s: Scheme) {
    form.value = JSON.parse(JSON.stringify(s));
}

function save() {
    if (!form.value) return;
    const payload = {
        name: form.value.name,
        marketplace: form.value.marketplace,
        ebay_category_id: form.value.ebay_category_id,
        template_id: form.value.template_id,
        pricelist_id: form.value.pricelist_id,
        price_multiplier: form.value.price_multiplier,
        tax_percent: form.value.tax_percent,
        default_stock: form.value.default_stock,
        publication_mode: form.value.publication_mode,
        enabled: form.value.enabled,
    };
    const opts = {
        preserveScroll: true,
        onSuccess: () => { form.value = null; },
        onError: () => toast.error("Nie udało się zapisać"),
    };

    form.value.id
        ? router.put(route("crafter.connect.marketplace.ebay.schemes.update", form.value.id), payload, opts)
        : router.post(route("crafter.connect.marketplace.ebay.schemes.store"), payload, opts);
}

function destroy(s: Scheme) {
    if (!window.confirm(`Usunąć schemat „${s.name}"?`)) return;
    router.delete(route("crafter.connect.marketplace.ebay.schemes.destroy", s.id), { preserveScroll: true });
}
</script>
