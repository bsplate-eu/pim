<template>
    <PageContent>
        <div class="relative flex flex-1 items-stretch overflow-hidden">
            <div class="flex-1 overflow-y-auto pb-20">
                <div class="mt-6 w-full px-4 sm:px-6 md:px-8">
                    <div class="flex flex-col gap-6">
                        <!-- Kafelek: Do zapłaty (źródło: KSeF) -->
                        <div class="flex flex-col gap-4 rounded-lg bg-gradient-to-r from-primary-600 to-primary-800 p-5 text-white shadow-lg sm:p-8">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-14 w-14 items-center justify-center rounded-lg bg-white shadow-lg">
                                        <BanknotesIcon class="h-8 w-8 text-primary-700" />
                                    </div>
                                    <div>
                                        <div class="text-lg font-light leading-tight">Do zapłaty</div>
                                        <div class="text-xs font-light text-primary-100">{{ duePeriodLabel }} · źródło: KSeF</div>
                                    </div>
                                </div>
                                <div class="inline-flex rounded-lg bg-white/15 p-1">
                                    <button
                                        v-for="p in DUE_PERIODS"
                                        :key="p.key"
                                        type="button"
                                        @click="duePeriod = p.key"
                                        :class="[
                                            'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                            duePeriod === p.key ? 'bg-white text-primary-700 shadow-sm' : 'text-white/80 hover:text-white',
                                        ]"
                                    >
                                        {{ p.label }}
                                    </button>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div v-for="(label, key) in duePayments.companies" :key="key" class="rounded-lg bg-white/10 px-4 py-3">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-primary-100">{{ label }}</div>
                                    <div class="mt-1 space-y-0.5">
                                        <div
                                            v-for="(line, i) in companyLines(duePeriod, String(key))"
                                            :key="i"
                                            class="text-2xl font-bold leading-tight"
                                        >
                                            {{ line }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-5 gap-6">
                            <div
                                class="flex justify-between bg-gradient-to-r from-primary-600 to-primary-800  text-white p-5 sm:p-8 shadow-lg rounded-lg">
                                <div class="flex flex-col justify-center">
                                    <div class="text-4xl font-bold">
                                        <count-up :end-val="counters.sources ?? 0"
                                                  :options="countUpOptions"></count-up>
                                    </div>
                                    <div class="text-lg font-light">{{ $t("crafter", "Sources") }}</div>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div
                                        class="flex items-center justify-center w-14 h-14 shadow-lg rounded-lg bg-white sm:w-14 sm:h-14">
                                        <CircleStackIcon
                                            class="w-8 h-8 text-primary-700 sm:w-10 sm:h-10"></CircleStackIcon>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="flex justify-between bg-gradient-to-r from-primary-600 to-primary-800  text-white p-5 sm:p-8 shadow-lg rounded-lg">
                                <div class="flex flex-col justify-center">
                                    <div class="text-4xl font-bold">
                                        <count-up :end-val="counters.integrations ?? 0"
                                                  :options="countUpOptions"></count-up>
                                    </div>
                                    <div class="text-lg font-light">{{ $t("crafter", "Integrations") }}</div>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div
                                        class="flex items-center justify-center w-14 h-14 shadow-lg rounded-lg bg-white sm:w-14 sm:h-14">
                                        <CubeTransparentIcon
                                            class="w-8 h-8 text-primary-700 sm:w-10 sm:h-10"></CubeTransparentIcon>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="flex justify-between bg-gradient-to-r from-primary-600 to-primary-800  text-white p-5 sm:p-8 shadow-lg rounded-lg">
                                <div class="flex flex-col justify-center">
                                    <div class="text-4xl font-bold">
                                        <count-up :end-val="counters.products ?? 0"
                                                  :options="countUpOptions"></count-up>
                                    </div>
                                    <div class="text-lg font-light">{{ $t("crafter", "Products") }}</div>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div
                                        class="flex items-center justify-center w-14 h-14 shadow-lg rounded-lg bg-white sm:w-14 sm:h-14">
                                        <ShoppingCartIcon
                                            class="w-8 h-8 text-primary-700 sm:w-10 sm:h-10"></ShoppingCartIcon>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="flex justify-between bg-gradient-to-r from-primary-600 to-primary-800  text-white p-5 sm:p-8 shadow-lg rounded-lg">
                                <div class="flex flex-col justify-center">
                                    <div class="text-4xl font-bold">
                                        <count-up :end-val="counters.pricelists ?? 0"
                                                  :options="countUpOptions"></count-up>
                                    </div>
                                    <div class="text-lg font-light">{{ $t("crafter", "Pricelists") }}</div>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div
                                        class="flex items-center justify-center w-14 h-14 shadow-lg rounded-lg bg-white sm:w-14 sm:h-14">
                                        <CurrencyDollarIcon
                                            class="w-8 h-8 text-primary-700 sm:w-10 sm:h-10"></CurrencyDollarIcon>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="flex justify-between bg-gradient-to-r from-primary-600 to-primary-800  text-white p-5 sm:p-8 shadow-lg rounded-lg">
                                <div class="flex flex-col justify-center">
                                    <div class="text-4xl font-bold">
                                        <count-up :end-val="counters.templates ?? 0"
                                                  :options="countUpOptions"></count-up>
                                    </div>
                                    <div class="text-lg font-light">{{ $t("crafter", "Templates") }}</div>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div
                                        class="flex items-center justify-center w-14 h-14 shadow-lg rounded-lg bg-white sm:w-14 sm:h-14">
                                        <PuzzlePieceIcon
                                            class="w-8 h-8 text-primary-700 sm:w-10 sm:h-10"></PuzzlePieceIcon>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-6 my-6">
                        <div class="bg-white shadow-lg rounded-lg">
                            <div class="px-6 py-4">
                                <div class="flex items-center mb-4">
                                    <NewspaperIcon
                                        class="text-primary-500 h-8 w-8"></NewspaperIcon>
                                    <h5 class="ml-2 text-slate-800 text-xl font-semibold">
                                        {{ $t("crafter", "New Products") }}
                                    </h5>
                                </div>
                            </div>
                            <div class="flex justify-center items-center p-8">
                                <apexchart type="area"
                                           ref="productsChart"
                                           height="300"
                                           :options="props.charts.products.options"
                                           :series="props.charts.products.series"
                                           class="w-full h-50"
                                ></apexchart>
                            </div>
                        </div>
                        <div class="bg-white shadow-lg rounded-lg">
                            <div class="px-6 py-4">
                                <div class="flex items-center mb-4">
                                    <StarIcon class="text-primary-500 h-8 w-8"></StarIcon>
                                    <h5 class="ml-2 text-slate-800 text-xl font-semibold">
                                        {{ $t("crafter", "Top Categories") }}
                                    </h5>
                                </div>
                            </div>
                            <div class="flex justify-center items-center p-8">
                                <apexchart type="pie"
                                           ref="categoriesChart"
                                           height="300"
                                           :options="props.charts.categories.options"
                                           :series="props.charts.categories.series"
                                           class="w-full h-50"
                                ></apexchart>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import {PageContent} from "crafter/Components";
import CountUp from 'vue-countup-v3'
import {
    NewspaperIcon,
    StarIcon,
    CubeTransparentIcon,
    ShoppingCartIcon,
    CurrencyDollarIcon,
    PuzzlePieceIcon,
    CircleStackIcon,
    BanknotesIcon,
} from "@heroicons/vue/24/outline";
import {computed, nextTick, onMounted, ref} from 'vue'

type DuePeriod = "dzis" | "tydzien" | "miesiac";

interface DuePayments {
    companies: Record<string, string>;
    totals: Record<DuePeriod, Record<string, Record<string, number>>>;
}

interface Props {
    counters: any;
    charts: any;
    duePayments: DuePayments;
}

const props = defineProps<Props>();
const countUpOptions = {useGrouping: false, scrollSpyOnce: true, duration: 1};

// ── Kafelek „Do zapłaty" ──
const DUE_PERIODS: { key: DuePeriod; label: string }[] = [
    { key: "dzis", label: "Dziś" },
    { key: "tydzien", label: "Tydzień" },
    { key: "miesiac", label: "Miesiąc" },
];
const duePeriod = ref<DuePeriod>("dzis");
const duePeriodLabel = computed(() => DUE_PERIODS.find((p) => p.key === duePeriod.value)?.label ?? "");

function formatAmount(amount: number, currency: string): string {
    const n = new Intl.NumberFormat("pl-PL", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount || 0));
    return currency === "PLN" ? `${n} zł` : `${n} ${currency}`;
}

/** Linie kwot dla firmy w danym okresie (po walutach; PLN pierwsze). Brak FV → „0,00 zł". */
function companyLines(period: DuePeriod, companyKey: string): string[] {
    const map = props.duePayments?.totals?.[period]?.[companyKey] ?? {};
    const currencies = Object.keys(map);
    if (currencies.length === 0) return [formatAmount(0, "PLN")];
    currencies.sort((a, b) => (a === "PLN" ? -1 : b === "PLN" ? 1 : a.localeCompare(b)));
    return currencies.map((c) => formatAmount(map[c], c));
}
const productsChart = ref();
const categoriesChart = ref();

onMounted(async () => {
    nextTick(() => {
        productsChart.value.refresh()
        categoriesChart.value.refresh()
    });
});
</script>
