<template>
    <PageHeader :title="`Zamówienie #${order.baselinker_order_id}`">
        <template #subtitle>
            <span class="text-sm text-gray-500">{{ formatDate(order.date_add) }}</span>
        </template>

        <div class="flex items-center gap-2">
            <Link
                v-if="navigation.prev_id"
                :href="route('crafter.connect.orders.show', navigation.prev_id)"
                class="p-2 rounded hover:bg-gray-100"
                title="Poprzednie"
            >
                <ChevronLeftIcon class="w-5 h-5" />
            </Link>
            <Link
                v-if="navigation.next_id"
                :href="route('crafter.connect.orders.show', navigation.next_id)"
                class="p-2 rounded hover:bg-gray-100"
                title="Następne"
            >
                <ChevronRightIcon class="w-5 h-5" />
            </Link>

            <Button
                variant="outline"
                color="gray"
                :leftIcon="ArrowPathIcon"
                @click="refreshFromApi"
                :loading="refreshing"
            >
                Odśwież z BaseLinker
            </Button>
        </div>
    </PageHeader>

    <PageContent fluid>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- LEWA KOLUMNA -->
            <div class="lg:col-span-2 space-y-4">
                <!-- Informacje o zamówieniu -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <h2 class="text-base font-semibold">Informacje o zamówieniu</h2>
                            <span
                                v-if="order.status_name"
                                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium"
                                :style="statusStyle(order.status_color)"
                            >
                                {{ order.status_name }}
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                            <InfoRow label="BaseLinker ID">#{{ order.baselinker_order_id }}</InfoRow>
                            <InfoRow label="Zewn. ID">{{ order.external_order_id || '—' }}</InfoRow>
                            <InfoRow label="Źródło">{{ displaySource }}</InfoRow>
                            <InfoRow label="Login">{{ order.user_login || '—' }}</InfoRow>
                            <InfoRow label="Email">
                                <a v-if="order.email" :href="`mailto:${order.email}`" class="text-primary-600 hover:underline">
                                    {{ order.email }}
                                </a>
                                <span v-else>—</span>
                            </InfoRow>
                            <InfoRow label="Telefon">{{ order.phone || '—' }}</InfoRow>

                            <InfoRow label="Data dodania">{{ formatDate(order.date_add) }}</InfoRow>
                            <InfoRow label="Data potwierdzenia">{{ formatDate(order.date_confirmed) }}</InfoRow>

                            <InfoRow label="Metoda płatności">
                                {{ order.payment_method || '—' }}
                                <span v-if="order.payment_method_cod" class="ml-1 text-xs text-orange-600">(pobranie)</span>
                            </InfoRow>
                            <InfoRow label="Zapłacono">
                                <span class="font-semibold">{{ formatMoney(order.payment_done, order.currency) }}</span>
                                <span class="text-gray-400"> / {{ formatMoney(order.total_amount, order.currency) }}</span>
                            </InfoRow>
                        </div>
                    </CardContent>
                </Card>

                <!-- Adresy -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Adres dostawy -->
                    <Card>
                        <CardHeader>
                            <h3 class="text-sm font-semibold">Adres dostawy</h3>
                        </CardHeader>
                        <CardContent>
                            <AddressBlock
                                :fullname="order.delivery.fullname"
                                :company="order.delivery.company"
                                :address="order.delivery.address"
                                :postcode="order.delivery.postcode"
                                :city="order.delivery.city"
                                :country="order.delivery.country"
                                :country_code="order.delivery.country_code"
                            />
                        </CardContent>
                    </Card>

                    <!-- Dane do faktury -->
                    <Card>
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold">Dane do faktury</h3>
                                <span
                                    v-if="order.invoice.want_invoice"
                                    class="text-[10px] px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700"
                                >
                                    WYMAGANA
                                </span>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div v-if="!hasInvoiceData" class="text-sm text-gray-400">—</div>
                            <div v-else>
                                <AddressBlock
                                    :fullname="order.invoice.fullname"
                                    :company="order.invoice.company"
                                    :address="order.invoice.address"
                                    :postcode="order.invoice.postcode"
                                    :city="order.invoice.city"
                                    :country="order.invoice.country"
                                    :country_code="order.invoice.country_code"
                                />
                                <div v-if="order.invoice.nip" class="mt-2 text-sm">
                                    <span class="text-gray-500">NIP:</span>
                                    <span class="font-mono">{{ order.invoice.nip }}</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Odbiór w punkcie -->
                    <Card>
                        <CardHeader>
                            <h3 class="text-sm font-semibold">Odbiór w punkcie</h3>
                        </CardHeader>
                        <CardContent>
                            <div v-if="!order.delivery.point_id" class="text-sm text-gray-400">Brak</div>
                            <div v-else class="text-sm space-y-1">
                                <div class="font-medium">{{ order.delivery.point_name }}</div>
                                <div class="text-gray-600">{{ order.delivery.point_address }}</div>
                                <div class="text-gray-600">
                                    {{ order.delivery.point_postcode }} {{ order.delivery.point_city }}
                                </div>
                                <div class="text-xs text-gray-400 font-mono mt-1">
                                    ID: {{ order.delivery.point_id }}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Produkty -->
                <Card>
                    <CardHeader>
                        <h2 class="text-base font-semibold">Produkty ({{ order.products.length }})</h2>
                    </CardHeader>
                    <CardContent class="!p-0">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Produkt</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">SKU / EAN</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cena</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">VAT</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Ilość</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Suma</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="p in order.products" :key="p.id">
                                    <td class="px-4 py-3">
                                        <div class="flex items-start gap-3">
                                            <div class="shrink-0 w-12 h-12 rounded border border-gray-200 bg-white overflow-hidden flex items-center justify-center">
                                                <img
                                                    v-if="p.thumbnail"
                                                    :src="p.thumbnail"
                                                    :alt="p.name"
                                                    class="w-full h-full object-contain"
                                                    loading="lazy"
                                                />
                                                <PhotoIcon v-else class="w-5 h-5 text-gray-300" />
                                            </div>
                                            <div class="min-w-0">
                                                <div class="font-medium text-gray-900">{{ p.name }}</div>
                                                <div v-if="p.attributes" class="text-xs text-gray-500">{{ p.attributes }}</div>
                                                <div v-if="p.location" class="text-xs text-gray-400 font-mono">
                                                    📍 {{ p.location }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">
                                        <div>{{ p.sku || '—' }}</div>
                                        <div v-if="p.ean" class="text-gray-400">{{ p.ean }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        {{ formatMoney(p.price_brutto, order.currency) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500">
                                        {{ p.tax_rate }}%
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium">{{ p.quantity }}</td>
                                    <td class="px-4 py-3 text-right font-semibold">
                                        {{ formatMoney(p.line_total, order.currency) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="5" class="px-4 py-2 text-right text-sm text-gray-500">Produkty razem:</td>
                                    <td class="px-4 py-2 text-right font-medium">
                                        {{ formatMoney(productsTotal, order.currency) }}
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="5" class="px-4 py-2 text-right text-sm text-gray-500">
                                        Dostawa ({{ order.delivery.method || '—' }}):
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        {{ formatMoney(order.delivery.price, order.currency) }}
                                    </td>
                                </tr>
                                <tr class="border-t-2">
                                    <td colspan="5" class="px-4 py-2 text-right font-semibold">RAZEM:</td>
                                    <td class="px-4 py-2 text-right font-bold text-lg">
                                        {{ formatMoney(order.total_amount, order.currency) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </CardContent>
                </Card>

                <!-- Przesyłki -->
                <Card v-if="order.delivery.package_nr">
                    <CardHeader>
                        <h2 class="text-base font-semibold">Przesyłka</h2>
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm text-gray-500">{{ order.delivery.package_module || 'Kurier' }}</div>
                                <div class="font-mono text-sm font-medium mt-0.5">{{ order.delivery.package_nr }}</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Komentarze -->
                <Card v-if="order.user_comments || order.admin_comments">
                    <CardHeader>
                        <h2 class="text-base font-semibold">Komentarze</h2>
                    </CardHeader>
                    <CardContent>
                        <div v-if="order.user_comments" class="mb-3">
                            <div class="text-xs text-gray-500 mb-1">Klient:</div>
                            <div class="text-sm bg-gray-50 rounded p-2 whitespace-pre-wrap">
                                {{ order.user_comments }}
                            </div>
                        </div>
                        <div v-if="order.admin_comments">
                            <div class="text-xs text-gray-500 mb-1">Sprzedawca:</div>
                            <div class="text-sm bg-yellow-50 rounded p-2 whitespace-pre-wrap">
                                {{ order.admin_comments }}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Pola dodatkowe -->
                <Card v-if="order.extra_field_1 || order.extra_field_2 || hasCustomFields">
                    <CardHeader>
                        <h2 class="text-base font-semibold">Pola dodatkowe</h2>
                    </CardHeader>
                    <CardContent>
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <template v-if="order.extra_field_1">
                                <dt class="text-gray-500">Extra 1:</dt>
                                <dd>{{ order.extra_field_1 }}</dd>
                            </template>
                            <template v-if="order.extra_field_2">
                                <dt class="text-gray-500">Extra 2:</dt>
                                <dd>{{ order.extra_field_2 }}</dd>
                            </template>
                            <template v-for="(v, k) in (order.custom_extra_fields || {})" :key="k">
                                <dt class="text-gray-500">Pole #{{ k }}:</dt>
                                <dd>{{ v }}</dd>
                            </template>
                        </dl>
                    </CardContent>
                </Card>
            </div>

            <!-- PRAWA KOLUMNA -->
            <div class="space-y-4">
                <!-- Faktury i korekty -->
                <Card>
                    <CardHeader>
                        <h3 class="text-sm font-semibold">Faktury i korekty</h3>
                    </CardHeader>
                    <CardContent>
                        <div v-if="invoices.length === 0" class="text-sm text-gray-400">
                            <span v-if="order.invoice.want_invoice">Wymagana, brak wystawionej.</span>
                            <span v-else>—</span>
                        </div>
                        <ul v-else class="space-y-2">
                            <li
                                v-for="inv in invoices"
                                :key="inv.id"
                                class="flex items-start gap-2 text-sm"
                            >
                                <span
                                    class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide"
                                    :class="inv.type === 'correction'
                                        ? 'bg-orange-100 text-orange-700'
                                        : 'bg-blue-100 text-blue-700'"
                                >
                                    {{ inv.type === 'correction' ? 'Korekta' : 'Faktura' }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <div class="font-mono font-medium break-all">{{ inv.nr_full }}</div>
                                    <div class="text-xs text-gray-500">
                                        <span>Utworzono: {{ inv.issue_date ? formatDateShort(inv.issue_date) : '—' }}</span>
                                        <span v-if="inv.total_brutto"> · {{ formatMoney(inv.total_brutto, inv.currency) }}</span>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <h3 class="text-sm font-semibold">Flagi</h3>
                    </CardHeader>
                    <CardContent>
                        <ul class="text-sm space-y-2">
                            <FlagRow :on="order.confirmed" label="Potwierdzone" />
                            <FlagRow :on="!!order.pick_state" label="Skompletowane" />
                            <FlagRow :on="!!order.pack_state" label="Spakowane" />
                            <FlagRow :on="order.invoice.want_invoice" label="Faktura wymagana" />
                            <FlagRow :on="order.payment_method_cod" label="Za pobraniem" />
                            <li v-if="order.star" class="flex items-center gap-2">
                                <StarIcon class="w-4 h-4 text-yellow-500" />
                                <span>Gwiazdka: {{ order.star }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <h3 class="text-sm font-semibold">Informacje dodatkowe</h3>
                    </CardHeader>
                    <CardContent>
                        <dl class="text-xs space-y-2">
                            <div>
                                <dt class="text-gray-500">Waluta</dt>
                                <dd class="font-mono">{{ order.currency || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Zaimportowane</dt>
                                <dd>{{ formatDate(order.imported_at) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Ostatnia aktualizacja z API</dt>
                                <dd>{{ formatDate(order.updated_from_api_at) }}</dd>
                            </div>
                            <div v-if="order.order_page">
                                <dt class="text-gray-500">Strona zamówienia</dt>
                                <dd>
                                    <a :href="order.order_page" target="_blank" class="text-primary-600 hover:underline break-all">
                                        {{ order.order_page }}
                                    </a>
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card v-if="order.commission">
                    <CardHeader>
                        <h3 class="text-sm font-semibold">Prowizja marketplace</h3>
                    </CardHeader>
                    <CardContent>
                        <pre class="text-xs">{{ JSON.stringify(order.commission, null, 2) }}</pre>
                    </CardContent>
                </Card>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, ref, h } from "vue";
import { Link, router } from "@inertiajs/vue3";
import axios from "axios";
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    ArrowPathIcon,
    StarIcon,
    CheckCircleIcon,
    XCircleIcon,
    PhotoIcon,
} from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import {
    PageHeader,
    PageContent,
    Button,
    Card,
    CardHeader,
    CardContent,
} from "crafter/Components";

interface InvoiceItem {
    id: number;
    baselinker_invoice_id: number;
    type: 'invoice' | 'correction';
    series_name: string | null;
    nr: number | null;
    nr_full: string | null;
    corrected_invoice_id: number | null;
    issue_date: string | null;
    sell_date: string | null;
    payment_date: string | null;
    total_netto: number;
    total_brutto: number;
    currency: string | null;
}

interface Props {
    order: any;
    invoices: InvoiceItem[];
    navigation: { prev_id: number | null; next_id: number | null };
}

const props = withDefaults(defineProps<Props>(), {
    invoices: () => [],
});
const toast = useToast();
const refreshing = ref(false);

const displaySource = computed(() => {
    const info = (props.order.order_source_info ?? '').trim();
    const source = (props.order.order_source ?? '').trim();
    if (info && info !== '-' && info !== '—') return info;
    if (source && source !== '-' && source !== '—') return source;
    return '—';
});

const invoices = computed<InvoiceItem[]>(() => props.invoices ?? []);

const productsTotal = computed(() =>
    props.order.products.reduce((sum: number, p: any) => sum + p.line_total, 0)
);

const hasInvoiceData = computed(() =>
    !!(
        props.order.invoice.fullname ||
        props.order.invoice.company ||
        props.order.invoice.nip ||
        props.order.invoice.address
    )
);

const hasCustomFields = computed(
    () =>
        props.order.custom_extra_fields &&
        Object.keys(props.order.custom_extra_fields).length > 0
);

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    return new Date(iso).toLocaleString("pl-PL", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

function formatDateShort(iso: string | null): string {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString("pl-PL", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    });
}

function formatMoney(amount: number, currency: string | null): string {
    try {
        return new Intl.NumberFormat("pl-PL", {
            style: "currency",
            currency: currency || "PLN",
        }).format(amount);
    } catch {
        return `${(amount || 0).toFixed(2)} ${currency ?? ""}`;
    }
}

function statusStyle(color: string | null) {
    if (!color) return { backgroundColor: "#e5e7eb", color: "#374151" };
    return {
        backgroundColor: color + "22",
        color: color,
        border: `1px solid ${color}66`,
    };
}

async function refreshFromApi() {
    refreshing.value = true;
    try {
        const { data } = await axios.post(
            route("crafter.connect.orders.sync", props.order.id)
        );
        if (data.ok) {
            toast.success("Zamówienie odświeżone z BaseLinker.");
            router.reload();
        } else {
            toast.error(data.message ?? "Nie udało się odświeżyć.");
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd odświeżania.");
    } finally {
        refreshing.value = false;
    }
}

// Komponenty pomocnicze (w tym samym pliku)
const InfoRow = (props: any, { slots }: any) =>
    h("div", { class: "flex gap-2" }, [
        h("dt", { class: "text-gray-500 min-w-[120px]" }, props.label + ":"),
        h("dd", { class: "text-gray-900 font-medium" }, slots.default?.()),
    ]);
(InfoRow as any).props = ["label"];

const AddressBlock = (props: any) =>
    h("div", { class: "text-sm space-y-0.5" }, [
        props.fullname && h("div", { class: "font-medium" }, props.fullname),
        props.company && h("div", {}, props.company),
        props.address && h("div", { class: "text-gray-600" }, props.address),
        (props.postcode || props.city) &&
            h("div", { class: "text-gray-600" }, `${props.postcode || ""} ${props.city || ""}`.trim()),
        props.country &&
            h("div", { class: "text-gray-500 text-xs" }, `${props.country}${props.country_code ? ` (${props.country_code})` : ""}`),
        !props.fullname && !props.address && h("div", { class: "text-gray-400" }, "—"),
    ]);
(AddressBlock as any).props = ["fullname", "company", "address", "postcode", "city", "country", "country_code"];

const FlagRow = (props: any) =>
    h(
        "li",
        { class: "flex items-center gap-2" },
        [
            props.on
                ? h(CheckCircleIcon, { class: "w-4 h-4 text-green-600" })
                : h(XCircleIcon, { class: "w-4 h-4 text-gray-300" }),
            h("span", { class: props.on ? "" : "text-gray-400" }, props.label),
        ]
    );
(FlagRow as any).props = ["on", "label"];
</script>
