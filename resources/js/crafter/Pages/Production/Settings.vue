<template>
    <PageHeader sticky title="Produkcja — Ustawienia" />

    <PageContent>
        <div class="w-full">
            <!-- Jedna zakladka na razie; pasek zostaje, bo dojda kolejne ustawienia. -->
            <div class="mb-4 border-b border-gray-200">
                <nav class="-mb-px flex gap-6">
                    <button type="button" :class="tabClass(tab === 'stages')" @click="tab = 'stages'">
                        Etapy
                    </button>
                    <button type="button" :class="tabClass(tab === 'groups')" @click="tab = 'groups'">
                        Grupowanie
                        <span class="ml-1 text-xs text-gray-400">{{ groups.proposed.length }}</span>
                    </button>
                    <button type="button" :class="tabClass(tab === 'exclusions')" @click="tab = 'exclusions'">
                        Wykluczenia
                        <span class="ml-1 text-xs text-gray-400">{{ exclusions.excluded_count }}</span>
                    </button>
                </nav>
            </div>

            <Card v-if="tab === 'groups'">
                <GroupsTab :groups="groups" />
            </Card>

            <Card v-else-if="tab === 'exclusions'">
                <ExclusionsTab :exclusions="exclusions" />
            </Card>

            <Card v-else>
                <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-medium text-gray-900">Etapy produkcji</h3>
                        <p class="mt-1 max-w-2xl text-sm text-gray-500">
                            Etap przypisuje się <strong>automatycznie</strong> na podstawie
                            sprzedaży 12 mc. Kod trafia do pierwszego etapu z listy, w którego
                            przedział wpada — dlatego kolejność ma znaczenie przy nachodzących
                            zakresach. Etap bez przedziału istnieje, ale nic sam nie łapie.
                        </p>
                    </div>
                    <Button :leftIcon="ArrowPathIcon" :loading="recalculating" @click="recalculate">
                        Przelicz etapy
                    </Button>
                </div>

                <!-- Rozklad sprzedazy — zeby progi nie byly ustawiane w ciemno. -->
                <div class="mb-6 rounded-md border border-gray-200 bg-gray-50 p-4">
                    <div class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-500">
                        Rozkład sprzedaży 12 mc — ile kodów wpada w który przedział
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <div
                            v-for="bucket in histogram"
                            :key="bucket.label"
                            class="min-w-[92px] flex-1 rounded border border-gray-200 bg-white px-3 py-2"
                        >
                            <div class="text-xs text-gray-500">{{ bucket.label }} szt.</div>
                            <div class="text-lg font-semibold text-gray-900">{{ bucket.count }}</div>
                            <div class="text-xs text-gray-400">{{ bucket.percent }}%</div>
                        </div>
                    </div>
                </div>

                <div v-if="recalcResult" class="mb-6 rounded-md border border-green-200 bg-green-50 p-4 text-sm">
                    <div class="font-medium text-green-900">
                        Przeliczono: {{ recalcResult.przypisanych }} kodów dostało etap,
                        {{ recalcResult.bez_etapu }} bez etapu
                        <span class="text-green-700">({{ recalcResult.zmienionych }} zmian)</span>
                    </div>
                    <div v-if="Object.keys(recalcResult.per_etap ?? {}).length" class="mt-1 text-green-800">
                        <span v-for="(count, name) in recalcResult.per_etap" :key="name" class="mr-4">
                            {{ name }}: <strong>{{ count }}</strong>
                        </span>
                    </div>
                    <div v-if="recalcResult.bez_zakresu?.length" class="mt-1 text-xs text-green-700">
                        Bez przedziału (pominięte przez automat):
                        {{ recalcResult.bez_zakresu.join(", ") }}
                    </div>
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="w-16 py-2 pr-3">Kolejność</th>
                            <th class="py-2 pr-3">Nazwa</th>
                            <th class="w-32 py-2 pr-3">Kolor</th>
                            <th class="w-28 py-2 pr-3">Sprzedaż od</th>
                            <th class="w-28 py-2 pr-3">Sprzedaż do</th>
                            <th class="w-24 py-2 pr-3">Kodów</th>
                            <th class="w-40 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(stage, index) in draft" :key="stage.id" class="border-b border-gray-100">
                            <td class="py-2 pr-3">
                                <div class="flex items-center gap-1">
                                    <IconButton
                                        :icon="ChevronUpIcon"
                                        variant="ghost"
                                        color="gray"
                                        size="sm"
                                        :disabled="index === 0"
                                        @click="move(index, -1)"
                                    />
                                    <IconButton
                                        :icon="ChevronDownIcon"
                                        variant="ghost"
                                        color="gray"
                                        size="sm"
                                        :disabled="index === draft.length - 1"
                                        @click="move(index, 1)"
                                    />
                                </div>
                            </td>
                            <td class="py-2 pr-3">
                                <input v-model="stage.name" type="text" class="w-full rounded border-gray-300 text-sm" />
                            </td>
                            <td class="py-2 pr-3">
                                <div class="flex items-center gap-2">
                                    <input v-model="stage.color" type="color" class="h-8 w-10 cursor-pointer rounded border border-gray-300" />
                                    <span class="font-mono text-xs text-gray-500">{{ stage.color }}</span>
                                </div>
                            </td>
                            <td class="py-2 pr-3">
                                <input
                                    v-model="stage.sales_from"
                                    type="number"
                                    min="0"
                                    placeholder="—"
                                    class="w-full rounded border-gray-300 text-sm"
                                />
                            </td>
                            <td class="py-2 pr-3">
                                <input
                                    v-model="stage.sales_to"
                                    type="number"
                                    min="0"
                                    placeholder="bez limitu"
                                    class="w-full rounded border-gray-300 text-sm"
                                />
                            </td>
                            <td class="py-2 pr-3 text-gray-500">{{ stage.codes }}</td>
                            <td class="py-2">
                                <div class="flex items-center justify-end gap-2">
                                    <Button size="sm" @click="save(stage)">Zapisz</Button>
                                    <IconButton
                                        :icon="TrashIcon"
                                        variant="ghost"
                                        color="gray"
                                        size="sm"
                                        @click="remove(stage)"
                                    />
                                </div>
                            </td>
                        </tr>

                        <tr v-if="!draft.length">
                            <td colspan="7" class="py-6 text-center text-sm text-gray-500">
                                Nie ma jeszcze żadnego etapu.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Nowy etap -->
                <div class="mt-6 rounded-md border border-dashed border-gray-300 p-4">
                    <div class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-500">
                        Nowy etap
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-[200px] flex-1">
                            <label class="mb-1 block text-xs text-gray-500">Nazwa</label>
                            <input v-model="fresh.name" type="text" placeholder="np. Etap 4" class="w-full rounded border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">Kolor</label>
                            <input v-model="fresh.color" type="color" class="h-9 w-14 cursor-pointer rounded border border-gray-300" />
                        </div>
                        <div class="w-28">
                            <label class="mb-1 block text-xs text-gray-500">Sprzedaż od</label>
                            <input v-model="fresh.sales_from" type="number" min="0" class="w-full rounded border-gray-300 text-sm" />
                        </div>
                        <div class="w-28">
                            <label class="mb-1 block text-xs text-gray-500">Sprzedaż do</label>
                            <input v-model="fresh.sales_to" type="number" min="0" placeholder="bez limitu" class="w-full rounded border-gray-300 text-sm" />
                        </div>
                        <Button :leftIcon="PlusIcon" @click="create">Dodaj etap</Button>
                    </div>
                </div>

                <p class="mt-4 text-xs text-gray-400">
                    Zmiana nazwy, koloru czy przedziału zapisuje się przyciskiem
                    <strong>Zapisz</strong> w wierszu. Sama zmiana przedziału nie przestawia
                    jeszcze etapów w tabeli — trzeba kliknąć <strong>Przelicz etapy</strong>.
                </p>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import {
    ArrowPathIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    PlusIcon,
    TrashIcon,
} from "@heroicons/vue/24/outline";
import { Button, Card, IconButton, PageContent, PageHeader } from "crafter/Components";
import GroupsTab from "./GroupsTab.vue";
import ExclusionsTab from "./ExclusionsTab.vue";

interface Stage {
    id: number;
    name: string;
    color: string;
    sales_from: number | null;
    sales_to: number | null;
    position: number;
    codes: number;
}

interface Bucket {
    label: string;
    count: number;
    percent: number;
}

interface Props {
    stages: Stage[];
    histogram: Bucket[];
    groups: { proposed: any[]; approved: any[]; rejected: any[] };
    exclusions: { codes: any[]; excluded_count: number };
}

const props = defineProps<Props>();
const toast = useToast();

const tab = ref<"stages" | "groups" | "exclusions">("stages");

// Kopia do edycji — inertiowe propsy sa zamrozone, a pola sa edytowalne w miejscu.
const draft = ref<Stage[]>(props.stages.map((s) => ({ ...s })));
watch(
    () => props.stages,
    (next) => {
        draft.value = next.map((s) => ({ ...s }));
    }
);

const fresh = ref({
    name: "",
    color: "#6b7280",
    sales_from: null as number | null,
    sales_to: null as number | null,
});

// Puste pole liczbowe przychodzi jako "" — do bazy ma isc NULL, nie 0,
// bo 0 to prawidlowa dolna granica (kody bez sprzedazy).
const asNumber = (value: unknown): number | null => {
    if (value === "" || value === null || value === undefined) return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const payload = (stage: { name: string; color: string; sales_from: unknown; sales_to: unknown }) => ({
    name: stage.name,
    color: stage.color,
    sales_from: asNumber(stage.sales_from),
    sales_to: asNumber(stage.sales_to),
});

// preserveState: true — bez niego Inertia odtwarza komponent i gubi lokalne refy,
// czyli otwarta zakladke Ustawien. Propsy i tak przychodza swieze z serwera.
function save(stage: Stage): void {
    router.put(route("crafter.production.stages.update", stage.id), payload(stage), {
        preserveScroll: true,
        preserveState: true,
    });
}

function create(): void {
    if (!fresh.value.name.trim()) {
        toast.error("Etap musi mieć nazwę");
        return;
    }

    router.post(route("crafter.production.stages.store"), payload(fresh.value), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            fresh.value = { name: "", color: "#6b7280", sales_from: null, sales_to: null };
        },
    });
}

function remove(stage: Stage): void {
    if (!window.confirm(`Usunąć etap „${stage.name}"? Kody stracą to oznaczenie.`)) {
        return;
    }

    router.delete(route("crafter.production.stages.destroy", stage.id), { preserveScroll: true, preserveState: true });
}

/** Kolejnosc decyduje, ktory etap wygrywa przy nachodzacych przedzialach. */
function move(index: number, direction: number): void {
    const next = [...draft.value];
    const target = index + direction;
    if (target < 0 || target >= next.length) return;

    [next[index], next[target]] = [next[target], next[index]];
    draft.value = next;

    router.post(
        route("crafter.production.stages.reorder"),
        { order: next.map((s) => s.id) },
        { preserveScroll: true, preserveState: true }
    );
}

const recalculating = ref(false);
const recalcResult = ref<any>(null);

async function recalculate(): Promise<void> {
    recalculating.value = true;
    try {
        const { data } = await axios.post(route("crafter.production.stages.recalculate"));
        recalcResult.value = data;
        // Liczniki „Kodów" w tabeli pochodza z serwera — po przeliczeniu trzeba je odswiezyc.
        router.reload({ only: ["stages"], preserveScroll: true });
    } catch (e) {
        toast.error("Nie udało się przeliczyć etapów");
    } finally {
        recalculating.value = false;
    }
}

function tabClass(active: boolean): string {
    return [
        "border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap",
        active
            ? "border-primary-500 text-primary-600"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}
</script>
