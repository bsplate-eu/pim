<template>
    <div>
        <nav class="mt-5 space-y-1">
            <!-- Strona główna -->
            <SidebarItem
                :href="route('crafter.dashboard')"
                :icon="HomeIcon"
                v-can="'crafter'"
            >
                {{ $t("crafter", "Dashboard") }}
            </SidebarItem>

            <!-- Argo HQ -->
            <SidebarGroup
                title="Argo HQ"
                :toggable="true"
                :open="false"
                :icon="BuildingOffice2Icon"
                v-can:any="[
                    'crafter.module.costs',
                    'crafter.module.kasa',
                    'crafter.module.ksef',
                ]"
            >
                <SidebarGroup title="Koszty" :toggable="false" v-can="'crafter.module.costs'">
                    <SidebarItem :href="route('crafter.cost-planner.index')">
                        Planer kosztów
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.cost-planner.summaries.index')">
                        Zestawienia
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.cost-planner.settings.edit')">
                        Ustawienia
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.cost-planner.reports.index')">
                        Raporty
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.bank-statements.index')">
                        Wyciąg z konta
                    </SidebarItem>
                </SidebarGroup>
                <SidebarGroup title="Kasa" :toggable="false" v-can="'crafter.module.kasa'">
                    <SidebarItem :href="route('crafter.kasa.index')">
                        Kasa
                    </SidebarItem>
                </SidebarGroup>
                <SidebarGroup title="Ksef" :toggable="false" v-can="'crafter.module.ksef'">
                    <SidebarItem :href="route('crafter.ksef.pareto')">
                        Ksef Pareto
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.ksef.bsp')">
                        Ksef BSP
                    </SidebarItem>
                </SidebarGroup>
            </SidebarGroup>

            <!-- Produkcja -->
            <SidebarGroup
                title="Produkcja"
                :toggable="true"
                :open="false"
                :icon="LaserCutIcon"
                v-can="'crafter.module.production'"
            >
                <SidebarItem :href="route('crafter.production.index')">
                    Produkcja
                </SidebarItem>
                <SidebarItem :href="route('crafter.production.reports')">
                    Raporty
                </SidebarItem>
                <SidebarItem :href="route('crafter.production.settings')">
                    Ustawienia
                </SidebarItem>
            </SidebarGroup>

            <!-- Magazyn -->
            <SidebarGroup
                title="Magazyn"
                :toggable="true"
                :open="false"
                :icon="WarehouseIcon"
                v-can="'crafter.module.production'"
            >
                <SidebarItem :href="route('crafter.production.warehouse')">
                    Magazyn M3R
                </SidebarItem>
                <SidebarItem :href="route('crafter.production.warehouse.table')">
                    Tabela
                </SidebarItem>
                <SidebarItem :href="route('crafter.production.warehouse.settings')">
                    Ustawienia
                </SidebarItem>
                <SidebarItem :href="route('crafter.production.warehouse.logs')">
                    Logi
                </SidebarItem>
            </SidebarGroup>

            <!-- Argo PIM -->
            <SidebarGroup
                title="Argo PIM"
                :toggable="true"
                :open="false"
                :icon="CubeIcon"
                v-can:any="[
                    'crafter.integration.index',
                    'crafter.product.index',
                    'crafter.category.index',
                    'crafter.pricelist.index',
                    'crafter.source.index',
                    'crafter.attribute.index',
                    'crafter.template.index',
                    'crafter.media.index',
                ]"
            >
                <SidebarSubGroup title="Integracje" v-can="'crafter.integration.index'">
                    <SidebarItem :href="route('crafter.integrations.index')">
                        {{ $t("crafter", "Integrations") }}
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.integrations.status')">
                        Status sync
                    </SidebarItem>
                </SidebarSubGroup>

                <SidebarSubGroup title="Oferta">
                    <SidebarItem
                        :href="route('crafter.products.index')"
                        v-can="'crafter.product.index'"
                    >
                        {{ $t("crafter", "Products") }}
                    </SidebarItem>
                    <SidebarItem
                        :href="route('crafter.categories.index')"
                        v-can="'crafter.category.index'"
                    >
                        {{ $t("crafter", "Categories") }}
                    </SidebarItem>
                    <SidebarItem
                        :href="route('crafter.pricelists.index')"
                        v-can="'crafter.pricelist.index'"
                    >
                        {{ $t("crafter", "Pricelists") }}
                    </SidebarItem>
                    <SidebarItem
                        :href="route('crafter.sources.index')"
                        v-can="'crafter.source.index'"
                    >
                        {{ $t("crafter", "Sources") }}
                    </SidebarItem>
                </SidebarSubGroup>

                <SidebarSubGroup title="Opcje">
                    <SidebarItem
                        :href="route('crafter.attributes.index')"
                        v-can="'crafter.attribute.index'"
                    >
                        {{ $t("crafter", "Attributes") }}
                    </SidebarItem>
                </SidebarSubGroup>

                <SidebarItem
                    :href="route('crafter.templates.index')"
                    v-can="'crafter.template.index'"
                >
                    {{ $t("crafter", "Templates") }}
                </SidebarItem>
                <SidebarItem
                    :href="route('crafter.media.index')"
                    v-can="'crafter.media.index'"
                >
                    {{ $t("crafter", "Media") }}
                </SidebarItem>
            </SidebarGroup>

            <!-- Argo Connect -->
            <SidebarGroup
                title="Argo Connect"
                :toggable="true"
                :open="true"
                :icon="LinkIcon"
                v-can:any="[
                    'crafter.module.connect',
                    'crafter.module.marketplace',
                    'crafter.module.ksef',
                ]"
            >
                <!-- Sprzedaż: zamówienia i ich okolice (jeden zestaw uprawnień → v-can na grupie). -->
                <SidebarSubGroup title="Zamówienia" v-can="'crafter.module.connect'">
                    <SidebarItem :href="route('crafter.connect.orders.index')">
                        Zamówienia
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.connect.customers.index')">
                        Klienci
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.connect.map.index')">
                        Mapa
                    </SidebarItem>
                </SidebarSubGroup>

                <!-- Konfiguracja połączeń. Każda pozycja ma własne uprawnienie (KSeF ≠ marketplace),
                     więc v-can zostaje na pozycjach, a grupa znika dopiero gdy nie ma ŻADNEJ. -->
                <SidebarSubGroup
                    title="Integracje"
                    v-can:any="[
                        'crafter.module.connect',
                        'crafter.module.marketplace',
                        'crafter.module.ksef',
                    ]"
                >
                    <SidebarItem :href="route('crafter.connect.integrations.base.index')" v-can="'crafter.module.connect'">
                        Base
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.connect.integrations.ebay.index')" v-can="'crafter.module.marketplace'">
                        Ebay
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.connect.integrations.ksef.index')" v-can="'crafter.module.ksef'">
                        Ksef
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.connect.chatbot.index')" v-can="'crafter.module.connect'">
                        Chatboot
                    </SidebarItem>
                </SidebarSubGroup>

                <!-- Praca na aukcjach. Podgrupa per marketplace — kolejne rynki (Allegro, Kaufland…)
                     dokładamy obok „Ebay", bez przebudowy menu. -->
                <SidebarSubGroup title="Marketplace" v-can="'crafter.module.marketplace'">
                    <SidebarSubGroup title="Ebay">
                        <!-- Jeden wpis: Wystawianie i Aukcje to teraz zakładki tego samego ekranu. -->
                        <SidebarItem :href="route('crafter.connect.marketplace.ebay.listing.index')">
                            Wystawianie
                        </SidebarItem>
                        <SidebarItem :href="route('crafter.connect.marketplace.ebay.ktype.index')">
                            kType (pojazdy)
                        </SidebarItem>
                        <SidebarItem :href="route('crafter.connect.marketplace.ebay.schemes.index')">
                            Schematy
                        </SidebarItem>
                        <SidebarItem :href="route('crafter.connect.marketplace.ebay.templates.index')">
                            Szablony
                        </SidebarItem>
                        <SidebarItem :href="route('crafter.connect.marketplace.ebay.categories.index')">
                            Kategorie i parametry
                        </SidebarItem>
                    </SidebarSubGroup>
                </SidebarSubGroup>

                <!-- Argo App — ostatnia pozycja grupy, poza podgrupami. -->
                <SidebarItem
                    :href="route('crafter.connect.argo-app.index')"
                    v-can="'crafter.module.connect'"
                >
                    Argo App
                </SidebarItem>
            </SidebarGroup>

            <!-- Argo Scope -->
            <SidebarGroup
                title="Argo Scope"
                :toggable="true"
                :open="false"
                :icon="MagnifyingGlassIcon"
                v-can="'crafter.module.scope'"
            >
                <SidebarSubGroup title="Scrapy">
                    <SidebarItem :href="route('crafter.scope.rumuni.index')">
                        Rumuni
                    </SidebarItem>
                </SidebarSubGroup>
            </SidebarGroup>

            <!-- Argo Task -->
            <SidebarGroup
                title="Argo Task"
                :toggable="true"
                :open="false"
                :icon="ClipboardDocumentListIcon"
                v-can="'crafter.module.task'"
            >
                <SidebarGroup
                    v-for="group in argoProjectGroups"
                    :key="group.id"
                    :title="group.name"
                    :toggable="true"
                    :open="false"
                >
                    <SidebarItem :href="route('crafter.argo-task.groups.show', group.id)">
                        <span class="italic text-gray-400">Podgląd grupy</span>
                    </SidebarItem>
                    <SidebarItem
                        v-for="project in group.projects"
                        :key="project.id"
                        :href="route('crafter.argo-task.projects.show', project.id)"
                    >
                        {{ project.name }}
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.argo-task.projects.create', group.id)">
                        <span class="font-medium">+ Dodaj projekt</span>
                    </SidebarItem>
                </SidebarGroup>
                <SidebarItem :href="route('crafter.argo-task.groups.create')">
                    <span class="font-medium">+ Dodaj grupę</span>
                </SidebarItem>
            </SidebarGroup>

            <!-- [argo-mail-pkg] Argo Mail -->
            <SidebarGroup
                title="Argo Mail"
                :toggable="true"
                :open="false"
                :icon="EnvelopeIcon"
                v-can="'crafter.module.mail'"
            >
                <SidebarItem :href="route('crafter.argo-mail.index')">
                    Skrzynka
                </SidebarItem>
                <SidebarItem :href="route('crafter.argo-mail.accounts.index')">
                    Skrzynki / konta
                </SidebarItem>
                <SidebarItem :href="route('crafter.argo-mail.settings')">
                    Ustawienia
                </SidebarItem>
                <SidebarItem :href="route('crafter.ai-tools.mail.administrator')">
                    Administrator (AI)
                </SidebarItem>
                <SidebarItem :href="route('crafter.mobile.home')">
                    Wersja mobilna (PWA)
                </SidebarItem>
            </SidebarGroup>

            <!-- ADMIN — wszystko administracyjne w jednym miejscu: narzędzia AI,
                 tłumaczenia, konta i uprawnienia, poczta transakcyjna, ustawienia.
                 Grupa znika w całości, gdy użytkownik nie ma żadnego z tych uprawnień. -->
            <SidebarGroup
                title="Admin"
                :toggable="true"
                :open="false"
                :icon="Cog8ToothIcon"
                v-can:any="[
                    'crafter.ai-tool.index',
                    'crafter.module.translations',
                    'crafter.admin-user.index',
                    'crafter.role.index',
                    'crafter.permission.index',
                    'crafter.mail.view',
                    'crafter.translation.index',
                    'crafter.settings.edit',
                ]"
            >
                <!-- Narzędzia AI — pojedyncza pozycja, bez podgrupy -->
                <SidebarItem
                    :href="route('crafter.ai-tools.index')"
                    v-can="'crafter.ai-tool.index'"
                >
                    {{ $t("crafter", "AI Tools") }}
                </SidebarItem>

                <SidebarSubGroup
                    title="Tłumaczenia"
                    v-can="'crafter.module.translations'"
                >
                    <SidebarItem :href="route('crafter.translation-phrases.index')">
                        Matryca tłumaczeń
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.translation-review.index')">
                        Tłumaczenia (review)
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.translation-logs.index')">
                        Logi
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.translation-settings.index')">
                        Ustawienia
                    </SidebarItem>
                </SidebarSubGroup>

                <SidebarSubGroup
                    title="Użytkownicy i uprawnienia"
                    v-can:any="[
                        'crafter.admin-user.index',
                        'crafter.role.index',
                        'crafter.permission.index',
                    ]"
                >
                    <SidebarItem
                        :href="route('crafter.admin-users.index')"
                        v-can="'crafter.admin-user.index'"
                    >
                        {{ $t("crafter", "Users") }}
                    </SidebarItem>
                    <SidebarItem
                        :href="route('crafter.roles.index')"
                        v-can="'crafter.role.index'"
                    >
                        Role
                    </SidebarItem>
                    <SidebarItem
                        :href="route('crafter.permissions.index')"
                        v-can="'crafter.permission.index'"
                    >
                        Uprawnienia (macierz)
                    </SidebarItem>
                </SidebarSubGroup>

                <!-- [argo-mail-pkg] Poczta (SMTP transakcyjny) -->
                <SidebarSubGroup title="Poczty (SMTP)" v-can="'crafter.mail.view'">
                    <SidebarItem :href="route('crafter.mail.smtp')">
                        Mail SMTP
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.mail.templates')">
                        Szablony maili
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.mail.logs')">
                        Logi poczty
                    </SidebarItem>
                </SidebarSubGroup>

                <!-- System — do 2026-08-19 ta grupa miała v-if="false", przez co Role,
                     Lokalizacja i Ustawienia były nieosiągalne z menu. -->
                <SidebarSubGroup
                    :title="$t('crafter', 'System')"
                    v-can:any="[
                        'crafter.translation.index',
                        'crafter.settings.edit',
                    ]"
                >
                    <SidebarItem
                        :href="route('crafter.translations.index')"
                        v-can="'crafter.translation.index'"
                    >
                        {{ $t("crafter", "Localization") }}
                    </SidebarItem>
                    <SidebarItem
                        :href="route('crafter.settings.index')"
                        v-can="'crafter.settings.edit'"
                    >
                        {{ $t("crafter", "Settings") }}
                    </SidebarItem>
                </SidebarSubGroup>
            </SidebarGroup>
        </nav>
    </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import {
    Cog8ToothIcon,
    HomeIcon,
    LinkIcon,
    BuildingOffice2Icon,
    ClipboardDocumentListIcon,
    CubeIcon,
    ClipboardDocumentCheckIcon,
    EnvelopeIcon,
    MagnifyingGlassIcon
} from "@heroicons/vue/24/outline";
import {SidebarItem, SidebarGroup, SidebarSubGroup} from "crafter/Components";
import LaserCutIcon from "./Icons/LaserCutIcon.vue";
import WarehouseIcon from "./Icons/WarehouseIcon.vue";

interface ArgoProjectItem {
    id: number;
    name: string;
}

interface ArgoProjectGroupItem {
    id: number;
    name: string;
    projects: ArgoProjectItem[];
}

const argoProjectGroups = computed<ArgoProjectGroupItem[]>(
    () => (usePage().props as any).argoProjectGroups ?? []
);
</script>
