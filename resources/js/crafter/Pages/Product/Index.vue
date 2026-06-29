<template>
    <PageHeader :title="$t('crafter', 'Products')">
        <div class="flex gap-1">
            <Button
                :leftIcon="PlusIcon"
                :as="Link"
                :href="route('crafter.products.create')"
                v-can="'crafter.product.create'"
            >
                {{ $t("crafter", "New Product") }}
            </Button>
            <Button
                :leftIcon="ArrowDownTrayIcon"
                :as="Link"
                :href="route('crafter.products.export-import')"
                v-can="'crafter.product.create'"
            >
                {{ $t("crafter", "Export/Import") }}
            </Button>
        </div>
    </PageHeader>

    <PageContent>
        <Listing
            :baseUrl="route('crafter.products.index')"
            :data="products"
            dataKey="products"
        >
            <template #actions>
                <FiltersDropdown
                    :activeFiltersCount="activeFiltersCount"
                    :resetFilters="resetFilters"
                >
                    <Multiselect
                        v-model="filtersForm.source"
                        name="source"
                        :label="$t('crafter', 'Source')"
                        :options="sources"
                        options-value-prop="id"
                        options-label="name"
                        mode="single"
                        can-clear
                    />
                    <Multiselect
                        v-model="filtersForm.enabled"
                        name="enabled"
                        :label="$t('crafter', 'Enabled')"
                        :options="[{ value: '1', label: $t('crafter', 'Yes') }, { value: '0', label: $t('crafter', 'No') }]"
                        mode="single"
                        can-clear
                    />
                </FiltersDropdown>
            </template>
            <template #bulkActions="{ bulkAction }">
                <Modal type="danger">
                    <template #trigger="{ setIsOpen }">
                        <Button
                            @click="() => setIsOpen(true)"
                            color="gray"
                            variant="outline"
                            size="sm"
                            :leftIcon="TrashIcon"
                            v-can="'crafter.product.destroy'"
                        >
                            {{ $t("crafter", "Delete") }}
                        </Button>
                    </template>

                    <template #title>
                        {{ $t("crafter", "Delete Product") }}
                    </template>
                    <template #content>
                        {{
                            $t(
                                "crafter",
                                "Are you sure you want to delete selected Product? All data will be permanently removed from our servers forever. This action cannot be undone."
                            )
                        }}
                    </template>

                    <template #buttons="{ setIsOpen }">
                        <Button
                            @click.prevent="
                () => {
                  bulkAction('post', route('crafter.products.bulk-destroy'), {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
                            color="danger"
                            v-can="'crafter.product.destroy'"
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

                <ListingHeaderCell sortBy="external_id">
                    {{ $t("crafter", "ID") }}
                </ListingHeaderCell>
                <!--          <ListingHeaderCell sortBy="source_id">-->
                <!--              {{ $t("crafter", "Source") }}-->
                <!--          </ListingHeaderCell>-->
                <!--          <ListingHeaderCell sortBy="product_code">-->
                <!--              {{ $t("crafter", "Product Code") }}-->
                <!--          </ListingHeaderCell>-->
                <!--                <ListingHeaderCell>-->
                <!--                    {{ $t("crafter", "Image") }}-->
                <!--                </ListingHeaderCell>-->

                <ListingHeaderCell sortBy="name">
                    {{ $t("crafter", "Name") }}
                </ListingHeaderCell>
                <ListingHeaderCell sortBy="category">
                    {{ $t("crafter", "Category") }}
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
                    {{ item.external_id }}
                </ListingDataCell>
                <ListingDataCell>
                    <div class="flex items-center">
                        <img
                            :src="item.thumbnail_url"
                            class="rounded object-cover h-20"/>
                        <div class="ml-4">
                            <div class="font-medium text-gray-900 mb-1">
                                {{ item.name?.[currentLocale] }}
                            </div>
                            <div class="flex gap-1">
                                <Tag
                                    color="info"
                                    size="sm"
                                    rounded
                                >
                                    {{ item.source?.name }}
                                </Tag>
                                <Tag
                                    color="info"
                                    size="sm"
                                    rounded
                                >
                                    {{ item.product_code }}
                                </Tag>
                            </div>
                        </div>
                    </div>
                </ListingDataCell>
                <ListingDataCell>
                    {{ item.category }}
                </ListingDataCell>
                <ListingDataCell>
                    <Tag
                        :color="item.enabled ? 'success' : 'danger'"
                        :icon="item.enabled ? CheckCircleIcon : XCircleIcon"
                        @click.prevent="
                    () => {
                      action('put', route('crafter.products.update', item), {enabled: !item.enabled});
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
                            :href="route('crafter.products.edit', item)"
                            variant="ghost"
                            color="gray"
                            :icon="PencilSquareIcon"
                            v-can="'crafter.product.edit'"
                        />

                        <Modal type="danger">
                            <template #trigger="{ setIsOpen }">
                                <IconButton
                                    @click="() => setIsOpen(true)"
                                    color="gray"
                                    variant="ghost"
                                    :icon="TrashIcon"
                                    v-can="'crafter.product.destroy'"
                                />
                            </template>

                            <template #title>
                                {{ $t("crafter", "Delete Product") }}
                            </template>
                            <template #content>
                                {{
                                    $t(
                                        "crafter",
                                        "Are you sure you want to delete selected Product? All data will be permanently removed from our servers forever. This action cannot be undone."
                                    )
                                }}
                            </template>

                            <template #buttons="{ setIsOpen }">
                                <Button
                                    @click.prevent="
                    () => {
                      action('delete', route('crafter.products.destroy', item), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                                    color="danger"
                                    v-can="'crafter.product.destroy'"
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
import {Link, router, usePage} from "@inertiajs/vue3";
import {
    PlusIcon,
    TrashIcon,
    PencilSquareIcon,
    ArrowDownTrayIcon, CheckCircleIcon, XCircleIcon,
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
    Publish, Tag,
} from "crafter/Components";
import {PaginatedCollection} from "crafter/types/pagination";
import type {Product} from "./types";
import type {PageProps} from "crafter/types/page";
import {useListingFilters} from "@/crafter/hooks/useListingFilters";
import dayjs from "dayjs";


import {useFormLocale} from "crafter/hooks/useFormLocale";


const {availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale} = useFormLocale();


interface Props {
    products: PaginatedCollection<Product>;
    sources: Array<any>;
}

defineProps<Props>();
const downloadFile = () => {
    const url = window.location.href.split("?");
    if (url.length > 1) {
        window.location = route('crafter.products.export', url.pop()).slice(0, -1);
    } else {
        window.location = route('crafter.products.export');
    }
}

const {filtersForm, resetFilters, activeFiltersCount} = useListingFilters(
    "/admin/products",
    {
        source: (usePage().props as PageProps).filter?.source ?? null,
        enabled: (usePage().props as PageProps).filter?.enabled ?? null,
    }
);
</script>
