<template>
    <PageHeader :title="$t('crafter', 'Integrations')">
        <Button
            :leftIcon="PlusIcon"
            :as="Link"
            :href="route('crafter.integrations.create')"
            v-can="'crafter.integration.create'"
        >
            {{ $t("crafter", "New Integration") }}
        </Button>

    </PageHeader>

    <PageContent fluid>
        <Listing
            :baseUrl="route('crafter.integrations.index')"
            :data="integrations"
            dataKey="integrations"
        >
            <template #bulkActions="{ bulkAction }">
                <Modal type="danger">
                    <template #trigger="{ setIsOpen }">
                        <Button
                            @click="() => setIsOpen(true)"
                            color="gray"
                            variant="outline"
                            size="sm"
                            :leftIcon="TrashIcon"
                            v-can="'crafter.integration.destroy'"
                        >
                            {{ $t("crafter", "Delete") }}
                        </Button>
                    </template>

                    <template #title>
                        {{ $t("crafter", "Delete Integration") }}
                    </template>
                    <template #content>
                        {{
                            $t(
                                "crafter",
                                "Are you sure you want to delete selected Integration? All data will be permanently removed from our servers forever. This action cannot be undone."
                            )
                        }}
                    </template>

                    <template #buttons="{ setIsOpen }">
                        <Button
                            @click.prevent="
                () => {
                  bulkAction('post', route('crafter.integrations.bulk-destroy'), {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
                            color="danger"
                            v-can="'crafter.integration.destroy'"
                        >
                            {{ $t("crafter", "Delete") }}
                        </Button>
                        <Button
                            @click.prevent="() => setIsOpen()"
                            color="gray"
                            variant="outline"
                        >
                            {{ $t("crafter", "Cancel") }}
                        </Button>
                    </template>
                </Modal>
            </template>
            <template #tableHead>

                <ListingHeaderCell sortBy="id">
                    {{ $t("crafter", "Id") }}
                </ListingHeaderCell>
                <ListingHeaderCell sortBy="name">
                    {{ $t("crafter", "Name") }}
                </ListingHeaderCell>
                <ListingHeaderCell sortBy="type">
                    {{ $t("crafter", "Type") }}
                </ListingHeaderCell>
                <ListingHeaderCell>
                    {{ $t("crafter", "Sources") }}
                </ListingHeaderCell>
                <ListingHeaderCell sortBy="enabled">
                    {{ $t("crafter", "Enabled") }}
                </ListingHeaderCell>
                <ListingHeaderCell>
                    <span class="sr-only">{{ $t("crafter", "Actions") }}</span>
                </ListingHeaderCell>
            </template>
            <template #tableRow="{ item, action }: any">

                <ListingDataCell>
                    {{ item.id }}
                </ListingDataCell>
                <ListingDataCell>
                    <div class="font-medium text-gray-900">
                    {{ item.name }}
                    </div>
                </ListingDataCell>
                <ListingDataCell>
                    <div class="flex items-center">
                        <img
                            :src="'https://favicone.com/' + item.type + '.com'"
                            class="object-cover" :alt="item.host"/>
                        <div class="ml-2">
                            <div class="text-gray-900">
                                {{ _.capitalize(item.type) }}
                            </div>
                        </div>
                    </div>

                </ListingDataCell>
                <ListingDataCell>
                    <div class="flex gap-1">
                        <Tag v-for="integration_source in item.integration_sources"
                            show-dot
                             color="info"
                            size="sm"
                            rounded
                        >
                            {{ integration_source.source.name }}
                        </Tag>
                    </div>
                </ListingDataCell>
                <ListingDataCell>
                    <Tag
                        :color="item.enabled ? 'success' : 'danger'"
                        :icon="item.enabled ? CheckCircleIcon : XCircleIcon"
                        @click.prevent="
                    () => {
                      action('put', route('crafter.integrations.update', item), {enabled: !item.enabled});
                    }
                  "
                        size="sm"
                        class="p-2 cursor-pointer"
                        rounded
                    >
                        {{ item.enabled ? $t("crafter", "Yes") : $t("crafter", "No") }}
                    </Tag>
                </ListingDataCell>
                <ListingDataCell>
                    <div class="flex items-center justify-end gap-3">
                        <IconButton
                            :as="Link"
                            :href="route('crafter.integration-products.index', item)"
                            variant="ghost"
                            color="gray"
                            :icon="ListBulletIcon"
                            v-can="'crafter.integration.edit'"
                        />
                        <IconButton
                            @click.prevent="downloadFile(item)"
                            :loading="loadingFile"
                            :loadingText="'Loading...'"
                            variant="ghost"
                            color="gray"
                            :icon="ArrowDownTrayIcon"
                            v-can="'crafter.integration.edit'"
                        />
                        <IconButton
                            @click.prevent.stop="() => openConnectorModal(item)"
                            variant="ghost"
                            color="gray"
                            :icon="ArrowsRightLeftIcon"
                            v-can="'crafter.integration.edit'"
                        />
                        <IconButton
                            :as="Link"
                            :href="route('crafter.integrations.edit', item)"
                            variant="ghost"
                            color="gray"
                            :icon="PencilSquareIcon"
                            v-can="'crafter.integration.edit'"
                        />

                        <Modal type="danger">
                            <template #trigger="{ setIsOpen }">
                                <IconButton
                                    @click="() => setIsOpen(true)"
                                    color="gray"
                                    variant="ghost"
                                    :icon="TrashIcon"
                                    v-can="'crafter.integration.destroy'"
                                />
                            </template>

                            <template #title>
                                {{ $t("crafter", "Delete Integration") }}
                            </template>
                            <template #content>
                                {{
                                    $t(
                                        "crafter",
                                        "Are you sure you want to delete selected Integration? All data will be permanently removed from our servers forever. This action cannot be undone."
                                    )
                                }}
                            </template>

                            <template #buttons="{ setIsOpen }">
                                <Button
                                    @click.prevent="
                    () => {
                      action('delete', route('crafter.integrations.destroy', item), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                                    color="danger"
                                    v-can="'crafter.integration.destroy'"
                                >
                                    {{ $t("crafter", "Delete") }}
                                </Button>
                                <Button
                                    @click.prevent="() => setIsOpen()"
                                    color="gray"
                                    variant="outline"
                                >
                                    {{ $t("crafter", "Cancel") }}
                                </Button>
                            </template>
                        </Modal>
                    </div>
                </ListingDataCell>
            </template>
        </Listing>

        <Modal :open="connectorModalOpen" externalOpen @toggleOpen="closeConnectorModal">
            <template #title>
                Aktualizacje oferty
            </template>

            <template #content>
                <div class="grid gap-2">
                    <template v-if="selectedIntegration?.type === 'baselinker' || selectedIntegration?.type === 'selly'">
                        <Button
                            @click.prevent="runPullSync"
                            color="gray"
                            variant="outline"
                            class="justify-start"
                        >
                            Odśwież feed dla {{ _.capitalize(selectedIntegration.type) }}
                        </Button>
                        <p class="text-sm text-gray-500 mt-2">
                            {{ _.capitalize(selectedIntegration.type) }} działa <strong>PULL</strong> — sam pobiera plik z PIM kiedy chce.
                            Ta akcja: dodaje nowe produkty do feed-a, czyści cache (3600s) i flaguje produkty jako zsynchronizowane.
                            Faktyczne pobranie XML musisz wymusić <strong>w panelu {{ _.capitalize(selectedIntegration.type) }}</strong> (Katalogi produktów → odśwież XML).
                        </p>
                    </template>
                    <template v-else-if="selectedIntegration?.type === 'prestashop' || selectedIntegration?.type === 'litecart' || selectedIntegration?.type === 'opencart'">
                        <Button
                            @click.prevent="() => runConnectorAction('catalog-create')"
                            color="gray"
                            variant="outline"
                            class="justify-start"
                        >
                            Dodaj nowe pozycje do katalogu
                        </Button>
                        <Button
                            @click.prevent="() => runConnectorAction('media')"
                            color="gray"
                            variant="outline"
                            class="justify-start"
                        >
                            Zaktualizuj kolejkę zdjęć
                        </Button>
                        <Button
                            @click.prevent="() => runConnectorAction('catalog-delta')"
                            color="gray"
                            variant="outline"
                            class="justify-start"
                        >
                            Uruchom aktualizację oferty
                        </Button>
                        <Button
                            @click.prevent="() => runConnectorAction('blog')"
                            color="gray"
                            variant="outline"
                            class="justify-start"
                        >
                            Synchronizuj wpisy blogowe
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm text-gray-500">
                            Nieobsługiwany typ integracji: {{ selectedIntegration?.type }}
                        </p>
                    </template>
                </div>
            </template>
        </Modal>
    </PageContent>
</template>

<script setup lang="ts">
import {Link, router, usePage} from "@inertiajs/vue3";
import {
    PlusIcon,
    TrashIcon,
    PencilSquareIcon,
    ListBulletIcon,
    ArrowsRightLeftIcon,
    ArrowDownTrayIcon,
    CheckCircleIcon,
    XCircleIcon
} from "@heroicons/vue/24/outline";
import {
    PageHeader,
    PageContent,
    Button,
    Listing,
    ListingHeaderCell,
    ListingDataCell,
    Modal,
    IconButton,
    Tag,
} from "crafter/Components";
import {PaginatedCollection} from "crafter/types/pagination";
import type {Integration} from "./types";
import _ from "lodash";
import {ref} from 'vue';
import axios from 'axios';
import {useToast} from "@brackets/vue-toastification";

const toast = useToast();


interface Props {
    integrations: PaginatedCollection<Integration>;
}

defineProps<Props>();

const loadingFile = ref(null);
const connectorModalOpen = ref(false);
const selectedIntegration = ref<any | null>(null);

const downloadFile = async function (integration) {
    try {
        loadingFile.value = integration.id;

        const response = await axios({
            url: route('crafter.integration-products.export', [integration]),
            method: 'GET',
            responseType: 'blob',
        });

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `export-${integration.id}.csv`); // Dostosuj nazwę pliku
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } catch (error) {
        toast.error('Something went wrong. Please try again.');
    } finally {
        loadingFile.value = null;
    }
};

const openConnectorModal = function (integration) {
    selectedIntegration.value = integration;
    connectorModalOpen.value = true;
};

const closeConnectorModal = function () {
    connectorModalOpen.value = false;
};

const runConnectorAction = function (connector) {
    if (!selectedIntegration.value) {
        return;
    }

    router.post(
        route('crafter.integrations.sync-connector', [selectedIntegration.value, connector]),
        {},
        {
            onFinish: () => {
                connectorModalOpen.value = false;
            },
        }
    );
};

const runPullSync = function () {
    if (!selectedIntegration.value) {
        return;
    }

    router.get(
        route('crafter.integrations.sync', [selectedIntegration.value]),
        {},
        {
            onFinish: () => {
                connectorModalOpen.value = false;
            },
        }
    );
};
</script>
