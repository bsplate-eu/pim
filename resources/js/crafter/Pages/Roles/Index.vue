<template>
  <PageHeader :title="$t('crafter', 'Roles')">
    <Button
      :as="Link"
      :href="route('crafter.permissions.index')"
      color="gray"
      variant="outline"
      v-can="'crafter.permission.index'"
    >
      {{ $t("crafter", "Manage permissions") }}
    </Button>
    <Button
      :as="Link"
      :href="route('crafter.roles.create')"
      :leftIcon="PlusIcon"
      v-can="'crafter.role.edit'"
    >
      Dodaj rolę
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
        <ListingHeaderCell class="w-32"> Uprawnienia </ListingHeaderCell>
        <ListingHeaderCell>
          {{ $t("crafter", "Users") }}
        </ListingHeaderCell>
        <ListingHeaderCell class="w-24" />
      </template>
      <template #tableRow="{ item, action }: any">
        <ListingDataCell>
          {{ item.id }}
        </ListingDataCell>
        <ListingDataCell>
          <div class="flex items-center gap-2">
            <span class="font-medium text-gray-900">{{ item.name }}</span>
            <span
              v-if="protectedRoles.includes(item.name)"
              class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-500"
              title="Rola systemowa — nie można jej usunąć ani zmienić nazwy"
            >
              systemowa
            </span>
          </div>
        </ListingDataCell>
        <ListingDataCell>
          <span class="text-sm text-gray-600">
            {{ item.permissions_count }}
          </span>
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
          <span v-if="item.users.length === 0" class="text-sm text-gray-400">
            brak
          </span>
        </ListingDataCell>
        <ListingDataCell>
          <div class="flex justify-end gap-1">
            <IconButton
              :as="Link"
              :href="route('crafter.roles.edit', item.id)"
              :icon="PencilSquareIcon"
              variant="outline"
              color="gray"
              size="sm"
              v-can="'crafter.role.edit'"
            />

            <Modal
              type="danger"
              v-can="'crafter.role.edit'"
              v-if="!protectedRoles.includes(item.name)"
            >
              <template #trigger="{ setIsOpen }">
                <IconButton
                  @click="() => setIsOpen(true)"
                  :icon="TrashIcon"
                  variant="outline"
                  color="danger"
                  size="sm"
                />
              </template>

              <template #title> Usunąć rolę „{{ item.name }}"? </template>

              <template #content>
                <template v-if="item.users.length > 0">
                  Rola jest przypisana do {{ item.users.length }} użytkownik(ów).
                  Najpierw przepnij ich na inną rolę — inaczej straciliby dostęp
                  do panelu.
                </template>
                <template v-else>
                  Rola zostanie usunięta wraz z przypisanymi jej uprawnieniami.
                  Tej operacji nie da się cofnąć.
                </template>
              </template>

              <template #buttons="{ setIsOpen }">
                <Button
                  color="danger"
                  :disabled="item.users.length > 0"
                  @click.prevent="
                    () => {
                      action(
                        'delete',
                        route('crafter.roles.destroy', item.id),
                        {},
                        { onFinish: () => setIsOpen(false) }
                      );
                    }
                  "
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
  Modal,
} from "crafter/Components";
import {
  PlusIcon,
  PencilSquareIcon,
  TrashIcon,
} from "@heroicons/vue/24/outline";
import { PaginatedCollection } from "crafter/types/pagination";
import type { Role } from "crafter/types/models";
import { ref } from "vue";

interface Props {
  roles: PaginatedCollection<Role>;
  protectedRoles: string[];
}

withDefaults(defineProps<Props>(), {
  protectedRoles: () => [],
});

const avatarGroupLimit = ref(7);
</script>
