<template>
    <PageHeader :title="$t('crafter', 'Integration Products') + ' - ' + integration.name">
        <Button
            :leftIcon="ArrowDownTrayIcon"
            as="a"
            class="ml-2"
            @click="downloadFile"
        >
            {{ $t("crafter", "Export") }}
        </Button>
    </PageHeader>

    <PageContent>
        <Listing
            :baseUrl="route('crafter.integration-products.index', integration)"
            :data="integrationProducts"
            dataKey="integrationProducts"
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
                            v-can="'crafter.integration-product.destroy'"
                        >
                            {{ $t("crafter", "Delete") }}
                        </Button>
                    </template>

                    <template #title>
                        {{ $t("crafter", "Delete Integration Product") }}
                    </template>
                    <template #content>
                        {{
                            $t(
                                "crafter",
                                "Are you sure you want to delete selected Integration Product? All data will be permanently removed from our servers forever. This action cannot be undone."
                            )
                        }}
                    </template>

                    <template #buttons="{ setIsOpen }">
                        <Button
                            @click.prevent="
                () => {
                  bulkAction('post', route('crafter.integration-products.bulk-destroy', integration), {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
                            color="danger"
                            v-can="'crafter.integration-product.destroy'"
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
                <ListingHeaderCell>
                    {{ $t("crafter", "Product Code") }}
                </ListingHeaderCell>
                <ListingHeaderCell>
                    {{ $t("crafter", "Category") }}
                </ListingHeaderCell>
                <ListingHeaderCell>
                    {{ $t("crafter", "Sub Category") }}
                </ListingHeaderCell>
                <ListingHeaderCell>
                    {{ $t("crafter", "Name") }}
                </ListingHeaderCell>
                <ListingHeaderCell sortBy="synced_at">
                    {{ $t("crafter", "Synced At") }}
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
                    {{ item.product.product_code }}
                </ListingDataCell>
                <ListingDataCell>
                    {{ item.product.category }}
                </ListingDataCell>
                <ListingDataCell>
                    {{ item.product.sub_category }}
                </ListingDataCell>
                <ListingDataCell>
                    {{ item.product.name?.[currentLocale] }}
                </ListingDataCell>
                <ListingDataCell>
                    {{ dayjs(item.synced_at).format('DD.MM.YYYY HH:mm') }}
                </ListingDataCell>
                <ListingDataCell>
                    <div class="flex items-center justify-end gap-3">
                        <IconButton
                            :as="Link"
                            :href="route('crafter.integration-products.edit', [integration, item])"
                            variant="ghost"
                            color="gray"
                            :icon="PencilSquareIcon"
                            v-can="'crafter.integration-product.edit'"
                        />

                        <Modal type="danger">
                            <template #trigger="{ setIsOpen }">
                                <IconButton
                                    @click="() => setIsOpen(true)"
                                    color="gray"
                                    variant="ghost"
                                    :icon="TrashIcon"
                                    v-can="'crafter.integration-product.destroy'"
                                />
                            </template>

                            <template #title>
                                {{ $t("crafter", "Delete Integration Product") }}
                            </template>
                            <template #content>
                                {{
                                    $t(
                                        "crafter",
                                        "Are you sure you want to delete selected Integration Product? All data will be permanently removed from our servers forever. This action cannot be undone."
                                    )
                                }}
                            </template>

                            <template #buttons="{ setIsOpen }">
                                <Button
                                    @click.prevent="
                    () => {
                      action('delete', route('crafter.integration-products.destroy', [integration, item]), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                                    color="danger"
                                    v-can="'crafter.integration-product.destroy'"
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
    </PageContent>
</template>

<script setup lang="ts">
import {Link, usePage} from "@inertiajs/vue3";
import {
    PlusIcon,
    TrashIcon,
    PencilSquareIcon,
    ArrowDownTrayIcon,
} from "@heroicons/vue/24/outline";
import {
    PageHeader,
    PageContent,
    Button,
    Listing,
    Avatar,
    ListingHeaderCell,
    ListingDataCell,
    Modal,
    Multiselect,
    IconButton,
    FiltersDropdown,
    Publish,
} from "crafter/Components";
import {PaginatedCollection} from "crafter/types/pagination";
import type {IntegrationProduct} from "./types";
import type {PageProps} from "crafter/types/page";
import dayjs from "dayjs";
import {Integration} from "crafter/Pages/Integration/types";
import {useFormLocale} from "crafter/hooks/useFormLocale";

const {availableLocales, currentLocale} = useFormLocale();

interface Props {
    integration: Integration | null;
}

const props = defineProps<Props>();
const downloadFile = () => {
    window.location = route('crafter.integration-products.export', [props.integration]);
}
</script>
