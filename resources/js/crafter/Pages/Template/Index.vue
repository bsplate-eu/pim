<template>
  <PageHeader :title="$t('crafter', 'Templates')">
    <Button
      :leftIcon="PlusIcon"
      :as="Link"
      :href="route('crafter.templates.create')"
      v-can="'crafter.template.create'"
    >
      {{ $t("crafter", "New Template") }}
    </Button>

  </PageHeader>

  <PageContent>
    <Listing
      :baseUrl="route('crafter.templates.index')"
      :data="templates"
      dataKey="templates"
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
              v-can="'crafter.template.destroy'"
            >
              {{ $t("crafter", "Delete") }}
            </Button>
          </template>

          <template #title>
            {{ $t("crafter", "Delete Template") }}
          </template>
          <template #content>
            {{
              $t(
                "crafter",
                "Are you sure you want to delete selected Template? All data will be permanently removed from our servers forever. This action cannot be undone."
              )
            }}
          </template>

          <template #buttons="{ setIsOpen }">
            <Button
              @click.prevent="
                () => {
                  bulkAction('post', route('crafter.templates.bulk-destroy'), {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
              color="danger"
              v-can="'crafter.template.destroy'"
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

<!--        <ListingHeaderCell sortBy="id">-->
<!--            {{ $t("crafter", "Id") }}-->
<!--        </ListingHeaderCell> -->
        <ListingHeaderCell sortBy="name">
            {{ $t("crafter", "Name") }}
        </ListingHeaderCell>
        <ListingHeaderCell sortBy="name">
            {{ $t("crafter", "Locale") }}
        </ListingHeaderCell>
        <ListingHeaderCell>
          <span class="sr-only">{{ $t("crafter", "Actions") }}</span>
        </ListingHeaderCell>
      </template>
      <template #tableRow="{ item, action }: any">

<!--        <ListingDataCell>-->
<!--             {{ item.id }}-->
<!--        </ListingDataCell> -->
        <ListingDataCell>
             {{ item.name }}
        </ListingDataCell>
        <ListingDataCell>
            <LocaleFlag :locale="item.locale" />
        </ListingDataCell>

        <ListingDataCell>
          <div class="flex items-center justify-end gap-3">
            <IconButton
              :as="Link"
              :href="route('crafter.templates.preview', item)"
              variant="ghost"
              color="gray"
              :icon="EyeIcon"
              v-can="'crafter.template.edit'"
            />
            <IconButton
              :as="Link"
              :href="route('crafter.templates.edit', item)"
              variant="ghost"
              color="gray"
              :icon="PencilSquareIcon"
              v-can="'crafter.template.edit'"
            />

            <Modal type="danger">
              <template #trigger="{ setIsOpen }">
                <IconButton
                  @click="() => setIsOpen(true)"
                  color="gray"
                  variant="ghost"
                  :icon="TrashIcon"
                  v-can="'crafter.template.destroy'"
                />
              </template>

              <template #title>
                {{ $t("crafter", "Delete Template") }}
              </template>
              <template #content>
                {{
                  $t(
                    "crafter",
                    "Are you sure you want to delete selected Template? All data will be permanently removed from our servers forever. This action cannot be undone."
                  )
                }}
              </template>

              <template #buttons="{ setIsOpen }">
                <Button
                  @click.prevent="
                    () => {
                      action('delete', route('crafter.templates.destroy', item), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                  color="danger"
                  v-can="'crafter.template.destroy'"
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
    ArrowDownTrayIcon, EyeIcon,
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
    Publish, LocaleFlag,
} from "crafter/Components";
import { PaginatedCollection } from "crafter/types/pagination";
import type { Template } from "./types";
import type { PageProps } from "crafter/types/page";
import dayjs from "dayjs";



interface Props {
  templates: PaginatedCollection<Template>;
}
defineProps<Props>();

</script>
