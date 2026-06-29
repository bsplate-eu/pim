<template>
  <PageHeader :title="$t('crafter', 'Users')">
    <Modal alignButtons="right" size="sm">
      <template #trigger="{ setIsOpen }">
        <Button @click="() => setIsOpen(true)" :leftIcon="PlusIcon">
          {{ $t("crafter", "Invite user") }}
        </Button>
      </template>
      <template #title>
        {{ $t("crafter", "Invite user") }}
      </template>
      <template #content>
        <div class="mt-4 flex flex-col gap-2">
          <TextInput
            v-model="form.email"
            name="email"
            :label="$t('crafter', 'Email')"
          />
          <Multiselect
            v-model="form.role_id"
            name="role"
            :label="$t('crafter', 'Role')"
            mode="single"
            :options="filterOptions.roles"
            optionsValueProp="id"
            optionsLabel="name"
          />
        </div>
      </template>
      <template #buttons="{ setIsOpen }">
        <Button size="sm" :loading="form.processing" @click="submit(setIsOpen)">
          {{ $t("crafter", "Invite user") }}
        </Button>
        <Button
          size="sm"
          color="gray"
          variant="outline"
          @click.prevent="() => setIsOpen()"
        >
          {{ $t("crafter", "Cancel") }}
        </Button>
      </template>
    </Modal>
  </PageHeader>

  <PageContent>
    <Listing
      :baseUrl="route('crafter.admin-users.index')"
      :data="adminUsers"
      dataKey="adminUsers"
    >
      <template #actions>
        <FiltersDropdown
          :activeFiltersCount="activeFiltersCount"
          :resetFilters="resetFilters"
        >
          <Multiselect
            v-model="filtersForm.role"
            name="role"
            :label="$t('crafter', 'Role')"
            :options="filterOptions.roles"
          />
          <Multiselect
            v-model="filtersForm.status"
            name="status"
            :label="$t('crafter', 'Status')"
            :options="statusOptions"
            options-value-prop="id"
            options-label="label"
            mode="single"
          />
        </FiltersDropdown>
      </template>

      <template #bulkActions="{ baseUrl, bulkAction }">
        <!-- TODO: there was some kind of an idea to soft/force destroy? -->
        <Button
          @click="() => bulkAction('post', `${baseUrl}/bulk-activate`)"
          color="gray"
          variant="outline"
          size="sm"
          :leftIcon="ShieldCheckIcon"
          v-can="'crafter.admin-user.edit'"
        >
          {{ $t("crafter", "Activate") }}
        </Button>

        <Modal type="danger" v-can="'crafter.admin-user.destroy'">
          <template #trigger="{ setIsOpen }">
            <Button
              @click="setIsOpen(true)"
              color="gray"
              variant="outline"
              size="sm"
              :leftIcon="NoSymbolIcon"
            >
              {{ $t("crafter", "Deactivate") }}
            </Button>
          </template>

          <template #title
            >{{ $t("crafter", "Deactivate users") }}
          </template>

          <template #content>
            {{
              $t(
                "crafter",
                "Are you sure you want to deactivate selected users?"
              )
            }}
          </template>

          <template #buttons="{ setIsOpen }">
            <Button
              @click.prevent="
                () => bulkAction('post', `${baseUrl}/bulk-deactivate`)
              "
              color="danger"
            >
              {{ $t("crafter", "Deactivate") }}
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

        <Modal type="danger" v-can="'crafter.admin-user.destroy'">
          <template #trigger="{ setIsOpen }">
            <Button
              @click="() => setIsOpen(true)"
              color="gray"
              variant="outline"
              size="sm"
              :leftIcon="TrashIcon"
            >
              {{ $t("crafter", "Delete") }}
            </Button>
          </template>

          <template #title>{{ $t("crafter", "Delete users") }} </template>
          <template #content>
            {{
              $t(
                "crafter",
                "Are you sure you want to delete selected users? All of their data will be permanently removed from our servers forever. This action cannot be undone."
              )
            }}
          </template>

          <template #buttons="{ setIsOpen }">
            <!-- TODO: disable button while submitting... (done in other PR) -->
            <Button
              @click.prevent="
                () => {
                  bulkAction('delete', `${baseUrl}/bulk-destroy`, {
                    onFinish: () => setIsOpen(false),
                  });
                }
              "
              color="danger"
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
        <ListingHeaderCell sortBy="id" class="w-14">
          {{ $t("crafter", "ID") }}
        </ListingHeaderCell>

        <ListingHeaderCell sortBy="first_name">
          {{ $t("crafter", "User") }}
        </ListingHeaderCell>

        <ListingHeaderCell>
          {{ $t("crafter", "Role") }}
        </ListingHeaderCell>

        <ListingHeaderCell>
          {{ $t("crafter", "Status") }}
        </ListingHeaderCell>

        <ListingHeaderCell
          v-if="$page.props.config?.crafter?.track_user_last_active_time"
          sortBy="last_active_at"
        >
          {{ $t("crafter", "Last active") }}
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
          <div class="flex items-center">
            <Avatar
              :src="item.avatar_url"
              :name="`${item.first_name} ${item.last_name}`"
            />
            <div class="ml-4">
              <div class="font-medium text-gray-900">
                <!-- TODO: maybe have full_name attribute? -->
                {{ item.first_name }} {{ item.last_name }}
              </div>
              <div class="text-gray-500">{{ item.email }}</div>
            </div>
          </div>
        </ListingDataCell>

        <ListingDataCell>
          <span class="text-sm font-normal leading-5 text-slate-500">
            {{ item.roles.length > 0 ? item.roles[0].name : "" }}
          </span>
        </ListingDataCell>

        <ListingDataCell class="text-left">
          <template v-if="item.email_verified_at">
            <div v-if="item.active">
              <Tag :icon="CheckCircleIcon" color="success" rounded size="sm">
                {{ $t("crafter", "Active") }}
              </Tag>
            </div>

            <div v-else>
              <Tag :icon="XCircleIcon" color="gray" rounded size="sm">
                {{ $t("crafter", "Inactive") }}
              </Tag>
            </div>
          </template>

          <div v-else>
            <Tag
              :icon="ExclamationCircleIcon"
              color="warning"
              rounded
              size="sm"
            >
              {{ $t("crafter", "Pending") }}
            </Tag>
          </div>
        </ListingDataCell>

        <ListingDataCell
          v-if="$page.props.config?.crafter?.track_user_last_active_time"
        >
          <div v-if="item.email_verified_at" class="flex gap-2">
            <div v-if="item.last_active_at === null">
              {{ $t("crafter", "Never") }}
            </div>

            <template v-else>
              <div class="flex flex-col justify-center">
                <ClockIcon class="h-4 w-4" />
              </div>
              <div>
                {{ dayjs(item.last_active_at).format("DD.MM.YYYY HH:mm") }}
              </div>
            </template>
          </div>

          <template v-else>
            <Button
              variant="outline"
              color="gray"
              @click.prevent="
                () => {
                  action(
                    'post',
                    `admin-users/${item.id}/resend-verification-email`
                  );
                }
              "
              size="sm"
            >
              {{ $t("crafter", "Resend invitation") }}
            </Button>
          </template>
        </ListingDataCell>

        <ListingDataCell>
          <div class="flex justify-end">
            <Dropdown
              noContentPadding
              :placement="isLastThreeItems(item) ? 'bottom-end' : 'top-end'"
            >
              <template #button>
                <IconButton
                  :icon="EllipsisVerticalIcon"
                  variant="outline"
                  color="gray"
                  size="sm"
                />
              </template>

              <template #content class="bg-red">
                <div class="py-1">
                  <DropdownItem
                    v-can="'crafter.admin-user.edit'"
                    :href="`${item.resource_url}/edit`"
                    :icon="PencilSquareIcon"
                  >
                    {{ $t("crafter", "Edit") }}
                  </DropdownItem>

                  <template v-if="item.email_verified_at">
                    <DropdownItem
                      @click="changeActiveStatus(item)"
                      :icon="item.active ? NoSymbolIcon : ShieldCheckIcon"
                    >
                      {{
                        item.active
                          ? $t("crafter", "Deactivate")
                          : $t("crafter", "Activate")
                      }}
                    </DropdownItem>
                  </template>

                  <DropdownItem
                    v-else
                    @click="
                      () => {
                        action(
                          'post',
                          `admin-users/${item.id}/resend-verification-email`
                        );
                      }
                    "
                    :icon="EnvelopeIcon"
                  >
                    {{ $t("crafter", "Resend invitation") }}
                  </DropdownItem>

                  <div>
                    <Modal
                      type="danger"
                      v-can="'crafter.admin-user.destroy'"
                    >
                      <template #trigger="{ setIsOpen }">
                        <div
                          @click="() => setIsOpen(true)"
                          class="flex cursor-pointer gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                        >
                          <div class="flex flex-col justify-center">
                            <TrashIcon class="h-4 w-4" />
                          </div>
                          {{ $t("crafter", "Delete") }}
                        </div>
                      </template>

                      <template #title>
                        {{ $t("crafter", "Delete user") }}
                      </template>

                      <template #content>
                        {{
                          $t(
                            "crafter",
                            "Are you sure you want to delete selected user? All of his data will be permanently removed from our servers forever. This action cannot be undone."
                          )
                        }}
                      </template>

                      <template #buttons="{ setIsOpen }">
                        <Button
                          @click.prevent="
                            () => {
                              action('delete', item.resource_url, {
                                onFinish: () => setIsOpen(false),
                              });
                            }
                          "
                          color="danger"
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

                  <DropdownItem
                    v-can="'crafter.admin-user.impersonal-login'"
                    v-if="item.id !== $page.props.auth.user.id"
                    :href="
                      route(
                        'crafter.admin-user.impersonalLogin',
                        {
                          adminUser: item.id,
                        }
                      )
                    "
                    :icon="ArrowLeftOnRectangleIcon"
                  >
                    {{ $t("crafter", "Log as user") }}
                  </DropdownItem>
                </div>
              </template>
            </Dropdown>
          </div>
        </ListingDataCell>
      </template>
    </Listing>
  </PageContent>
</template>

<script setup lang="ts">
import { Link, usePage, useForm } from "@inertiajs/vue3";

import {
  PlusIcon,
  TrashIcon,
  PencilSquareIcon,
  ClockIcon,
  ArrowLeftOnRectangleIcon,
  EllipsisVerticalIcon,
  EnvelopeIcon,
} from "@heroicons/vue/24/outline";
import { NoSymbolIcon, ShieldCheckIcon } from "@heroicons/vue/24/solid";
import {
  CheckCircleIcon,
  ExclamationCircleIcon,
  XCircleIcon,
} from "@heroicons/vue/20/solid";
import {
  PageHeader,
  PageContent,
  Button,
  Listing,
  Avatar,
  ListingHeaderCell,
  ListingDataCell,
  Modal,
  IconButton,
  FiltersDropdown,
  Multiselect,
  Tag,
  Dropdown,
  DropdownItem,
  TextInput,
} from "crafter/Components";
import { PaginatedCollection } from "crafter/types/pagination";
import type { AdminUser } from "crafter/types/models";
import { useAction } from "crafter/hooks/useAction";
import { useListingFilters } from "crafter/hooks/useListingFilters";
import { PageProps } from "crafter/types/page";
import { wTrans } from "crafter/plugins/laravel-vue-i18n";
import dayjs from "dayjs";
import { AdminUserInviteUserForm } from "./types";
import { useToast } from "@brackets/vue-toastification";

interface Props {
  adminUsers: PaginatedCollection<AdminUser>;
  filterOptions: {
    roles: string[];
  };
}

const { action } = useAction();

const changeActiveStatus = (item: AdminUser) => {
  action("patch", route("crafter.admin-users.update", item.id), {
    active: !item.active,
  });
};

const statusOptions = [
  { id: "true", label: wTrans("crafter", "Active") },
  { id: "false", label: wTrans("crafter", "Inactive") },
  { id: "pending", label: wTrans("crafter", "Pending") },
];

const props = defineProps<Props>();

const { filtersForm, resetFilters, activeFiltersCount } = useListingFilters(
  "/admin/admin-users",
  {
    role: (usePage().props as PageProps).filter?.role ?? null,
    status: (usePage().props as PageProps).filter?.status ?? null,
  }
);

const isLastThreeItems = (item: AdminUser) => {
  const arrLength = props.adminUsers.data.length;

  let lastElement = props.adminUsers.data[arrLength - 1];
  let beforeLastElement = props.adminUsers.data[arrLength - 2];

  return lastElement.id === item.id || beforeLastElement.id === item.id;
};

const toast = useToast();

const form = useForm({
  email: "",
  role_id: "",
});

const submit = (closeModal: CallableFunction) => {
  form.post(route("crafter.admin-user.invite-user"), {
    onSuccess: () => {
      form.email = "";
      form.role_id = "";

      closeModal();

      toast.success(usePage().props?.message);
    },
  });
};
</script>
