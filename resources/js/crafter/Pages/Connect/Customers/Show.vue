<template>
    <PageHeader :title="customer.display_name || 'Klient'">
        <template #subtitle>
            <span class="text-sm text-gray-500 flex items-center gap-2">
                <img
                    v-if="customer.country_code"
                    :src="flagUrl(customer.country_code)"
                    :alt="customer.country_code"
                    class="w-6 h-4 rounded-sm object-cover shadow-sm ring-1 ring-black/5"
                />
                <span>{{ customer.country || customer.country_code || '—' }}</span>
                <span v-if="customer.primary_source" class="text-gray-400">·</span>
                <span v-if="customer.primary_source">{{ customer.primary_source }}</span>
            </span>
        </template>

        <Link
            :href="route('crafter.connect.customers.index')"
            class="px-3 py-1.5 rounded border border-gray-300 text-sm hover:bg-gray-50"
        >
            ← Lista klientów
        </Link>
    </PageHeader>

    <PageContent fluid>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- LEWA -->
            <div class="lg:col-span-2 space-y-4">
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <h2 class="text-base font-semibold">Dane klienta</h2>
                            <span class="text-xs text-gray-400">ID: {{ customer.id }}</span>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-start gap-4">
                            <div
                                class="w-14 h-14 rounded-full flex items-center justify-center text-white font-semibold text-lg shrink-0"
                                :style="{ backgroundColor: avatarColor(customer.display_name || customer.email) }"
                            >
                                {{ initials(customer.display_name || customer.email) }}
                            </div>
                            <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                                <InfoRow label="Imię">{{ customer.first_name || '—' }}</InfoRow>
                                <InfoRow label="Nazwisko">{{ customer.last_name || '—' }}</InfoRow>
                                <InfoRow label="E-mail">
                                    <a v-if="customer.email" :href="`mailto:${customer.email}`" class="text-primary-600 hover:underline">
                                        {{ customer.email }}
                                    </a>
                                    <span v-else>—</span>
                                </InfoRow>
                                <InfoRow label="Telefon">
                                    <a v-if="customer.phone" :href="`tel:${customer.phone}`" class="text-primary-600 hover:underline font-mono">
                                        {{ customer.phone }}
                                    </a>
                                    <span v-else>—</span>
                                </InfoRow>
                                <InfoRow label="Telefon (oryg.)">
                                    <span class="font-mono">{{ customer.phone_raw || '—' }}</span>
                                </InfoRow>
                                <InfoRow label="Login BL">{{ customer.user_login || '—' }}</InfoRow>
                                <InfoRow label="CRM ID">{{ customer.crm_client_id || '—' }}</InfoRow>
                                <InfoRow label="Źródło">{{ customer.primary_source || '—' }}</InfoRow>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="customer.company || customer.nip">
                    <CardHeader>
                        <h3 class="text-sm font-semibold">Firma</h3>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                            <InfoRow label="Firma">{{ customer.company || '—' }}</InfoRow>
                            <InfoRow label="NIP">{{ customer.nip || '—' }}</InfoRow>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <h3 class="text-sm font-semibold">Adres</h3>
                    </CardHeader>
                    <CardContent>
                        <div class="text-sm space-y-0.5">
                            <div v-if="customer.address">{{ customer.address }}</div>
                            <div v-if="customer.postcode || customer.city" class="text-gray-700">
                                {{ customer.postcode }} {{ customer.city }}
                            </div>
                            <div v-if="customer.state" class="text-gray-500">{{ customer.state }}</div>
                            <div class="text-gray-500 text-xs flex items-center gap-1 pt-1">
                                <img
                                    v-if="customer.country_code"
                                    :src="flagUrl(customer.country_code)"
                                    :alt="customer.country_code"
                                    class="w-5 h-3.5 rounded-sm object-cover shadow-sm ring-1 ring-black/5"
                                />
                                <span>{{ customer.country || '—' }}</span>
                                <span v-if="customer.country_code" class="font-mono">({{ customer.country_code }})</span>
                            </div>
                            <div v-if="!customer.address && !customer.city" class="text-gray-400">—</div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold">Historia zamówień</h3>
                            <span class="text-xs text-gray-500">{{ orders.length }} zamówień</span>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div v-if="orders.length === 0" class="text-sm text-gray-400">
                            Brak zamówień.
                        </div>
                        <table v-else class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 border-b">
                                    <th class="py-2 font-medium">Nr</th>
                                    <th class="py-2 font-medium">Data</th>
                                    <th class="py-2 font-medium">Źródło</th>
                                    <th class="py-2 font-medium text-center">Poz.</th>
                                    <th class="py-2 font-medium text-right">Kwota</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="o in orders"
                                    :key="o.id"
                                    class="border-b last:border-0 hover:bg-gray-50"
                                >
                                    <td class="py-2 font-mono text-primary-600">
                                        <Link :href="route('crafter.connect.orders.show', o.id)" class="hover:underline">
                                            #{{ o.baselinker_order_id }}
                                        </Link>
                                    </td>
                                    <td class="py-2 text-gray-600">{{ formatDate(o.date_add) }}</td>
                                    <td class="py-2 text-gray-600">{{ o.order_source || '—' }}</td>
                                    <td class="py-2 text-center">{{ o.products_count }}</td>
                                    <td class="py-2 text-right font-semibold">
                                        {{ formatMoney(o.total_amount, o.currency) }}
                                    </td>
                                    <td class="py-2 text-right">
                                        <span
                                            v-if="o.payment_done >= o.total_amount"
                                            class="text-xs text-green-600"
                                        >opłacone</span>
                                        <span v-else class="text-xs text-red-600">brak</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>

            <!-- PRAWA -->
            <div class="space-y-4">
                <Card>
                    <CardHeader>
                        <h3 class="text-sm font-semibold">Statystyki</h3>
                    </CardHeader>
                    <CardContent>
                        <dl class="text-sm space-y-3">
                            <div>
                                <dt class="text-xs text-gray-500">Liczba zamówień</dt>
                                <dd class="text-2xl font-semibold text-primary-700">{{ customer.orders_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Pierwsze zamówienie</dt>
                                <dd>{{ formatDate(customer.first_order_at) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Ostatnie zamówienie</dt>
                                <dd>{{ formatDate(customer.last_order_at) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Klient od</dt>
                                <dd>{{ formatDate(customer.created_at) }}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card v-if="spendingByCurrency.length">
                    <CardHeader>
                        <h3 class="text-sm font-semibold">Wydatki</h3>
                    </CardHeader>
                    <CardContent>
                        <ul class="text-sm space-y-2">
                            <li
                                v-for="s in spendingByCurrency"
                                :key="s.currency"
                                class="flex justify-between items-baseline"
                            >
                                <div>
                                    <div class="font-semibold">{{ formatMoney(s.total, s.currency) }}</div>
                                    <div class="text-xs text-gray-500">{{ s.orders }} zam.</div>
                                </div>
                                <span class="text-xs font-mono text-gray-400">{{ s.currency }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card v-if="customer.sources && customer.sources.length">
                    <CardHeader>
                        <h3 class="text-sm font-semibold">Wszystkie źródła</h3>
                    </CardHeader>
                    <CardContent>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="src in customer.sources"
                                :key="src"
                                class="inline-flex items-center px-2 py-0.5 rounded bg-gray-100 text-xs text-gray-700"
                            >
                                {{ src }}
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { h } from "vue";
import { Link } from "@inertiajs/vue3";
import {
    PageHeader,
    PageContent,
    Card,
    CardHeader,
    CardContent,
} from "crafter/Components";

interface Customer {
    id: number;
    full_name: string | null;
    first_name: string | null;
    last_name: string | null;
    display_name: string;
    email: string | null;
    phone: string | null;
    phone_raw: string | null;
    user_login: string | null;
    crm_client_id: number | null;
    company: string | null;
    nip: string | null;
    address: string | null;
    postcode: string | null;
    city: string | null;
    state: string | null;
    country: string | null;
    country_code: string | null;
    primary_source: string | null;
    sources: string[];
    orders_count: number;
    first_order_at: string | null;
    last_order_at: string | null;
    created_at: string | null;
}

interface OrderRow {
    id: number;
    baselinker_order_id: number;
    order_source: string | null;
    order_status_id: number;
    date_add: string | null;
    total_amount: number;
    payment_done: number;
    currency: string | null;
    products_count: number;
}

interface SpendingRow {
    currency: string;
    total: number;
    orders: number;
}

interface Props {
    customer: Customer;
    orders: OrderRow[];
    spendingByCurrency: SpendingRow[];
}

defineProps<Props>();

function flagUrl(code: string | null): string {
    if (!code || code.length !== 2) return "";
    return `https://flagcdn.com/w40/${code.toLowerCase()}.png`;
}

function formatDate(iso: string | null): string {
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

function initials(value: string | null): string {
    if (!value) return "??";
    return value
        .split(/[\s@.]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((s) => s[0]!.toUpperCase())
        .join("");
}

function avatarColor(value: string | null): string {
    if (!value) return "#64748b";
    let hash = 0;
    for (const ch of value) hash = ch.charCodeAt(0) + ((hash << 5) - hash);
    const palette = [
        "#ef4444", "#f97316", "#f59e0b", "#10b981",
        "#14b8a6", "#06b6d4", "#3b82f6", "#6366f1",
        "#8b5cf6", "#ec4899",
    ];
    return palette[Math.abs(hash) % palette.length];
}

const InfoRow = (props: any, { slots }: any) =>
    h("div", { class: "flex gap-2" }, [
        h("dt", { class: "text-gray-500 min-w-[110px]" }, props.label + ":"),
        h("dd", { class: "text-gray-900 font-medium" }, slots.default?.()),
    ]);
(InfoRow as any).props = ["label"];
</script>
