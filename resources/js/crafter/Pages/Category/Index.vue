<template>
  <PageHeader :title="$t('crafter', 'Categories')">
    <Button
      :leftIcon="PlusIcon"
      :as="Link"
      :href="route('crafter.categories.create')"
      v-can="'crafter.category.create'"
    >
      {{ $t("crafter", "New Category") }}
    </Button>

  </PageHeader>

  <PageContent>
    <Listing
      :baseUrl="route('crafter.categories.index')"
      :data="categories"
      dataKey="categories"
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
              v-can="'crafter.category.destroy'"
            >
              {{ $t("crafter", "Delete") }}
            </Button>
          </template>

          <template #title>
            {{ $t("crafter", "Delete Category") }}
          </template>
          <template #content>
            {{
              $t(
                "crafter",
                "Are you sure you want to delete selected Category? All data will be permanently removed from our servers forever. This action cannot be undone."
              )
            }}
          </template>

          <template #buttons="{ setIsOpen }">
            <Button
              @click.prevent="
                () => {
                  bulkAction('post', route('crafter.categories.bulk-destroy'), {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
              color="danger"
              v-can="'crafter.category.destroy'"
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
          <div class="flex items-center justify-end gap-3">
              <IconButton
                  :as="Link"
                  :href="route('crafter.categories.index', {parent_id: item.id})"
                  variant="ghost"
                  color="gray"
                  :icon="ListBulletIcon"
                  v-can="'crafter.integration.edit'"
              />
            <IconButton
              :as="Link"
              :href="route('crafter.categories.edit', item)"
              variant="ghost"
              color="gray"
              :icon="PencilSquareIcon"
              v-can="'crafter.category.edit'"
            />

            <Modal type="danger">
              <template #trigger="{ setIsOpen }">
                <IconButton
                  @click="() => setIsOpen(true)"
                  color="gray"
                  variant="ghost"
                  :icon="TrashIcon"
                  v-can="'crafter.category.destroy'"
                />
              </template>

              <template #title>
                {{ $t("crafter", "Delete Category") }}
              </template>
              <template #content>
                {{
                  $t(
                    "crafter",
                    "Are you sure you want to delete selected Category? All data will be permanently removed from our servers forever. This action cannot be undone."
                  )
                }}
              </template>

              <template #buttons="{ setIsOpen }">
                <Button
                  @click.prevent="
                    () => {
                      action('delete', route('crafter.categories.destroy', item), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                  color="danger"
                  v-can="'crafter.category.destroy'"
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
import { Link, usePage } from "@inertiajs/vue3";
import {
    PlusIcon,
    TrashIcon,
    PencilSquareIcon,
    ArrowDownTrayIcon, ListBulletIcon,
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
import { PaginatedCollection } from "crafter/types/pagination";
import type { Category } from "./types";
import type { PageProps } from "crafter/types/page";
import dayjs from "dayjs";


import { useFormLocale } from "crafter/hooks/useFormLocale";


const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();


interface Props {
  categories: PaginatedCollection<Category>;
}
defineProps<Props>();

</script>
