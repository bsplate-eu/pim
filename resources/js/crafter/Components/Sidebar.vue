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
            <SidebarGroup title="Argo HQ" :toggable="true" :open="false" :icon="BuildingOffice2Icon">
                <SidebarGroup title="Koszty" :toggable="false">
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
                <SidebarGroup title="Kasa" :toggable="false">
                    <SidebarItem :href="route('crafter.kasa.index')">
                        Kasa
                    </SidebarItem>
                </SidebarGroup>
                <SidebarGroup title="Ksef" :toggable="false">
                    <SidebarItem :href="route('crafter.ksef.pareto')">
                        Ksef Pareto
                    </SidebarItem>
                    <SidebarItem :href="route('crafter.ksef.bsp')">
                        Ksef BSP
                    </SidebarItem>
                </SidebarGroup>
            </SidebarGroup>

            <!-- Argo PIM -->
            <SidebarGroup title="Argo PIM" :toggable="true" :open="false" :icon="CubeIcon">
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
            <SidebarGroup title="Argo Connect" :toggable="true" :open="true" :icon="LinkIcon">
                <SidebarItem :href="route('crafter.connect.orders.index')">
                    Zamówienia
                </SidebarItem>
                <SidebarItem :href="route('crafter.connect.customers.index')">
                    Klienci
                </SidebarItem>
                <SidebarItem :href="route('crafter.connect.map.index')">
                    Mapa
                </SidebarItem>
                <SidebarItem :href="route('crafter.connect.integrations.base.index')">
                    Integracje · Base
                </SidebarItem>
                <SidebarItem :href="route('crafter.connect.integrations.ebay.index')">
                    Integracje · Ebay
                </SidebarItem>
                <SidebarItem :href="route('crafter.connect.integrations.ksef.index')">
                    Integracje · KSEF
                </SidebarItem>
                <SidebarItem :href="route('crafter.connect.chatbot.index')">
                    Integracja chatboot
                </SidebarItem>
            </SidebarGroup>

            <!-- Argo Scope -->
            <SidebarGroup title="Argo Scope" :toggable="true" :open="false" :icon="MagnifyingGlassIcon">
                <SidebarSubGroup title="Scrapy">
                    <SidebarItem :href="route('crafter.scope.rumuni.index')">
                        Rumuni
                    </SidebarItem>
                </SidebarSubGroup>
            </SidebarGroup>

            <!-- Argo Task -->
            <SidebarGroup title="Argo Task" :toggable="true" :open="false" :icon="ClipboardDocumentListIcon">
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
            <SidebarGroup title="Argo Mail" :toggable="true" :open="false" :icon="EnvelopeIcon">
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

            <!-- Narzędzia AI -->
            <SidebarItem
                :href="route('crafter.ai-tools.index')"
                :icon="CursorArrowRippleIcon"
                v-can="'crafter.ai-tool.index'"
            >
                {{ $t("crafter", "AI Tools") }}
            </SidebarItem>

            <!-- Matryca tłumaczeń -->
            <SidebarItem
                :href="route('crafter.translation-phrases.index')"
                :icon="LanguageIcon"
            >
                Matryca tłumaczeń
            </SidebarItem>
            <SidebarItem
                :href="route('crafter.translation-review.index')"
                :icon="ClipboardDocumentCheckIcon"
            >
                Tłumaczenia: review queue
            </SidebarItem>

            <!-- Użytkownicy -->
            <SidebarItem
                :href="route('crafter.admin-users.index')"
                :icon="UsersIcon"
                v-can="'crafter.admin-user.index'"
            >
                {{ $t("crafter", "Users") }}
            </SidebarItem>

            <!-- [argo-mail-pkg] Poczta (SMTP transakcyjny) -->
            <SidebarGroup title="Poczta (SMTP)" :toggable="true" :open="false" :icon="EnvelopeIcon" v-can="'crafter.mail.view'">
                <SidebarItem :href="route('crafter.mail.smtp')">
                    Mail SMTP
                </SidebarItem>
                <SidebarItem :href="route('crafter.mail.templates')">
                    Szablony maili
                </SidebarItem>
                <SidebarItem :href="route('crafter.mail.logs')">
                    Logi poczty
                </SidebarItem>
            </SidebarGroup>

            <SidebarGroup
                v-if="false"
                :title="$t('crafter', 'System')"
                v-can:any="[
                      'crafter.admin-user.index',
                      'crafter.role.index',
                      'crafter.translation.index',
                      'crafter.settings.edit',
                    ]"
            >
                <SidebarItem
                    :href="route('crafter.roles.index')"
                    :icon="KeyIcon"
                    v-can="'crafter.role.index'"
                >
                    {{ $t("crafter", "Roles") }}
                </SidebarItem>
                <SidebarItem
                    :href="route('crafter.translations.index')"
                    :icon="LanguageIcon"
                    v-can="'crafter.translation.index'"
                >
                    {{ $t("crafter", "Localization") }}
                </SidebarItem>
                <SidebarItem
                    :href="route('crafter.settings.index')"
                    :icon="Cog8ToothIcon"
                    v-can="'crafter.settings.edit'"
                >
                    {{ $t("crafter", "Settings") }}
                </SidebarItem>
            </SidebarGroup>
        </nav>
    </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import {
    KeyIcon,
    LanguageIcon,
    UsersIcon,
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
import {CursorArrowRippleIcon} from "@heroicons/vue/16/solid";

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
