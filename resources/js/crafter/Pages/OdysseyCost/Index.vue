<template>
    <PageHeader title="Koszty Odyssey">
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
                :href="route('crafter.odyssey-cost.show', m.id)"
                class="block"
            >
                <Card class="hover:shadow-md transition">
                    <CardContent>
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-base font-semibold text-gray-800">{{ m.label }}</h3>
                                <p class="text-xs text-gray-500 mt-1">Zamówień: {{ m.entries_count }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-gray-500">Suma</p>
                                <p class="text-sm font-semibold text-gray-800">{{ fmt(m.total_sum) }}</p>
                                <p v-if="Number(m.balance_due) > 0" class="text-xs text-red-600 mt-1">
                                    Do pokrycia: {{ fmt(m.balance_due) }}
                                </p>
                                <p v-else-if="Number(m.total_paid) > 0" class="text-xs text-green-600 mt-1">
                                    Rozliczone
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
            </div>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button :loading="form.processing" :leftIcon="PlusIcon" @click.prevent="submit">Utwórz</Button>
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); addOpen = false; }">Anuluj</Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { PlusIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@brackets/vue-toastification';
import { PageHeader, PageContent, Card, CardContent, Button, Modal } from 'crafter/Components';

interface MonthItem {
    id: number;
    label: string;
    year: number;
    month: number;
    entries_count: number;
    total_goods: number | string;
    total_shipping: number | string;
    total_sum: number | string;
    total_paid: number | string;
    balance_due: number | string;
}

defineProps<{ months: MonthItem[] }>();

const toast = useToast();
const addOpen = ref(false);
const now = new Date();

const form = useForm<{ year: number; month: number }>({
    year: now.getFullYear(),
    month: now.getMonth() + 1,
});

const monthNames = [
    'Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
    'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień',
];

const yearOptions = computed(() => {
    const y = now.getFullYear();
    return [y - 1, y, y + 1, y + 2];
});

const openAdd = () => {
    form.reset();
    form.year = now.getFullYear();
    form.month = now.getMonth() + 1;
    addOpen.value = true;
};

const submit = () => {
    form.post(route('crafter.odyssey-cost.store'), {
        onSuccess: () => { toast.success('Miesiąc utworzony.'); addOpen.value = false; },
        onError: (errs) => { toast.error(Object.values(errs)[0] as string); },
    });
};

const fmt = (v: number | string | null): string => {
    const n = Number(v ?? 0);
    return new Intl.NumberFormat('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) + ' zł';
};
</script>
