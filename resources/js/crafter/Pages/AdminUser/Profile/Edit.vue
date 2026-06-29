<template>
  <PageHeader
    sticky
    :title="$t('crafter', 'Profile')"
    :subtitle="
      $t('crafter', 'Last updated at :updated_at', {
        updated_at: dayjs(adminUser.updated_at).format(
          'DD.MM.YYYY HH:mm'
        ),
      })
    "
  >
    <div class="flex gap-3">
      <Button
        :leftIcon="ArrowDownTrayIcon"
        @click="submit"
        :loading="form.processing"
      >
        {{ $t("crafter", "Save") }}
      </Button>
    </div>
  </PageHeader>

  <PageContent>
    <div class="w-full">
      <ProfileCard :form="form" />
    </div>
  </PageContent>
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import {
  PageHeader,
  PageContent,
  Button,
  Multiselect,
} from "crafter/Components";
import type { AdminUser } from "crafter/types/models";
import { useForm } from "crafter/hooks/useForm";
import dayjs from "dayjs";
import ProfileCard from "../Components/ProfileCard.vue";

interface Props {
  adminUser: AdminUser;
}

const props = defineProps<Props>();

const { form, submit } = useForm(
  {
    first_name: props.adminUser.first_name ?? "",
    last_name: props.adminUser.last_name ?? "",
    email: props.adminUser.email ?? "",
    locale: props.adminUser.locale ?? "",
    avatar: props.adminUser.avatar ?? [],
  },
  route("crafter.admin-users.profile.update")
);
</script>
