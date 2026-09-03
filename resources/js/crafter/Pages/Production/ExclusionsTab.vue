<!--
  Ustawienia → Wykluczenia.

  Lista wszystkich kodow z katalogu, domyslnie nic nie zaznaczone. Zaznaczony
  kod znika z tabeli produkcji i przestaje sie liczyc do czegokolwiek — sumy
  grupy, etapow, barometru. Nic nie jest kasowane: odznaczenie przywraca kod.
-->
<template>
    <div>
        <div class="mb-4">
            <h3 class="text-base font-medium text-gray-900">Wykluczenia</h3>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">
                Zaznaczony kod <strong>znika z tabeli produkcji</strong> i przestaje
                się liczyć: nie dokłada sprzedaży do grupy, nie dostaje etapu, nie
                wchodzi do barometru. Nic przy tym nie jest kasowane — odznaczenie
                przywraca kod w tym samym stanie.
            </p>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="min-w-[240px] flex-1">
                <label class="mb-1 block text-xs text-gray-500">Szukaj kodu lub nazwy</label>
                <input
                    v-model="search"
                    type="text"
                    placeholder="np. 30.144 albo Sprinter"
                    class="w-full rounded border-gray-300 text-sm"
                />
            </div>
            <div class="flex items-center gap-4 text-sm">
                <label class="inline-flex cursor-pointer items-center gap-2">
                    <input v-model="onlyExcluded" type="checkbox" />
                    <span>Pokaż tylko wykluczone</span>
                </label>
                <span class="text-gray-500">
                    Wykluczonych: <strong class="text-gray-900">{{ excludedCount }}</strong>
                    / {{ codes.length }}
                </span>
            </div>
        </div>

        <!-- Pasek masowy — pojawia sie dopiero gdy cos zaznaczone. -->
        <div
            v-if="selected.size"
            class="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2"
        >
            <span class="text-sm text-indigo-900">
                Zaznaczonych: <strong>{{ selected.size }}</strong>
            </span>
            <div class="ml-auto flex items-center gap-2">
                <Button size="sm" @click="bulk(true)">Wyklucz zaznaczone</Button>
                <Button size="sm" color="gray" variant="outline" @click="bulk(false)">
                    Przywróć zaznaczone
                </Button>
                <Button size="sm" color="gray" variant="ghost" @click="selected = new Set()">
                    Wyczyść
                </Button>
            </div>
        </div>

        <div class="max-h-[32rem] overflow-auto rounded border border-gray-200">
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-gray-50">
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <th class="w-10 py-2 pl-3 pr-2">
                            <input
                                type="checkbox"
                                :checked="allSelected"
                                :indeterminate.prop="selected.size > 0 && !allSelected"
                                @change="toggleAll(($event.target as HTMLInputElement).checked)"
                            />
                        </th>
                        <th class="w-14 py-2 pr-3">Wyklucz</th>
                        <th class="w-36 py-2 pr-3">Kod</th>
                        <th class="py-2 pr-3">Nazwa</th>
                        <th class="w-28 py-2 pr-3 text-right">Sprzedaż</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in visible"
                        :key="item.product_code"
                        class="border-b border-gray-100"
                        :class="item.excluded ? 'bg-gray-50 text-gray-400' : ''"
                    >
                        <td class="py-1.5 pl-3 pr-2">
                            <input
                                type="checkbox"
                                :checked="selected.has(item.product_code)"
                                @change="toggleOne(item.product_code, ($event.target as HTMLInputElement).checked)"
                            />
                        </td>
                        <td class="py-1.5 pr-3">
                            <input
                                type="checkbox"
                                :checked="item.excluded"
                                @change="toggle(item, ($event.target as HTMLInputElement).checked)"
                            />
                        </td>
                        <td class="py-1.5 pr-3 font-mono" :class="item.excluded ? 'line-through' : 'text-gray-900'">
                            {{ item.product_code }}
                        </td>
                        <td class="py-1.5 pr-3">{{ item.name }}</td>
                        <td class="py-1.5 pr-3 text-right">{{ item.sales_12m }}</td>
                    </tr>

                    <tr v-if="!visible.length">
                        <td colspan="5" class="py-8 text-center text-sm text-gray-500">
                            Nic nie pasuje do wyszukiwania.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-400">
            Widocznych: <strong>{{ visible.length }}</strong> z {{ codes.length }}.
            Po zmianie wykluczeń wróć na zakładkę <strong>Etapy</strong> i kliknij
            <strong>Przelicz etapy</strong> — wykluczony kod przestaje mieć etap.
        </p>
    </div>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { Button } from "crafter/Components";

interface CodeRow {
    product_code: string;
    name: string;
    sales_12m: number;
    excluded: boolean;
}

interface Props {
    exclusions: { codes: CodeRow[]; excluded_count: number };
}

const props = defineProps<Props>();

const codes = computed<CodeRow[]>(() => props.exclusions.codes ?? []);
const excludedCount = computed<number>(() => codes.value.filter((c) => c.excluded).length);

const search = ref("");
const onlyExcluded = ref(false);

// Filtrowanie po stronie przegladarki — 707 pozycji, wiec nie ma po co
// biegac na serwer przy kazdej literze.
const visible = computed<CodeRow[]>(() => {
    const q = search.value.trim().toLowerCase();

    return codes.value.filter((c) => {
        if (onlyExcluded.value && !c.excluded) return false;
        if (!q) return true;
        return (
            c.product_code.toLowerCase().includes(q) ||
            c.name.toLowerCase().includes(q)
        );
    });
});

// preserveState: true — bez niego Inertia odtwarza komponent i gubi zakladke,
// wyszukiwanie oraz zaznaczenie.
const visitOptions = { preserveScroll: true, preserveState: true };

function toggle(item: CodeRow, excluded: boolean): void {
    router.post(
        route("crafter.production.exclusions.toggle"),
        { product_code: item.product_code, excluded },
        visitOptions
    );
}

// === ZAZNACZANIE ===
const selected = ref<Set<string>>(new Set());

const allSelected = computed<boolean>(
    () => visible.value.length > 0 && visible.value.every((c) => selected.value.has(c.product_code))
);

function toggleOne(code: string, on: boolean): void {
    const next = new Set(selected.value);
    on ? next.add(code) : next.delete(code);
    selected.value = next;
}

function toggleAll(on: boolean): void {
    selected.value = on ? new Set(visible.value.map((c) => c.product_code)) : new Set();
}

function bulk(excluded: boolean): void {
    const list = Array.from(selected.value);
    if (!list.length) return;

    const slowo = excluded ? "Wykluczyć" : "Przywrócić";
    if (!window.confirm(`${slowo} ${list.length} kodów?`)) return;

    router.post(
        route("crafter.production.exclusions.bulk"),
        { codes: list, excluded },
        { ...visitOptions, onSuccess: () => { selected.value = new Set(); } }
    );
}
</script>
