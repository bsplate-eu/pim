<template>
    <PageHeader title="Planer kosztów">
        <Button :leftIcon="PlusIcon" @click.prevent="openAdd">
            Dodaj miesiąc
        </Button>
    </PageHeader>

    <PageContent>
        <div v-if="months.length === 0" class="text-sm text-gray-500">
            Brak miesięcy. Kliknij „Dodaj miesiąc”, aby rozpocząć.
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <Link
                v-for="m in months"
                :key="m.id"
                :href="route('crafter.cost-planner.show', m.id)"
                class="block"
            >
                <Card class="hover:shadow-md transition">
                    <CardContent>
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-base font-semibold text-gray-800">{{ m.label }}</h3>
                                <p class="text-xs text-gray-500 mt-1">Pozycji: {{ m.items_count }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-gray-500">Suma</p>
                                <p class="text-sm font-semibold text-gray-800">
                                    {{ formatAmount(m.total_amount) }}
                                </p>
                                <p v-if="Number(m.total_unpaid) > 0" class="text-xs text-red-600 mt-1">
                                    Do zapłaty: {{ formatAmount(m.total_unpaid) }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </Link>
        </div>
    </PageContent>

    <Modal :open="addOpen" externalOpen @toggleOpen="addOpen = false">
        <template #title>Dodaj miesiąc</template>
        <template #content>
            <div class="space-y-4 text-left">
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
                            <option v-for="(name, idx) in monthNames" :key="idx" :value="idx + 1">
                                {{ name }}
                            </option>
                        </select>
                    </div>
                </div>

                <div v-if="months.length > 0">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" v-model="cloneChecked" />
                        Skopiuj pozycje z poprzedniego miesiąca
                    </label>
                    <select
                        v-if="cloneChecked"
                        v-model.number="form.clone_from_id"
                        class="mt-2 w-full border border-gray-300 rounded px-2 py-1.5 text-sm"
                    >
                        <option :value="null">— wybierz miesiąc —</option>
                        <option v-for="m in months" :key="m.id" :value="m.id">{{ m.label }}</option>
                    </select>
                </div>
            </div>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button :loading="form.processing" :leftIcon="PlusIcon" @click.prevent="submit">
                Utwórz
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
    label: string;
    year: number;
    month: number;
    items_count: number;
    total_amount: number | string | null;
    total_unpaid: number | string | null;
}

const props = defineProps<{ months: MonthItem[] }>();

const toast = useToast();
const addOpen = ref(false);
const cloneChecked = ref(false);

const now = new Date();
const form = useForm<{ year: number; month: number; clone_from_id: number | null; notes: string | null }>({
    year: now.getFullYear(),
    month: now.getMonth() + 1,
    clone_from_id: null,
    notes: null,
});

const monthNames = [
    'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
    'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień',
];

const yearOptions = computed(() => {
    const y = now.getFullYear();
    return [y - 1, y, y + 1, y + 2];
});

const openAdd = () => {
    form.reset();
    form.year = now.getFullYear();
    form.month = now.getMonth() + 1;
    cloneChecked.value = false;
    addOpen.value = true;
};

const submit = () => {
    if (!cloneChecked.value) form.clone_from_id = null;
    form.post(route('crafter.cost-planner.store'), {
        onSuccess: () => {
            toast.success('Miesiąc utworzony.');
            addOpen.value = false;
        },
        onError: (errs) => {
            toast.error(Object.values(errs)[0] as string);
        },
    });
};

const formatAmount = (v: number | string | null): string => {
    const n = Number(v ?? 0);
    return new Intl.NumberFormat('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) + ' zł';
};
</script>
