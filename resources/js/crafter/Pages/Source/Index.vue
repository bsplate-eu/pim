<template>
  <PageHeader :title="$t('crafter', 'Sources')">
    <Button
      :leftIcon="PlusIcon"
      :as="Link"
      :href="route('crafter.sources.create')"
      v-can="'crafter.source.create'"
    >
      {{ $t("crafter", "New Source") }}
    </Button>

  </PageHeader>

  <PageContent>
    <Listing
      :baseUrl="route('crafter.sources.index')"
      :data="sources"
      dataKey="sources"
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
              v-can="'crafter.source.destroy'"
            >
              {{ $t("crafter", "Delete") }}
            </Button>
          </template>

          <template #title>
            {{ $t("crafter", "Delete Source") }}
          </template>
          <template #content>
            {{
              $t(
                "crafter",
                "Are you sure you want to delete selected Source? All data will be permanently removed from our servers forever. This action cannot be undone."
              )
            }}
          </template>

          <template #buttons="{ setIsOpen }">
            <Button
              @click.prevent="
                () => {
                  bulkAction('post', route('crafter.sources.bulk-destroy'), {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
              color="danger"
              v-can="'crafter.source.destroy'"
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
             {{ item.name }}
        </ListingDataCell>
        <ListingDataCell>
            <Tag
                :color="item.enabled ? 'success' : 'danger'"
                :icon="item.enabled ? CheckCircleIcon : XCircleIcon"
                @click.prevent="
                    () => {
                      action('put', route('crafter.sources.update', item), {enabled: !item.enabled});
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
              :href="route('crafter.sources.edit', item)"
              variant="ghost"
              color="gray"
              :icon="PencilSquareIcon"
              v-can="'crafter.source.edit'"
            />

            <Modal type="danger">
              <template #trigger="{ setIsOpen }">
                <IconButton
                  @click="() => setIsOpen(true)"
                  color="gray"
                  variant="ghost"
                  :icon="TrashIcon"
                  v-can="'crafter.source.destroy'"
                />
              </template>

              <template #title>
                {{ $t("crafter", "Delete Source") }}
              </template>
              <template #content>
                {{
                  $t(
                    "crafter",
                    "Are you sure you want to delete selected Source? All data will be permanently removed from our servers forever. This action cannot be undone."
                  )
                }}
              </template>

              <template #buttons="{ setIsOpen }">
                <Button
                  @click.prevent="
                    () => {
                      action('delete', route('crafter.sources.destroy', item), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                  color="danger"
                  v-can="'crafter.source.destroy'"
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
import { Link, router,usePage } from "@inertiajs/vue3";
import {
    PlusIcon,
    TrashIcon,
    PencilSquareIcon,
    ArrowDownTrayIcon,
    CheckCircleIcon,
    XCircleIcon
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
    Tag,
    FiltersDropdown,
    Publish,
} from "crafter/Components";
import { PaginatedCollection } from "crafter/types/pagination";
import type { Source } from "./types";
import type { PageProps } from "crafter/types/page";
import dayjs from "dayjs";



interface Props {
  sources: PaginatedCollection<Source>;
}
defineProps<Props>();

</script>
