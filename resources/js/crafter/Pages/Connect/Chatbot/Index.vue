<template>
    <PageHeader title="Integracja chatboot" />

    <PageContent fluid>
        <!-- Taby -->
        <div class="mb-5 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                <button type="button" @click="tab = 'sales'" :class="tab === 'sales' ? activeTab : idleTab">
                    Raport sprzedaży
                </button>
                <button type="button" @click="tab = 'ksef'" :class="tab === 'ksef' ? activeTab : idleTab">
                    Powiadomienia KSeF
                </button>
            </nav>
        </div>

        <!-- ════════ TAB: RAPORT SPRZEDAŻY ════════ -->
        <div v-show="tab === 'sales'" class="max-w-2xl">
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Raport sprzedaży</h2>
                    <p class="text-sm text-gray-500">
                        Codzienny raport sprzedaży <strong>za poprzedni dzień</strong> (z zamówień Argo Connect) wysyłany na WhatsApp.
                        Kwoty per kraj w walucie zamówienia, przeliczone na PLN, z sumą i obrotem tydzień/miesiąc (bieżące).
                    </p>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-medium text-gray-900">Wysyłka włączona</div>
                                <div class="text-xs text-gray-500">Codziennie o {{ sales.send_time }} (cron).</div>
                            </div>
                            <Toggle v-model="sales.enabled" />
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Godzina wysyłki</label>
                                <input v-model="sales.send_time" type="time"
                                    class="block w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Numer odbiorcy (WhatsApp)</label>
                                <input v-model="sales.phone" placeholder="puste = ten sam co KSeF"
                                    class="block w-full rounded-md border-gray-300 text-sm font-mono focus:border-primary-500 focus:ring-primary-500" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">CallMeBot apikey</label>
                                <input v-model="sales.api_key" placeholder="puste = ten sam co KSeF"
                                    class="block w-full rounded-md border-gray-300 text-sm font-mono focus:border-primary-500 focus:ring-primary-500" />
                                <p class="mt-1 text-xs text-gray-500">
                                    Zostaw numer i apikey puste, aby wysyłać na ten sam WhatsApp co powiadomienia KSeF.
                                </p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Treść raportu</label>
                            <textarea v-model="sales.template" rows="8"
                                class="block w-full rounded-md border-gray-300 text-sm font-mono focus:border-primary-500 focus:ring-primary-500"></textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Placeholdery: <code>{sprzedaz_per_kraj}</code>, <code>{razem_dzis}</code>,
                                <code>{obrot_tydzien}</code>, <code>{obrot_miesiac}</code>, <code>{data}</code>.
                                Polskie znaki są zamieniane przy wysyłce (CallMeBot WhatsApp ich nie przyjmuje).
                            </p>
                        </div>

                        <div v-if="salesTestMessage" class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 whitespace-pre-line">
                            {{ salesTestMessage }}
                        </div>

                        <p v-if="sales.last_sent_date" class="text-xs text-gray-400">
                            Ostatnio wysłano: {{ sales.last_sent_date }}
                        </p>
                    </div>
                </CardContent>
                <CardFooter>
                    <div class="flex justify-end gap-2">
                        <Button color="gray" variant="outline" :loading="salesTesting" @click.prevent="testSales">
                            Wyślij teraz (test)
                        </Button>
                        <Button :loading="salesSaving" @click.prevent="saveSales">Zapisz</Button>
                    </div>
                </CardFooter>
            </Card>
        </div>

        <!-- ════════ TAB: POWIADOMIENIA KSEF ════════ -->
        <div v-show="tab === 'ksef'" class="max-w-2xl">
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Powiadomienia KSeF (Do zapłaty)</h2>
                    <p class="text-sm text-gray-500">
                        Codzienne podsumowanie „do zapłaty dziś" (faktury KSeF) wysyłane na WhatsApp (bramka CallMeBot).
                        To wspólny numer/apikey dla powiadomień — raport sprzedaży z pustymi polami korzysta z tych ustawień.
                    </p>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-medium text-gray-900">Wysyłka włączona</div>
                                <div class="text-xs text-gray-500">Codziennie o {{ ksef.send_time }} (cron).</div>
                            </div>
                            <Toggle v-model="ksef.enabled" />
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Numer odbiorcy (WhatsApp)</label>
                                <input v-model="ksef.phone" placeholder="48723661085"
                                    class="block w-full rounded-md border-gray-300 text-sm font-mono focus:border-primary-500 focus:ring-primary-500" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Godzina wysyłki</label>
                                <input v-model="ksef.send_time" type="time"
                                    class="block w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">CallMeBot apikey</label>
                                <input v-model="ksef.api_key" placeholder="apikey z CallMeBot"
                                    class="block w-full rounded-md border-gray-300 text-sm font-mono focus:border-primary-500 focus:ring-primary-500" />
                                <p class="mt-1 text-xs text-gray-500">
                                    W WhatsApp dodaj numer bota CallMeBot (z <code>callmebot.com → WhatsApp API</code>) i wyślij „I allow callmebot to send me messages" — w odpowiedzi przyjdzie apikey.
                                    Polskie znaki są zamieniane przy wysyłce.
                                </p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Treść wiadomości</label>
                            <textarea v-model="ksef.template" rows="4"
                                class="block w-full rounded-md border-gray-300 text-sm font-mono focus:border-primary-500 focus:ring-primary-500"></textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Placeholdery: <code>{pareto}</code>, <code>{bsp}</code>, <code>{data}</code> (kwoty na dziś i data);
                                <code>{przeterminowane}</code> (lista: „- dni / kontrahent / kwota"), <code>{przeterminowane_razem}</code> (suma opóźnionych).
                            </p>
                        </div>

                        <div v-if="ksefTestMessage" class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 whitespace-pre-line">
                            {{ ksefTestMessage }}
                        </div>

                        <p v-if="ksef.last_sent_date" class="text-xs text-gray-400">
                            Ostatnio wysłano: {{ ksef.last_sent_date }}
                        </p>
                    </div>
                </CardContent>
                <CardFooter>
                    <div class="flex justify-end gap-2">
                        <Button color="gray" variant="outline" :loading="ksefTesting" @click.prevent="testKsef">
                            Wyślij teraz (test)
                        </Button>
                        <Button :loading="ksefSaving" @click.prevent="saveKsef">Zapisz</Button>
                    </div>
                </CardFooter>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { reactive, ref } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Card, CardHeader, CardContent, CardFooter, Toggle } from "crafter/Components";

interface ReportSettings {
    enabled: boolean;
    template: string;
    send_time: string;
    phone: string | null;
    api_key: string | null;
    last_sent_date: string | null;
}

interface Props {
    sales: ReportSettings;
    ksef: ReportSettings;
}

const props = defineProps<Props>();
const toast = useToast();

const tab = ref<string>("sales");
const activeTab = "border-b-2 border-primary-500 px-1 py-3 text-sm font-medium text-primary-600";
const idleTab = "border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300";

// ── Raport sprzedaży ──
const sales = reactive({
    enabled: props.sales.enabled,
    template: props.sales.template ?? "",
    send_time: props.sales.send_time ?? "20:00",
    phone: props.sales.phone ?? "",
    api_key: props.sales.api_key ?? "",
    last_sent_date: props.sales.last_sent_date,
});
const salesSaving = ref(false);
const salesTesting = ref(false);
const salesTestMessage = ref("");

function saveSales() {
    salesSaving.value = true;
    router.put(route("crafter.connect.chatbot.sales.update"), {
        enabled: sales.enabled,
        template: sales.template || null,
        send_time: sales.send_time,
        phone: sales.phone || null,
        api_key: sales.api_key || null,
    }, {
        preserveScroll: true,
        onSuccess: () => toast.success("Raport sprzedaży zapisany."),
        onError: (errors: Record<string, string>) => {
            const first = Object.values(errors)[0];
            if (first) toast.error(first);
        },
        onFinish: () => { salesSaving.value = false; },
    });
}

async function testSales() {
    salesTesting.value = true;
    salesTestMessage.value = "";
    try {
        const { data } = await axios.post(route("crafter.connect.chatbot.sales.test"), {
            template: sales.template || null,
            phone: sales.phone || null,
            api_key: sales.api_key || null,
        });
        salesTestMessage.value = data.message || "";
        if (data.ok) {
            toast.success("Wysłano raport (test).");
        } else {
            toast.error("WhatsApp: " + (data.error || "błąd wysyłki"));
        }
    } catch {
        toast.error("Nie udało się wykonać testu.");
    } finally {
        salesTesting.value = false;
    }
}

// ── Powiadomienia KSeF (te same trasy co dawniej na stronie KSeF) ──
const ksef = reactive({
    enabled: props.ksef.enabled,
    template: props.ksef.template ?? "",
    send_time: props.ksef.send_time ?? "07:00",
    phone: props.ksef.phone ?? "",
    api_key: props.ksef.api_key ?? "",
    last_sent_date: props.ksef.last_sent_date,
});
const ksefSaving = ref(false);
const ksefTesting = ref(false);
const ksefTestMessage = ref("");

function saveKsef() {
    ksefSaving.value = true;
    router.put(route("crafter.ksef.signal.update"), {
        enabled: ksef.enabled,
        phone: ksef.phone || null,
        api_key: ksef.api_key || null,
        template: ksef.template || null,
        send_time: ksef.send_time,
    }, {
        preserveScroll: true,
        onSuccess: () => toast.success("Powiadomienia KSeF zapisane."),
        onError: (errors: Record<string, string>) => {
            const first = Object.values(errors)[0];
            if (first) toast.error(first);
        },
        onFinish: () => { ksefSaving.value = false; },
    });
}

async function testKsef() {
    ksefTesting.value = true;
    ksefTestMessage.value = "";
    try {
        const { data } = await axios.post(route("crafter.ksef.signal.test"), {
            phone: ksef.phone || null,
            api_key: ksef.api_key || null,
            template: ksef.template || null,
        });
        ksefTestMessage.value = data.message || "";
        if (data.ok) {
            toast.success("Wysłano testową wiadomość KSeF.");
        } else {
            toast.error("WhatsApp: " + (data.error || "błąd wysyłki"));
        }
    } catch {
        toast.error("Nie udało się wykonać testu.");
    } finally {
        ksefTesting.value = false;
    }
}
</script>
