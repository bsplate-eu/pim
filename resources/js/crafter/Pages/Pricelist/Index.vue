<template>
  <PageHeader :title="$t('crafter', 'Pricelists')">
    <Button
      :leftIcon="PlusIcon"
      :as="Link"
      :href="route('crafter.pricelists.create')"
      v-can="'crafter.pricelist.create'"
    >
      {{ $t("crafter", "New Pricelist") }}
    </Button>

  </PageHeader>

  <PageContent>
    <Listing
      :baseUrl="route('crafter.pricelists.index')"
      :data="pricelists"
      dataKey="pricelists"
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
              v-can="'crafter.pricelist.destroy'"
            >
              {{ $t("crafter", "Delete") }}
            </Button>
          </template>

          <template #title>
            {{ $t("crafter", "Delete Pricelist") }}
          </template>
          <template #content>
            {{
              $t(
                "crafter",
                "Are you sure you want to delete selected Pricelist? All data will be permanently removed from our servers forever. This action cannot be undone."
              )
            }}
          </template>

          <template #buttons="{ setIsOpen }">
            <Button
              @click.prevent="
                () => {
                  bulkAction('post', route('crafter.pricelists.bulk-destroy'), {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
              color="danger"
              v-can="'crafter.pricelist.destroy'"
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
        <ListingHeaderCell sortBy="position">
            Kolejność
        </ListingHeaderCell>
        <ListingHeaderCell sortBy="name">
            {{ $t("crafter", "Name") }}
        </ListingHeaderCell>
        <ListingHeaderCell sortBy="currency">
            {{ $t("crafter", "Currency") }}
        </ListingHeaderCell>
        <ListingHeaderCell>
          <span class="sr-only">{{ $t("crafter", "Actions") }}</span>
        </ListingHeaderCell>
      </template>
      <template #tableRow="{ item, action }: any">

<!--        <ListingDataCell>-->
<!--             {{ item.id }}-->
<!--        </ListingDataCell> -->
        <ListingDataCell class="w-24">
          <SelectInput
            :name="`position-${item.id}`"
            size="sm"
            :options="positionOptions"
            :modelValue="item.position"
            @update:modelValue="
              (position: number) => moveTo(item, position, action)
            "
            v-can="'crafter.pricelist.edit'"
          />
          <span v-can:not="'crafter.pricelist.edit'" class="text-gray-500">
            {{ item.position }}
          </span>
        </ListingDataCell>
        <ListingDataCell>
             {{ item.name }}
        </ListingDataCell>
        <ListingDataCell>
             {{ item.currency }}
        </ListingDataCell>
        <ListingDataCell>
          <div class="flex items-center justify-end gap-3">
            <IconButton
              :as="Link"
              :href="route('crafter.pricelists.edit', item)"
              variant="ghost"
              color="gray"
              :icon="PencilSquareIcon"
              v-can="'crafter.pricelist.edit'"
            />

            <Modal>
              <template #trigger="{ setIsOpen }">
                <IconButton
                  @click="() => setIsOpen(true)"
                  color="gray"
                  variant="ghost"
                  :icon="DocumentDuplicateIcon"
                  v-can="'crafter.pricelist.create'"
                />
              </template>

              <template #title>Stwórz kopię cennika</template>
              <template #content>
                Cennik <strong>{{ item.name }}</strong> zostanie zduplikowany razem ze
                wszystkimi cenami. Powstanie nowa pozycja z sufiksem „(kopia)".
              </template>

              <template #buttons="{ setIsOpen }">
                <Button
                  @click.prevent="
                    () => {
                      action('post', route('crafter.pricelists.clone', item), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                  v-can="'crafter.pricelist.create'"
                >
                  Stwórz kopię
                </Button>
                <Button
                  @click.prevent="() => setIsOpen()"
                  color="gray"
                  variant="outline"
                >
                  Anuluj
                </Button>
              </template>
            </Modal>

            <Modal type="danger">
              <template #trigger="{ setIsOpen }">
                <IconButton
                  @click="() => setIsOpen(true)"
                  color="gray"
                  variant="ghost"
                  :icon="TrashIcon"
                  v-can="'crafter.pricelist.destroy'"
                />
              </template>

              <template #title>
                {{ $t("crafter", "Delete Pricelist") }}
              </template>
              <template #content>
                {{
                  $t(
                    "crafter",
                    "Are you sure you want to delete selected Pricelist? All data will be permanently removed from our servers forever. This action cannot be undone."
                  )
                }}
              </template>

              <template #buttons="{ setIsOpen }">
                <Button
                  @click.prevent="
                    () => {
                      action('delete', route('crafter.pricelists.destroy', item), {
                        onFinish: () => setIsOpen(false),
                      });
                    }
                  "
                  color="danger"
                  v-can="'crafter.pricelist.destroy'"
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
import { computed } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import {
    PlusIcon,
    TrashIcon,
    PencilSquareIcon,
    ArrowDownTrayIcon,
    DocumentDuplicateIcon,
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
    SelectInput,
} from "crafter/Components";
import { PaginatedCollection } from "crafter/types/pagination";
import type { Pricelist } from "./types";
import type { PageProps } from "crafter/types/page";
import dayjs from "dayjs";



interface Props {
  pricelists: PaginatedCollection<Pricelist>;
  positionsCount: number;
}
const props = defineProps<Props>();

// Dropdown "Kolejność" ma tyle pozycji, ile jest cenników łącznie (nie tylko na tej stronie).
const positionOptions = computed(() =>
  Array.from({ length: props.positionsCount }, (_, index) => index + 1)
);

/** Przestawia cennik na wybraną pozycję; reszta listy przesuwa się sama. */
const moveTo = (item: Pricelist, position: number, action: Function) => {
  if (Number(position) === Number(item.position)) {
    return;
  }

  action("post", route("crafter.pricelists.reorder", item), {
    position: Number(position),
  });
};

</script>
