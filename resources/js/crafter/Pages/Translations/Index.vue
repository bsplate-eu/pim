<template>
  <PageHeader :title="$t('crafter', 'Translations')">
    <div class="flex">
      <ExportModal
        @toggle-open="exportModalOpened = !exportModalOpened"
        :open="exportModalOpened"
        :locales="locales"
        v-can="'crafter.translation.export'"
      />
      <ImportModal
        @toggle-open="importModalOpened = !importModalOpened"
        :open="importModalOpened"
        :locales="locales"
        v-can="'crafter.translation.import'"
      />
      <ButtonGroup>
        <Button
          @click="
            () => {
              action('post', `/admin/translations/publish`);
              reload();
            }
          "
          v-can="'crafter.translation.publish'"
        >
          {{ $t("crafter", "Publish translations") }}
        </Button>
        <Dropdown
          noContentPadding
          v-can:any="[
            'crafter.translation.export',
            'crafter.translation.import',
            'crafter.translation.rescan',
          ]"
        >
          <template #button>
            <IconButton :icon="ChevronDownIcon" class="rounded-l-none" />
          </template>

          <template #content>
            <div class="py-1">
              <DropdownItem
                @click="
                  () => {
                    action('post', `/admin/translations/rescan`);
                    toast.warning(
                      $t('crafter', 'Scanning translations...')
                    );
                  }
                "
                v-can="'crafter.translation.rescan'"
              >
                {{ $t("crafter", "Re-scan translations") }}
              </DropdownItem>
              <DropdownItem
                @click="exportModalOpened = true"
                v-can="'crafter.translation.export'"
              >
                {{ $t("crafter", "Export") }}
              </DropdownItem>
              <DropdownItem
                @click="importModalOpened = true"
                v-can="'crafter.translation.import'"
              >
                {{ $t("crafter", "Import") }}
              </DropdownItem>
            </div>
          </template>
        </Dropdown>
      </ButtonGroup>
    </div>
  </PageHeader>

  <PageContent>
    <Listing
      :data="data"
      :baseUrl="route('crafter.translations.index')"
      :withBulkSelect="false"
    >
      <template #actions>
        <FiltersDropdown
          :activeFiltersCount="activeFiltersCount"
          :resetFilters="resetFilters"
        >
          <Multiselect
            v-model="filtersForm.group"
            :options="groups"
            :label="$t('crafter', 'Groups')"
            mode="tags"
            name="groups"
          />
        </FiltersDropdown>
      </template>
      <template #tableHead>
        <ListingHeaderCell sortBy="group">
          {{ $t("crafter", "Group") }}
        </ListingHeaderCell>

        <ListingHeaderCell sortBy="key">
          {{ $t("crafter", "Default") }}
        </ListingHeaderCell>

        <ListingHeaderCell>
          {{ ($page.props as PageProps).auth.user.locale }}
        </ListingHeaderCell>

        <ListingHeaderCell>
          {{ $t("crafter", "Last update") }}
        </ListingHeaderCell>

        <ListingHeaderCell></ListingHeaderCell>
      </template>
      <template #tableRow="{ item, action }">
        <ListingDataCell>
          {{ item.group }}
        </ListingDataCell>

        <ListingDataCell>
          <div class="max-w-sm overflow-hidden text-ellipsis">
            {{ item.key }}
          </div>
        </ListingDataCell>

        <ListingDataCell>
          <div class="max-w-sm overflow-hidden text-ellipsis">
            {{ item.text[($page.props as PageProps).auth.user.locale] }}
          </div>
        </ListingDataCell>

        <ListingDataCell>
          {{ dayjs(item.updated_at).format("DD.MM.YYYY HH:mm") }}
        </ListingDataCell>

        <ListingDataCell>
          <div class="flex items-center justify-end gap-3">
            <EditTranslationModal
              :language-line="item"
              :locales="locales"
              v-can="'crafter.translation.edit'"
            ></EditTranslationModal>
          </div>
        </ListingDataCell>
      </template>
    </Listing>
  </PageContent>
</template>

<script lang="ts" setup>
import { EllipsisVerticalIcon } from "@heroicons/vue/24/solid";
import {
  Button,
  Listing,
  ListingDataCell,
  ListingHeaderCell,
  Multiselect,
  PageHeader,
  PageContent,
  IconButton,
  Dropdown,
  FiltersDropdown,
  DropdownItem,
  ButtonGroup,
} from "crafter/Components";
import { PaginatedCollection } from "crafter/types/pagination";
import type { LanguageLine } from "crafter/types/models";
import type { PageProps } from "crafter/types/page";
import { useAction } from "crafter/hooks/useAction";
import EditTranslationModal from "crafter/Pages/Translations/Components/EditTranslationModal.vue";
import ExportModal from "crafter/Pages/Translations/Components/ExportModal.vue";
import ImportModal from "crafter/Pages/Translations/Components/ImportModal.vue";
import { useToast } from "@brackets/vue-toastification";
import { ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useListingFilters } from "crafter/hooks/useListingFilters";
import dayjs from "dayjs";
import { ChevronDownIcon } from "@heroicons/vue/24/outline";

interface Props {
  data: PaginatedCollection<LanguageLine>;
  groups: string[];
  locales: string[];
}

const toast = useToast();
const { action } = useAction();
const exportModalOpened = ref<boolean>(false);
const importModalOpened = ref<boolean>(false);

defineProps<Props>();

const { filtersForm, resetFilters, activeFiltersCount } = useListingFilters(
  route("crafter.translations.index"),
  {
    group: (usePage().props as PageProps)?.filter?.group ?? null,
  }
);

const reload = () => {
  window.location.reload();
};
</script>
