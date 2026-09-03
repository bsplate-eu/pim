<!--
  Ustawienia → Grupowanie.

  Propozycje liczy automat (trzon = kod bez koncowych liter), ale nic nie dziala
  samo: grupa laczy wiersze dopiero po zatwierdzeniu. W propozycji odznaczasz
  warianty, ktore maja zostac osobno.
-->
<template>
    <div>
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-base font-medium text-gray-900">Grupowanie wariantów kodu</h3>
                <p class="mt-1 max-w-3xl text-sm text-gray-500">
                    Trzon to kod bez końcowych liter, więc <code>30.145</code> łączy się
                    z <code>30.145ALU</code>, ale <code>00.1791</code> nie łączy się
                    z <code>00.1792</code> — to osobne osłony. Zatwierdzona grupa daje
                    <strong>jeden wiersz</strong> w tabeli produkcji, a sprzedaż wariantów
                    <strong>sumuje się</strong> do trzonu. Odznaczony wariant zostaje
                    osobnym wierszem.
                </p>
            </div>
            <Button :leftIcon="ArrowPathIcon" :loading="scanning" @click="scan">
                Szukaj propozycji
            </Button>
        </div>

        <!-- Lista sufiksow materialowych. To ona decyduje, co jest wariantem:
             ALU laczy sie z baza, a „W" czy „A" zostaja osobnym trzonem. -->
        <div class="mb-5 rounded-md border border-gray-200 bg-gray-50 p-4">
            <div class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">
                Sufiksy materiałowe — tylko te końcówki łączą się z kodem bazowym
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span
                    v-for="s in suffixes"
                    :key="s.id"
                    class="inline-flex items-center gap-2 rounded-full border border-gray-300 bg-white px-3 py-1 text-sm"
                >
                    <span class="font-mono font-semibold">{{ s.suffix }}</span>
                    <button
                        type="button"
                        class="text-gray-400 hover:text-red-600"
                        title="Usuń sufiks"
                        @click="removeSuffix(s)"
                    >×</button>
                </span>

                <input
                    v-model="newSuffix"
                    type="text"
                    placeholder="np. INOX"
                    class="w-28 rounded border-gray-300 text-sm uppercase"
                    @keyup.enter="addSuffix"
                />
                <Button size="sm" color="gray" variant="outline" @click="addSuffix">Dodaj</Button>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                <code>30.144</code> + <code>30.144ALU</code> to jedna osłona w dwóch materiałach.
                Ale <code>30.144W</code> to <strong>inna</strong> osłona — ma własną wersję
                <code>30.144WALU</code>, więc jest osobnym trzonem.
                <strong>Zmiana listy przelicza propozycje od nowa</strong>; zatwierdzone
                i odrzucone grupy zostają.
            </p>
        </div>

        <div v-if="scanResult" class="mb-5 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
            Nowych grup: <strong>{{ scanResult.nowych_grup }}</strong>,
            nowych wariantów: <strong>{{ scanResult.nowych_wariantow }}</strong>,
            propozycji czeka: <strong>{{ scanResult.propozycji }}</strong>
        </div>

        <div class="mb-4 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                <button
                    v-for="t in innerTabs"
                    :key="t.key"
                    type="button"
                    @click="active = t.key"
                    :class="tabClass(active === t.key)"
                >
                    {{ t.label }}
                    <span class="ml-1 text-xs text-gray-400">{{ t.count }}</span>
                </button>
            </nav>
        </div>

        <!-- Pasek operacji masowych — pojawia sie dopiero gdy cos zaznaczone,
             zeby nie zajmowal miejsca przy zwyklym przegladaniu. -->
        <div
            v-if="selected.size"
            class="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2"
        >
            <span class="text-sm text-indigo-900">
                Zaznaczonych: <strong>{{ selected.size }}</strong>
            </span>
            <div class="ml-auto flex items-center gap-2">
                <Button v-if="active !== 'approved'" size="sm" @click="bulk('approve')">
                    Zatwierdź zaznaczone
                </Button>
                <Button v-if="active === 'approved'" size="sm" color="gray" variant="outline" @click="bulk('revoke')">
                    Cofnij zaznaczone
                </Button>
                <Button v-if="active === 'proposed'" size="sm" color="gray" variant="outline" @click="bulk('reject')">
                    Odrzuć zaznaczone
                </Button>
                <Button size="sm" color="gray" variant="ghost" @click="selected = new Set()">
                    Wyczyść
                </Button>
            </div>
        </div>

        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                    <th class="w-10 py-2 pr-2">
                        <input
                            type="checkbox"
                            :checked="allSelected"
                            :indeterminate.prop="selected.size > 0 && !allSelected"
                            @change="toggleAll(($event.target as HTMLInputElement).checked)"
                        />
                    </th>
                    <th class="w-40 py-2 pr-3">Trzon</th>
                    <th class="py-2 pr-3">Warianty — odznacz te, które mają zostać osobno</th>
                    <th class="w-32 py-2 pr-3 text-right">Sprzedaż wiersza</th>
                    <th class="w-56 py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="group in visible"
                    :key="group.id"
                    class="border-b border-gray-100 align-top"
                    :class="selected.has(group.id) ? 'bg-indigo-50/50' : ''"
                >
                    <td class="py-3 pr-2">
                        <input
                            type="checkbox"
                            :checked="selected.has(group.id)"
                            @change="toggleOne(group.id, ($event.target as HTMLInputElement).checked)"
                        />
                    </td>
                    <td class="py-3 pr-3">
                        <div class="font-mono font-semibold text-gray-900">{{ group.trunk }}</div>
                        <div class="text-xs text-gray-400">{{ group.trunk_sales }} szt. własne</div>
                    </td>
                    <td class="py-3 pr-3">
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <label
                                v-for="member in group.members"
                                :key="member.id"
                                class="inline-flex cursor-pointer items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    :checked="member.included"
                                    @change="toggle(member, ($event.target as HTMLInputElement).checked)"
                                />
                                <span :class="member.included ? 'text-gray-900' : 'text-gray-400 line-through'">
                                    {{ member.product_code }}
                                </span>
                                <span class="text-xs text-gray-400">{{ member.sales_12m }} szt.</span>
                            </label>
                        </div>
                        <div v-if="group.trunk_name" class="mt-1 text-xs text-gray-400">
                            {{ group.trunk_name }}
                        </div>
                    </td>
                    <td class="py-3 pr-3 text-right">
                        <strong class="text-gray-900">{{ group.sales_after }}</strong>
                        <span class="text-xs text-gray-400"> szt.</span>
                    </td>
                    <td class="py-3">
                        <div class="flex items-center justify-end gap-2">
                            <template v-if="group.status === 'approved'">
                                <span class="rounded bg-green-50 px-2 py-1 text-xs font-medium text-green-700">
                                    grupuje
                                </span>
                                <Button size="sm" color="gray" variant="outline" @click="post('revoke', group)">
                                    Cofnij
                                </Button>
                            </template>
                            <template v-else-if="group.status === 'rejected'">
                                <span class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-500">odrzucona</span>
                                <Button size="sm" color="gray" variant="outline" @click="post('revoke', group)">
                                    Przywróć
                                </Button>
                            </template>
                            <template v-else>
                                <Button size="sm" @click="post('approve', group)">Zatwierdź</Button>
                                <Button size="sm" color="gray" variant="outline" @click="post('reject', group)">
                                    Odrzuć
                                </Button>
                            </template>
                        </div>
                    </td>
                </tr>

                <tr v-if="!visible.length">
                    <td colspan="5" class="py-8 text-center text-sm text-gray-500">
                        Nic tutaj. Kliknij <strong>Szukaj propozycji</strong>, żeby przeskanować katalog.
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="mt-4 text-xs text-gray-400">
            Po zatwierdzeniu grup sprzedaż wierszy się zmienia, a etap wynika ze sprzedaży —
            wróć na zakładkę <strong>Etapy</strong> i kliknij <strong>Przelicz etapy</strong>.
            Znaczniki postawione na wciąganym wariancie przenoszą się na trzon.
        </p>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import { ArrowPathIcon } from "@heroicons/vue/24/outline";
import { Button } from "crafter/Components";

interface Member {
    id: number;
    product_code: string;
    included: boolean;
    sales_12m: number;
}

interface Group {
    id: number;
    trunk: string;
    trunk_name: string;
    trunk_sales: number;
    status: string;
    members: Member[];
    sales_after: number;
}

interface Suffix {
    id: number;
    suffix: string;
}

interface Props {
    groups: {
        suffixes: Suffix[];
        proposed: Group[];
        approved: Group[];
        rejected: Group[];
    };
}

const props = defineProps<Props>();
const toast = useToast();

type Inner = "proposed" | "approved" | "rejected";
const active = ref<Inner>("proposed");

const innerTabs = computed(() => [
    { key: "proposed" as Inner, label: "Do zatwierdzenia", count: props.groups.proposed.length },
    { key: "approved" as Inner, label: "Grupujące", count: props.groups.approved.length },
    { key: "rejected" as Inner, label: "Odrzucone", count: props.groups.rejected.length },
]);

const visible = computed<Group[]>(() => props.groups[active.value] ?? []);

// === SUFIKSY MATERIALOWE ===
const suffixes = computed<Suffix[]>(() => props.groups.suffixes ?? []);
const newSuffix = ref("");

function addSuffix(): void {
    const value = newSuffix.value.trim().toUpperCase();
    if (!value) return;

    router.post(
        route("crafter.production.suffixes.store"),
        { suffix: value },
        { ...visitOptions, onSuccess: () => { newSuffix.value = ""; } }
    );
}

function removeSuffix(s: Suffix): void {
    if (!window.confirm(`Usunąć sufiks „${s.suffix}"? Kody z tą końcówką przestaną łączyć się z bazą.`)) {
        return;
    }

    router.delete(route("crafter.production.suffixes.destroy", s.id), visitOptions);
}

// === ZAZNACZANIE ===
// Czyscimy przy zmianie zakladki: zaznaczenie z „Do zatwierdzenia" nie ma sensu
// na „Grupujacych", a operacja masowa dziala na tym, co widac.
const selected = ref<Set<number>>(new Set());
watch(active, () => {
    selected.value = new Set();
});

const allSelected = computed<boolean>(
    () => visible.value.length > 0 && visible.value.every((g) => selected.value.has(g.id))
);

function toggleOne(id: number, on: boolean): void {
    const next = new Set(selected.value);
    on ? next.add(id) : next.delete(id);
    selected.value = next;
}

function toggleAll(on: boolean): void {
    selected.value = on ? new Set(visible.value.map((g) => g.id)) : new Set();
}

function bulk(action: "approve" | "reject" | "revoke"): void {
    const ids = Array.from(selected.value);
    if (!ids.length) return;

    const slowo = action === "approve" ? "Zatwierdzić" : action === "reject" ? "Odrzucić" : "Cofnąć";
    if (!window.confirm(`${slowo} ${ids.length} grup?`)) return;

    router.post(
        route("crafter.production.groups.bulk"),
        { ids, action },
        {
            ...visitOptions,
            onSuccess: () => {
                selected.value = new Set();
            },
        }
    );
}

function tabClass(on: boolean): string {
    return [
        "border-b-2 px-1 py-2 text-sm font-medium whitespace-nowrap",
        on
            ? "border-primary-500 text-primary-600"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}

// preserveState: true jest tu istotne. Bez niego Inertia odtwarza komponent od
// zera, a wtedy gina lokalne refy — czyli ktora zakladka Ustawien jest otwarta
// i ktory stan grup ogladasz. Klikniecie checkboxa wyrzucalo z powrotem na Etapy.
// Propsy i tak przychodza swieze z serwera, wiec liczby sie odswiezaja.
const visitOptions = { preserveScroll: true, preserveState: true };

function toggle(member: Member, included: boolean): void {
    router.put(route("crafter.production.groups.member", member.id), { included }, visitOptions);
}

function post(action: "approve" | "revoke" | "reject", group: Group): void {
    router.post(route(`crafter.production.groups.${action}`, group.id), {}, visitOptions);
}

const scanning = ref(false);
const scanResult = ref<any>(null);

async function scan(): Promise<void> {
    scanning.value = true;
    try {
        const { data } = await axios.post(route("crafter.production.groups.scan"));
        scanResult.value = data;
        router.reload({ only: ["groups"], preserveScroll: true });
    } catch (e) {
        toast.error("Nie udało się przeskanować katalogu");
    } finally {
        scanning.value = false;
    }
}
</script>
