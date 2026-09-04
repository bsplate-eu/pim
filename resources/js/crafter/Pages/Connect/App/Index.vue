<template>
    <PageHeader
        sticky
        title="Argo App"
        subtitle="Aplikacja na telefon — Magazyn i Poczta"
    />

    <PageContent>
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <!-- Adres + instalacja -->
            <Card class="lg:col-span-2">
                <CardHeader>
                    <h2 class="text-lg font-semibold">Instalacja na telefonie</h2>
                    <p class="text-sm text-gray-500">
                        Apka nie idzie przez Google Play — to PWA. Otwierasz adres w przeglądarce
                        telefonu i dodajesz do ekranu głównego; potem uruchamia się jak zwykła
                        aplikacja, pełny ekran, z ikoną ARGO.
                    </p>
                </CardHeader>

                <CardContent>
                    <div class="space-y-5">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Adres apki</label>
                            <div class="flex gap-2">
                                <input
                                    :value="appUrl"
                                    readonly
                                    class="block w-full rounded-md border-gray-300 bg-gray-50 font-mono text-sm"
                                />
                                <Button variant="outline" color="gray" @click="copyUrl">
                                    {{ copied ? "Skopiowano" : "Kopiuj" }}
                                </Button>
                                <Button :as="'a'" :href="appUrl" target="_blank" variant="outline" color="gray">
                                    Otwórz
                                </Button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Zaloguj się tym samym kontem co w panelu — apka używa tej samej sesji
                                i tych samych uprawnień.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div class="rounded-lg border border-gray-200 p-4">
                                <div class="mb-2 text-sm font-semibold text-gray-900">Android (Chrome)</div>
                                <ol class="list-decimal space-y-1 pl-4 text-sm text-gray-600">
                                    <li>otwórz adres i zaloguj się</li>
                                    <li>menu <strong>⋮</strong> → „Dodaj do ekranu głównego"</li>
                                    <li>na ekranie startowym zgódź się na powiadomienia</li>
                                </ol>
                            </div>
                            <div class="rounded-lg border border-gray-200 p-4">
                                <div class="mb-2 text-sm font-semibold text-gray-900">iPhone (Safari)</div>
                                <ol class="list-decimal space-y-1 pl-4 text-sm text-gray-600">
                                    <li>otwórz adres i zaloguj się</li>
                                    <li>przycisk udostępniania → „Do ekranu początkowego"</li>
                                    <li>powiadomienia działają dopiero po dodaniu do ekranu</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Stan -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Stan</h2>
                    <p class="text-sm text-gray-500">Co apka pokazuje w tej chwili.</p>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <div
                            v-for="m in modules"
                            :key="m.key"
                            class="rounded-lg border border-gray-200 p-4"
                        >
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="font-semibold text-gray-900">{{ m.name }}</span>
                                <span class="text-sm tabular-nums text-gray-500">
                                    {{ m.count }} {{ m.unit }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-600">{{ m.desc }}</p>
                            <p class="mt-1 text-xs text-gray-400">{{ m.detail }}</p>
                        </div>

                        <div class="rounded-lg bg-gray-50 p-4">
                            <div class="text-sm font-medium text-gray-900">Powiadomienia push</div>
                            <p class="mt-1 text-sm text-gray-600">
                                <template v-if="pushDevices === null">
                                    Web Push nie jest zainstalowany na tym środowisku.
                                </template>
                                <template v-else-if="pushDevices === 0">
                                    Żadne urządzenie nie ma jeszcze włączonych powiadomień.
                                </template>
                                <template v-else>
                                    {{ pushDevices }} {{ pushDevices === 1 ? "urządzenie ma" : "urządzeń ma" }}
                                    włączone powiadomienia.
                                </template>
                            </p>
                            <p class="mt-1 text-xs text-gray-400">
                                To jedyny ślad, że ktoś apkę faktycznie zainstalował — PWA nie
                                melduje się inaczej.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { Card, CardHeader, CardContent, PageContent, PageHeader, Button } from "crafter/Components";

const props = defineProps<{
    appUrl: string;
    modules: Array<{ key: string; name: string; desc: string; count: number; unit: string; detail: string }>;
    pushDevices: number | null;
}>();

const copied = ref(false);

/* http bez TLS nie ma navigator.clipboard — na pim.test lokalnie to normalny
   przypadek, wiec fallback przez ukryte pole, zeby guzik nie byl martwy. */
const copyUrl = async () => {
    try {
        await navigator.clipboard.writeText(props.appUrl);
    } catch (e) {
        const el = document.createElement("textarea");
        el.value = props.appUrl;
        document.body.appendChild(el);
        el.select();
        document.execCommand("copy");
        document.body.removeChild(el);
    }
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
};
</script>
