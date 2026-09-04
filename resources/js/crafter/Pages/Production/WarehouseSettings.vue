<template>
    <PageHeader sticky title="Magazyn — Ustawienia" subtitle="Połączenie ze źródłami stanów" />

    <PageContent>
        <div class="w-full">
            <Card>
                <!-- Jedna zakladka; pasek zostaje, bo dojdzie arkusz Google. -->
                <div class="mb-4 border-b border-gray-200">
                    <nav class="-mb-px flex items-center gap-6">
                        <button type="button" :class="tabClass(true)">
                            Argo Bridge
                            <!-- Kropka przy nazwie zakladki, nie tylko w karcie:
                                 stan polaczenia ma byc widoczny od wejscia. -->
                            <span
                                class="ml-2 inline-block h-2 w-2 rounded-full align-middle"
                                :style="{ background: state.dot }"
                                :title="state.label"
                            />
                        </button>
                    </nav>
                </div>

                <!-- === STAN POLACZENIA === -->
                <div
                    class="mb-6 flex flex-wrap items-start justify-between gap-4 rounded-md border px-4 py-3"
                    :class="state.box"
                >
                    <div class="flex items-start gap-3">
                        <component :is="state.icon" class="mt-0.5 h-6 w-6 flex-none" :style="{ color: state.dot }" />
                        <div>
                            <div class="text-sm font-medium" :style="{ color: state.dot }">
                                {{ state.label }}
                            </div>
                            <div class="mt-1 text-sm text-gray-600">{{ state.hint }}</div>
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                        <dt class="text-gray-500">Ostatni sygnał</dt>
                        <dd class="text-gray-900">
                            {{ bridge.last_seen_at ?? "—" }}
                            <span v-if="bridge.last_seen_human" class="text-gray-400">
                                ({{ bridge.last_seen_human }})
                            </span>
                        </dd>
                        <dt class="text-gray-500">Ostatnia paczka stanów</dt>
                        <dd class="text-gray-900">
                            {{ bridge.last_sync_at ?? "—" }}
                            <span v-if="bridge.last_codes" class="text-gray-400">
                                ({{ bridge.last_codes }} kodów)
                            </span>
                        </dd>
                        <dt class="text-gray-500">Wersja Bridge'a</dt>
                        <dd class="text-gray-900">{{ bridge.version ?? "—" }}</dd>
                    </dl>
                </div>

                <!-- === USTAWIENIA === -->
                <div class="max-w-3xl space-y-6">
                    <label class="flex items-start justify-between gap-6 border-b border-gray-100 pb-4">
                        <span>
                            <span class="block text-sm font-medium text-gray-900">Połączenie aktywne</span>
                            <span class="mt-1 block text-sm text-gray-500">
                                Wyłączone = paczki z Bridge'a są odrzucane, nawet z poprawnym
                                tokenem. Token zostaje, więc ponowne włączenie nie wymaga
                                zmian po tamtej stronie.
                            </span>
                        </span>
                        <Toggle v-model="form.enabled" name="bridge_enabled" class="mt-1 flex-none" />
                    </label>

                    <div>
                        <TextInput
                            v-model="form.warehouse_symbol"
                            name="warehouse_symbol"
                            label="Symbol magazynu w Subiekcie GT"
                            placeholder="np. MAG-M3R"
                            clearable
                        />
                        <p class="mt-1 text-sm text-gray-500">
                            Ten magazyn czytamy jako „M3R". Symbol wpisz dokładnie tak, jak
                            widnieje w GT — Bridge pobiera go stąd, więc zmiana dociera do
                            niego sama, bez ruszania konfiguracji na tamtej maszynie.
                        </p>
                    </div>

                    <div class="flex justify-end">
                        <Button :loading="saving" @click="save">Zapisz ustawienia</Button>
                    </div>
                </div>

                <!-- === TOKEN I ADRES === -->
                <div class="mt-8 max-w-3xl rounded-md border border-gray-200 bg-gray-50 p-4">
                    <h3 class="text-sm font-medium text-gray-900">Dane do wklejenia w Bridge</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Bridge sam się do nas zgłasza — PIM stoi publicznie, Subiekt siedzi
                        za NAT-em, więc ruch idzie tylko w tę stronę. Poniższe dwie rzeczy
                        wpisuje się po stronie Bridge'a.
                    </p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Adres zgłoszenia
                            </div>
                            <div class="mt-1 flex items-center gap-2">
                                <code class="flex-1 truncate rounded border border-gray-200 bg-white px-3 py-2 text-sm">
                                    {{ bridge.ping_url }}
                                </code>
                                <Button variant="outline" color="gray" @click="copy(bridge.ping_url)">
                                    Kopiuj
                                </Button>
                            </div>
                        </div>

                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                Token (nagłówek <code>X-Argo-Token</code>)
                            </div>
                            <div class="mt-1 flex items-center gap-2">
                                <code
                                    class="flex-1 truncate rounded border border-gray-200 bg-white px-3 py-2 text-sm"
                                    :class="{ 'text-gray-400': !bridge.token }"
                                >
                                    {{ bridge.token ?? "jeszcze nie wygenerowany" }}
                                </code>
                                <Button
                                    v-if="bridge.token"
                                    variant="outline"
                                    color="gray"
                                    @click="copy(bridge.token)"
                                >
                                    Kopiuj
                                </Button>
                                <Button
                                    variant="outline"
                                    color="gray"
                                    :loading="tokenLoading"
                                    @click="regenerate"
                                >
                                    {{ bridge.token ? "Generuj nowy" : "Generuj token" }}
                                </Button>
                            </div>
                            <p v-if="bridge.token" class="mt-1 text-sm text-amber-700">
                                Wygenerowanie nowego unieważnia poprzedni — Bridge ze starym
                                tokenem dostanie 401, dopóki nie wkleisz nowego.
                            </p>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { useToast } from "@brackets/vue-toastification";
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    NoSymbolIcon,
    SignalSlashIcon,
} from "@heroicons/vue/24/outline";
import { Button, Card, PageContent, PageHeader, TextInput, Toggle } from "crafter/Components";

interface Bridge {
    enabled: boolean;
    warehouse_symbol: string | null;
    token: string | null;
    status: "unconfigured" | "off" | "never" | "silent" | "connected";
    last_seen_at: string | null;
    last_seen_human: string | null;
    last_sync_at: string | null;
    last_codes: number | null;
    version: string | null;
    silent_after_minutes: number;
    ping_url: string;
}

const props = defineProps<{ bridge: Bridge }>();
const toast = useToast();

const bridge = computed<Bridge>(() => props.bridge);

const form = reactive({
    enabled: props.bridge.enabled,
    warehouse_symbol: props.bridge.warehouse_symbol ?? "",
});

const saving = ref(false);
const tokenLoading = ref(false);

function tabClass(active: boolean): string {
    return [
        "border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap",
        active
            ? "border-primary-500 text-primary-600"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}

// Stan liczy serwer (ten sam kod pilnuje API), front tylko go ubiera. Kazdy
// stan ma wlasna ikone, a nie sama kropke — kolor bez ksztaltu przepada
// przy daltonizmie i na slabym monitorze.
const STATES: Record<Bridge["status"], { label: string; hint: string; dot: string; box: string; icon: any }> = {
    connected: {
        label: "Połączony i aktywny",
        hint: "Bridge zgłosił się niedawno i połączenie jest włączone.",
        dot: "#16a34a",
        box: "border-green-200 bg-green-50",
        icon: CheckCircleIcon,
    },
    silent: {
        label: "Cisza",
        hint: "Połączenie jest włączone, ale Bridge dawno się nie odezwał — sprawdź, czy program na tamtej maszynie działa.",
        dot: "#d97706",
        box: "border-amber-200 bg-amber-50",
        icon: ExclamationTriangleIcon,
    },
    never: {
        label: "Jeszcze się nie zgłosił",
        hint: "Token jest, połączenie włączone — czekamy na pierwszy sygnał z maszyny z Subiektem.",
        dot: "#6b7280",
        box: "border-gray-200 bg-gray-50",
        icon: SignalSlashIcon,
    },
    off: {
        label: "Wyłączony",
        hint: "Token istnieje, ale połączenie jest zgaszone — paczki będą odrzucane.",
        dot: "#6b7280",
        box: "border-gray-200 bg-gray-50",
        icon: NoSymbolIcon,
    },
    unconfigured: {
        label: "Nieskonfigurowany",
        hint: "Nie ma tokenu, więc nikt się nie zaloguje. Wygeneruj token i wklej go w Bridge.",
        dot: "#6b7280",
        box: "border-gray-200 bg-gray-50",
        icon: SignalSlashIcon,
    },
};

const state = computed(() => STATES[bridge.value.status] ?? STATES.unconfigured);

function save(): void {
    saving.value = true;
    router.put(
        route("crafter.production.warehouse.bridge.update"),
        { enabled: form.enabled, warehouse_symbol: form.warehouse_symbol || null },
        {
            preserveScroll: true,
            onSuccess: () => toast.success("Zapisano ustawienia Bridge'a"),
            onError: () => toast.error("Nie udało się zapisać ustawień"),
            onFinish: () => (saving.value = false),
        }
    );
}

function regenerate(): void {
    if (bridge.value.token && !window.confirm("Nowy token unieważni poprzedni. Bridge przestanie się łączyć, dopóki nie wkleisz nowego. Generować?")) {
        return;
    }

    tokenLoading.value = true;
    router.post(
        route("crafter.production.warehouse.bridge.token"),
        {},
        {
            preserveScroll: true,
            onSuccess: () => toast.success("Nowy token wygenerowany"),
            onError: () => toast.error("Nie udało się wygenerować tokenu"),
            onFinish: () => (tokenLoading.value = false),
        }
    );
}

/**
 * Kopiowanie z fallbackiem: `navigator.clipboard` istnieje tylko w bezpiecznym
 * kontekscie, a PIM bywa otwierany po http — bez tego przycisk milczalby.
 */
async function copy(text: string | null): Promise<void> {
    if (!text) return;

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const area = document.createElement("textarea");
            area.value = text;
            area.style.position = "fixed";
            area.style.opacity = "0";
            document.body.appendChild(area);
            area.select();
            document.execCommand("copy");
            document.body.removeChild(area);
        }
        toast.success("Skopiowane");
    } catch (e) {
        toast.error("Nie udało się skopiować — zaznacz i skopiuj ręcznie");
    }
}
</script>
