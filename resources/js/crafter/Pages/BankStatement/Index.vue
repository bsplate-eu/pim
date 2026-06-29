<template>
    <PageHeader title="Wyciągi z konta">
        <Button :leftIcon="PlusIcon" @click.prevent="openAdd">
            Dodaj miesiąc
        </Button>
    </PageHeader>

    <PageContent>
        <div v-if="months.length === 0" class="text-sm text-gray-500">
            Brak wyciągów. Kliknij „Dodaj miesiąc", aby zaimportować plik CSV.
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <Link
                v-for="m in months"
                :key="m.id"
                :href="route('crafter.bank-statements.show', m.id)"
                class="block"
            >
                <Card class="hover:shadow-md transition">
                    <CardContent>
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs uppercase tracking-wide text-gray-500">{{ m.bank }}</span>
                                <h3 class="text-base font-semibold text-gray-800">{{ m.label }}</h3>
                                <p class="text-xs text-gray-500 mt-1">Pozycji: {{ m.items_count }}</p>
                            </div>
                            <div class="text-right">
                                <span
                                    class="inline-block text-xs font-semibold px-2 py-0.5 rounded"
                                    :class="m.matched_count === m.items_count ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'"
                                >
                                    {{ m.matched_count }} / {{ m.items_count }} rozliczone
                                </span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </Link>
        </div>
    </PageContent>

    <Modal :open="addOpen" externalOpen @toggleOpen="addOpen = false">
        <template #title>Dodaj wyciąg</template>
        <template #content>
            <div class="space-y-4 text-left">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Bank</label>
                    <select v-model="form.bank" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
                        <option v-for="b in banks" :key="b" :value="b">{{ b.toUpperCase() }}</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Rok</label>
                        <select v-model.number="form.year" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
                            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Miesiąc</label>
                        <select v-model.number="form.month" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
                            <option v-for="(n, idx) in monthNames" :key="idx" :value="idx + 1">{{ n }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Plik CSV</label>
                    <input
                        type="file"
                        accept=".csv,.txt"
                        @change="onFile"
                        class="block w-full text-sm"
                    />
                    <p class="text-xs text-gray-400 mt-1">Format eksportu z {{ form.bank.toUpperCase() }}.</p>
                </div>
            </div>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button :loading="form.processing" :leftIcon="PlusIcon" @click.prevent="submit">
                Importuj
            </Button>
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); addOpen = false; }">
                Anuluj
            </Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@brackets/vue-toastification';
import {
    PageHeader, PageContent,
    Card, CardContent,
    Button, Modal,
} from 'crafter/Components';

interface MonthItem {
    id: number;
    bank: string;
    label: string;
    items_count: number;
    matched_count: number;
}

const props = defineProps<{ months: MonthItem[]; banks: string[] }>();

const toast = useToast();
const addOpen = ref(false);

const now = new Date();

const form = useForm<{ bank: string; year: number; month: number; file: File | null }>({
    bank: props.banks[0] ?? 'santander',
    year: now.getFullYear(),
    month: now.getMonth() + 1,
    file: null,
});

const monthNames = [
    'Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
    'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień',
];

const yearOptions = computed(() => {
    const y = now.getFullYear();
    return [y - 1, y, y + 1];
});

function openAdd() {
    form.reset();
    form.bank = props.banks[0] ?? 'santander';
    form.year = now.getFullYear();
    form.month = now.getMonth() + 1;
    addOpen.value = true;
}

function onFile(e: Event) {
    const target = e.target as HTMLInputElement;
    form.file = target.files?.[0] ?? null;
}

function submit() {
    if (!form.file) {
        toast.error('Wybierz plik CSV.');
        return;
    }
    form.post(route('crafter.bank-statements.store'), {
        forceFormData: true,
        onSuccess: () => {
            toast.success('Wyciąg zaimportowany.');
            addOpen.value = false;
        },
        onError: (errs) => {
            toast.error(Object.values(errs)[0] as string);
        },
    });
}
</script>
