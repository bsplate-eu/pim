<template>
  <PageHeader
    sticky
    :title="$t('crafter', 'Edit user')"
    :subtitle="`Last updated at ${dayjs(adminUser.updated_at).format(
      'DD.MM.YYYY HH:mm'
    )}`"
  >
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
    >
      {{ $t("crafter", "Save") }}
    </Button>
  </PageHeader>

  <PageContent>
    <Form
      :locales="locales"
      :form="form"
      :adminUser="adminUser"
      :submit="submit"
      :roles="roles"
    />
  </PageContent>
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, PageContent, Button } from "crafter/Components";
import { useForm } from "@inertiajs/vue3";
import Form from "./Form.vue";
import type { AdminUser } from "crafter/types/models";
import dayjs from "dayjs";
import type { UploadedFile } from "../../types";
import type { Role } from "../../types/models";
import isNull from "lodash/isNull";
import omitBy from "lodash/omitBy";

interface Props {
  adminUser: AdminUser;
  avatar: UploadedFile[];
  roles: Role[];
  locales: string[];
}

const props = defineProps<Props>();

const form = useForm({
  first_name: props.adminUser.first_name ?? "",
  last_name: props.adminUser.last_name ?? "",
  email: props.adminUser.email ?? "",
  password: null,
  password_confirmation: null,
  locale: props.adminUser.locale ?? "",
  active: props.adminUser.active ?? false,
  role_id: props.adminUser.roles
    ? props.adminUser.roles?.[0]?.id
    : null,
  avatar: props.adminUser.avatar ?? [],
});

const submit = () => {
  form
    .transform((data) => omitBy(data as object, isNull))
    .put(
      route("crafter.admin-users.update", [
        props.adminUser.id,
      ])
    );
};
</script>
