<template>
  <PageHeader :title="$t('crafter', 'Roles')">
    <Button :as="Link" :href="`permissions`" v-can="'crafter.role.edit'">
      {{ $t("crafter", "Manage permissions") }}
    </Button>
  </PageHeader>

  <PageContent>
    <Listing
      :baseUrl="route('crafter.roles.index')"
      :data="roles"
      dataKey="roles"
      :withBulkSelect="false"
    >
      <template #tableHead>
        <ListingHeaderCell sortBy="id" class="w-14">
          {{ $t("crafter", "ID") }}
        </ListingHeaderCell>
        <ListingHeaderCell sortBy="name">
          {{ $t("crafter", "Name") }}
        </ListingHeaderCell>
        <ListingHeaderCell>
          {{ $t("crafter", "Users") }}
        </ListingHeaderCell>
      </template>
      <template #tableRow="{ item, action }: any">
        <ListingDataCell>
          {{ item.id }}
        </ListingDataCell>
        <ListingDataCell>
          <div class="font-medium text-gray-900">
            {{ item.name }}
          </div>
        </ListingDataCell>
        <ListingDataCell>
          <AvatarGroup
            :additionalCount="
              item.users.length > avatarGroupLimit
                ? item.users.length - avatarGroupLimit
                : undefined
            "
            :additionalHref="
              route('crafter.admin-users.index', {
                filter: { role: [item.name] },
              })
            "
          >
            <Avatar
              v-for="user in item.users.slice(0, avatarGroupLimit)"
              :key="user.id"
              :src="user.avatar_url"
              :name="`${user.first_name} ${user.last_name}`"
            />
          </AvatarGroup>
        </ListingDataCell>
      </template>
    </Listing>
  </PageContent>
</template>

<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import {
  PageHeader,
  PageContent,
  Listing,
  ListingHeaderCell,
  ListingDataCell,
  IconButton,
  Button,
  Avatar,
  AvatarGroup,
} from "crafter/Components";
import { PaginatedCollection } from "crafter/types/pagination";
import type { Role } from "crafter/types/models";
import { ref } from "vue";

interface Props {
  roles: PaginatedCollection<Role>;
}

defineProps<Props>();

const avatarGroupLimit = ref(7);
</script>
