<template>
  <PageHeader :title="$t('crafter', 'Ai Tools')">
    <Button
      :leftIcon="PlusIcon"
      :as="Link"
      :href="route('crafter.ai-tools.create')"
      v-can="'crafter.ai-tool.create'"
    >
      {{ $t("crafter", "New Ai Tool") }}
    </Button>

  </PageHeader>

  <PageContent>
    <Listing
      :baseUrl="route('crafter.ai-tools.index')"
      :data="aiTools"
      dataKey="aiTools"
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
              v-can="'crafter.ai-tool.destroy'"
            >
              {{ $t("crafter", "Delete") }}
            </Button>
          </template>

          <template #title>
            {{ $t("crafter", "Delete Ai Tool") }}
          </template>
          <template #content>
            {{
              $t(
                "crafter",
                "Are you sure you want to delete selected Ai Tool? All data will be permanently removed from our servers forever. This action cannot be undone."
              )
            }}
          </template>

          <template #buttons="{ setIsOpen }">
            <Button
              @click.prevent="
                () => {
                  bulkAction('post', route('crafter.ai-tools.bulk-destroy'), {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
              color="danger"
              v-can="'crafter.ai-tool.destroy'"
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
        <ListingHeaderCell sortBy="description">
            {{ $t("crafter", "Description") }}
        </ListingHeaderCell>
        <ListingHeaderCell sortBy="provider">
            {{ $t("crafter", "Provider") }}
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
             {{ item.name?.[currentLocale] }}
        </ListingDataCell>
        <ListingDataCell>
             {{ item.description?.[currentLocale] }}
        </ListingDataCell>
        <ListingDataCell>
             {{ item.provider }}
        </ListingDataCell>
        <ListingDataCell>
            <Tag
                :color="item.enabled ? 'success' : 'danger'"
                :icon="item.enabled ? CheckCircleIcon : XCircleIcon"
                @click.prevent="
                    () => {
                      action('put', route('crafter.ai-tools.update', item), {enabled: !item.enabled});
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
              :href="route('crafter.ai-tools.edit', item)"
              variant="ghost"
              color="gray"
              :icon="PencilSquareIcon"
              v-can="'crafter.ai-tool.edit'"
            />

            <Modal type="danger">
              <template #trigger="{ setIsOpen }">
                <IconButton
                  @click="() => setIsOpen(true)"
                  color="gray"
                  variant="ghost"
                  :icon="TrashIcon"
                  v-can="'crafter.ai-tool.destroy'"
                />
              </template>

              <template #title>
                {{ $t("crafter", "Delete Ai Tool") }}
              </template>
              <template #content>
                {{
                  $t(
                    "crafter",
                    "Are you sure you want to delete selected Ai Tool? All data will be permanently removed from our servers forever. This action cannot be undone."
                  )
                }}
              </template>

              <template #buttons="{ setIsOpen }">
                <Button
                  @click.prevent="
                    () => {
                      action('delete', route('crafter.ai-tools.destroy', item), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                  color="danger"
                  v-can="'crafter.ai-tool.destroy'"
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
import { PaginatedCollection } from "crafter/types/pagination";
import type { AiTool } from "./types";
import type { PageProps } from "crafter/types/page";
import dayjs from "dayjs";


import { useFormLocale } from "crafter/hooks/useFormLocale";


const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();


interface Props {
  aiTools: PaginatedCollection<AiTool>;
}
defineProps<Props>();

</script>
