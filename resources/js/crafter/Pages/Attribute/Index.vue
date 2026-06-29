<template>
    <PageHeader :title="$t('crafter', 'Attributes')">
        <div class="flex items-center gap-2 divide-x">
            <Modal size="xl" alignButtons="right">
                <template #trigger="{ setIsOpen }">
                    <Button
                        @click="() => setIsOpen(true)"
                        :leftIcon="Bars3Icon"
                    >
                        {{ $t("crafter", "Sort") }}
                    </Button>
                </template>

                <template #title>
                    {{ $t("crafter", "Sort Attributes") }}
                </template>
                <template #content>

                        <draggable
                            tag="ul"
                            v-model="draggable_attributes"
                            item-key="id"
                            handle=".handle"
                            class="3xl:grid-cols-5 grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 sm:gap-x-6 lg:grid-cols-4 xl:gap-x-8 xl:grid-cols-4 2xl:grid-cols-4"
                        >
                            <template #item="{ element, index }">
                                <li class="relative">
                                    <div
                                        class="flex items-center justify-between w-full overflow-hidden rounded-md border border-gray-300 p-2">
                        <span class="block text-sm font-medium text-gray-900 p-3">
                            {{ index + 1 }}. {{ element.name?.[currentLocale] }}
                        </span>
                                        <div class="p-2">
                                            <Bars3Icon class="handle cursor-move w-4 h-4"></Bars3Icon>
                                        </div>
                                    </div>
                                </li>
                            </template>
                        </draggable>


                </template>

                <template #buttons="{ setIsOpen }">
                    <Button
                        @click.prevent="() => setIsOpen()"
                        color="gray"
                        variant="outline"
                    >
                        {{ $t("crafter", "Cancel") }}
                    </Button>
                    <Button
                        @click.prevent="() => {updateOrder(); setIsOpen(false)}"
                    >
                        {{ $t("crafter", "Save") }}
                    </Button>
                </template>
            </Modal>
            <Button
                :leftIcon="PlusIcon"
                :as="Link"
                :href="route('crafter.attributes.create')"
                v-can="'crafter.attribute.create'"
            >
                {{ $t("crafter", "New Attribute") }}
            </Button>
        </div>

    </PageHeader>

    <PageContent>
        <div class="overflow-x-auto">
            <Listing
                :baseUrl="route('crafter.attributes.index')"
                :data="attributes"
                dataKey="attributes"
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
                                v-can="'crafter.attribute.destroy'"
                            >
                                {{ $t("crafter", "Delete") }}
                            </Button>
                        </template>

                        <template #title>
                            {{ $t("crafter", "Delete Attribute") }}
                        </template>
                        <template #content>
                            {{
                                $t(
                                    "crafter",
                                    "Are you sure you want to delete selected Attribute? All data will be permanently removed from our servers forever. This action cannot be undone."
                                )
                            }}
                        </template>

                        <template #buttons="{ setIsOpen }">
                            <Button
                                @click.prevent="
                () => {
                  bulkAction('post', route('crafter.attributes.bulk-destroy'), {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
                                color="danger"
                                v-can="'crafter.attribute.destroy'"
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
                    <ListingHeaderCell>
                        <span class="sr-only">{{ $t("crafter", "Actions") }}</span>
                    </ListingHeaderCell>
                </template>
                <template #tableRow="{ item, action }: any">

                    <ListingDataCell>
                        {{ item.id }}
                    </ListingDataCell>
                    <ListingDataCell>
                        {{ item.name?.[currentLocale] }}
                    </ListingDataCell>
                    <ListingDataCell>
                        <div class="flex items-center justify-end">
                            <IconButton
                                :as="Link"
                                :href="route('crafter.attributes.edit', item)"
                                variant="ghost"
                                color="gray"
                                :icon="PencilSquareIcon"
                                v-can="'crafter.attribute.edit'"
                            />

                            <Modal type="danger">
                                <template #trigger="{ setIsOpen }">
                                    <IconButton
                                        @click="() => setIsOpen(true)"
                                        color="gray"
                                        variant="ghost"
                                        :icon="TrashIcon"
                                        v-can="'crafter.attribute.destroy'"
                                    />
                                </template>

                                <template #title>
                                    {{ $t("crafter", "Delete Attribute") }}
                                </template>
                                <template #content>
                                    {{
                                        $t(
                                            "crafter",
                                            "Are you sure you want to delete selected Attribute? All data will be permanently removed from our servers forever. This action cannot be undone."
                                        )
                                    }}
                                </template>

                                <template #buttons="{ setIsOpen }">
                                    <Button
                                        @click.prevent="
                    () => {
                      action('delete', route('crafter.attributes.destroy', item), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                                        color="danger"
                                        v-can="'crafter.attribute.destroy'"
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
        </div>

    </PageContent>
</template>

<script setup lang="ts">
import {Link} from "@inertiajs/vue3";
import {
    PlusIcon,
    TrashIcon,
    PencilSquareIcon,
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
    Card,
} from "crafter/Components";
import {PaginatedCollection} from "crafter/types/pagination";
import type {Attribute} from "./types";

import draggable from "vuedraggable";

import {useFormLocale} from "crafter/hooks/useFormLocale";
import {Bars3Icon, Squares2X2Icon} from "@heroicons/vue/20/solid";
import {onMounted, reactive, ref} from "vue";
import axios from "axios";
import {router} from '@inertiajs/vue3'
import {useToast} from "@brackets/vue-toastification";
import {cloneDeep} from "lodash";

const {availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale} = useFormLocale();


interface Props {
    attributes: PaginatedCollection<Attribute>;
    all_attributes: Object;
}

const props = defineProps<Props>();
const selectedView = ref("list");
const draggable_attributes = ref(props.all_attributes.data);


const updateOrder = async () => {
    axios.post(route("crafter.attributes.update-order"), {
        attributes: draggable_attributes.value.map((attribute) => attribute.id),
    }).then((response) => {
        useToast().success(response.data.message);
        router.reload({only: ["attributes"]});
    });

};
</script>
