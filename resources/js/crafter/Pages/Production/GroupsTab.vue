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

        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                    <th class="w-40 py-2 pr-3">Trzon</th>
                    <th class="py-2 pr-3">Warianty — odznacz te, które mają zostać osobno</th>
                    <th class="w-32 py-2 pr-3 text-right">Sprzedaż wiersza</th>
                    <th class="w-56 py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="group in visible" :key="group.id" class="border-b border-gray-100 align-top">
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
                    <td colspan="4" class="py-8 text-center text-sm text-gray-500">
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
import { computed, ref } from "vue";
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

interface Props {
    groups: { proposed: Group[]; approved: Group[]; rejected: Group[] };
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

function tabClass(on: boolean): string {
    return [
        "border-b-2 px-1 py-2 text-sm font-medium whitespace-nowrap",
        on
            ? "border-primary-500 text-primary-600"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}

function toggle(member: Member, included: boolean): void {
    router.put(
        route("crafter.production.groups.member", member.id),
        { included },
        { preserveScroll: true, preserveState: false }
    );
}

function post(action: "approve" | "revoke" | "reject", group: Group): void {
    router.post(
        route(`crafter.production.groups.${action}`, group.id),
        {},
        { preserveScroll: true, preserveState: false }
    );
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
