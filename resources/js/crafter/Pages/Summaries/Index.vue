<template>
    <PageHeader title="Zestawienia">
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
                :href="route('crafter.cost-planner.summaries.show', m.id)"
                class="block"
            >
                <Card class="hover:shadow-md transition">
                    <CardContent>
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-base font-semibold text-gray-800">{{ m.label }}</h3>
                                <p class="text-xs text-gray-500 mt-1">Pozycji: {{ m.positions_count }}</p>
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
                <p class="text-xs text-gray-500">
                    Pozycje (FV i korekty) zaciągają się automatycznie na podstawie miesiąca z numeru faktury.
                </p>
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
    positions_count: number;
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
    'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
    'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień',
];

const yearOptions = computed(() => {
    const y = now.getFullYear();
    return [y - 2, y - 1, y, y + 1];
});

const openAdd = () => {
    form.reset();
    form.year = now.getFullYear();
    form.month = now.getMonth() + 1;
    addOpen.value = true;
};

const submit = () => {
    form.post(route('crafter.cost-planner.summaries.store'), {
        onSuccess: () => {
            toast.success('Miesiąc utworzony.');
            addOpen.value = false;
        },
        onError: (errs) => {
            toast.error(Object.values(errs)[0] as string);
        },
    });
};
</script>
