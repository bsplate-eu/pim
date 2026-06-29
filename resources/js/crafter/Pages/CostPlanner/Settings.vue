<template>
    <PageHeader title="Ustawienia planera kosztów">
        <Button :leftIcon="CheckIcon" :loading="form.processing" @click.prevent="save">
            Zapisz zmiany
        </Button>
        <Button color="gray" variant="outline" @click.prevent="back">
            ← Wróć
        </Button>
    </PageHeader>

    <PageContent>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

            <!-- 1. Nazwy kosztów -->
            <Card>
                <CardHeader>
                    <h2 class="text-base font-semibold text-gray-700">Koszty — podpowiedzi nazw</h2>
                    <p class="text-xs text-gray-500">Lista sugerowanych nazw w rozwijanym polu „Koszty". W tabeli zawsze można wpisać dowolny tekst.</p>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <div
                            v-for="(name, idx) in form.cost_names"
                            :key="idx"
                            class="flex gap-2 items-center"
                        >
                            <input
                                v-model="form.cost_names[idx]"
                                class="flex-1 border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400"
                                placeholder="np. Krystian faktura"
                            />
                            <button @click="form.cost_names.splice(idx, 1)" class="text-red-500 hover:text-red-700 p-1">
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </div>
                        <Button color="gray" variant="outline" :leftIcon="PlusIcon" @click.prevent="form.cost_names.push('')">
                            Dodaj nazwę
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- 2. Statusy -->
            <NamedColorEditor
                title="Status"
                description="Statusy płatności (np. Zapłacone, Do zapłaty)."
                v-model="form.statuses"
                :allowed-colors="allowedColors"
            />

            <!-- 3. Rodzaj -->
            <NamedColorEditor
                title="Rodzaj"
                description="Kategorie kosztów (np. Wynagrodzenia, Operacyjne, Software)."
                v-model="form.categories"
                :allowed-colors="allowedColors"
            />

            <!-- 4. Typ -->
            <NamedColorEditor
                title="Typ"
                description="Typ kosztu (np. Stałe, Zmienne)."
                v-model="form.types"
                :allowed-colors="allowedColors"
            />

            <!-- 5. Waluty -->
            <Card>
                <CardHeader>
                    <h2 class="text-base font-semibold text-gray-700">Waluty</h2>
                    <p class="text-xs text-gray-500">Kody 3-literowe (ISO 4217).</p>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <div
                            v-for="(code, idx) in form.currencies"
                            :key="idx"
                            class="flex gap-2 items-center"
                        >
                            <input
                                v-model="form.currencies[idx]"
                                maxlength="3"
                                class="w-24 border border-gray-300 rounded px-2 py-1.5 text-sm uppercase focus:outline-none focus:ring-1 focus:ring-blue-400"
                                placeholder="PLN"
                                @input="form.currencies[idx] = form.currencies[idx].toUpperCase()"
                            />
                            <button @click="form.currencies.splice(idx, 1)" class="text-red-500 hover:text-red-700 p-1">
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </div>
                        <Button color="gray" variant="outline" :leftIcon="PlusIcon" @click.prevent="form.currencies.push('')">
                            Dodaj walutę
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { PlusIcon, TrashIcon, CheckIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@brackets/vue-toastification';
import {
    PageHeader, PageContent,
    Card, CardHeader, CardContent,
    Button,
} from 'crafter/Components';
import NamedColorEditor from './NamedColorEditor.vue';

interface NamedColor { name: string; color: string; }

interface Settings {
    cost_names: string[];
    statuses: NamedColor[];
    categories: NamedColor[];
    types: NamedColor[];
    currencies: string[];
}

const props = defineProps<{
    settings: Settings;
    allowedColors: string[];
}>();

const toast = useToast();

const form = useForm<Settings>({
    cost_names: [...props.settings.cost_names],
    statuses: props.settings.statuses.map(s => ({ ...s })),
    categories: props.settings.categories.map(c => ({ ...c })),
    types: props.settings.types.map(t => ({ ...t })),
    currencies: [...props.settings.currencies],
});

function save() {
    // Czyszczenie pustych wartości.
    form.cost_names = form.cost_names.map(n => n.trim()).filter(Boolean);
    form.currencies = form.currencies.map(c => c.trim().toUpperCase()).filter(c => c.length === 3);

    form.put(route('crafter.cost-planner.settings.update'), {
        preserveScroll: true,
        onSuccess: () => toast.success('Ustawienia zapisane.'),
        onError: (errs) => toast.error(Object.values(errs)[0] as string),
    });
}

function back() {
    router.visit(route('crafter.cost-planner.index'));
}
</script>
