<template>
  <PageHeader :title="'Matryca tłumaczeń'">
    <div class="text-sm text-gray-500">
      {{ phrases.total }} fraz · top-1 pokrywa {{ phrases.data[0]?.product_count ?? 0 }} produktów
    </div>
  </PageHeader>

  <PageContent>
    <Listing
      :baseUrl="route('crafter.translation-phrases.index')"
      :data="phrases"
      dataKey="phrases"
    >
      <template #tableHead>
        <ListingHeaderCell sortBy="id">ID</ListingHeaderCell>
        <ListingHeaderCell sortBy="phrase_pl">PL — fraza (typ produktu)</ListingHeaderCell>
        <ListingHeaderCell sortBy="product_count">Produktów</ListingHeaderCell>
        <ListingHeaderCell sortBy="renditions_count">Kanałów</ListingHeaderCell>
        <ListingHeaderCell><span class="sr-only">Akcje</span></ListingHeaderCell>
      </template>
      <template #tableRow="{ item }: any">
        <ListingDataCell>{{ item.id }}</ListingDataCell>
        <ListingDataCell>
          <div class="font-medium">{{ item.phrase_pl }}</div>
          <div class="text-xs text-gray-500 font-mono">{{ item.slug }}</div>
        </ListingDataCell>
        <ListingDataCell>
          <span class="font-mono">{{ item.product_count }}</span>
        </ListingDataCell>
        <ListingDataCell>
          <Tag :color="item.renditions_count >= 10 ? 'success' : item.renditions_count >= 6 ? 'warning' : 'danger'" size="sm" rounded>
            {{ item.renditions_count }} / 11
          </Tag>
        </ListingDataCell>
        <ListingDataCell>
          <div class="flex items-center justify-end gap-3">
            <IconButton
              :as="Link"
              :href="route('crafter.translation-phrases.edit', item)"
              variant="ghost"
              color="gray"
              :icon="PencilSquareIcon"
            />
          </div>
        </ListingDataCell>
      </template>
    </Listing>
  </PageContent>
</template>

<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { PencilSquareIcon } from "@heroicons/vue/24/outline";
import {
    PageHeader,
    PageContent,
    Listing,
    ListingHeaderCell,
    ListingDataCell,
    IconButton,
    Tag,
} from "crafter/Components";
import { PaginatedCollection } from "crafter/types/pagination";

interface Phrase {
  id: number;
  slug: string;
  phrase_pl: string;
  product_count: number;
  renditions_count: number;
}

interface Props {
  phrases: PaginatedCollection<Phrase>;
  channelLabels: Record<string, string>;
}

defineProps<Props>();
</script>
