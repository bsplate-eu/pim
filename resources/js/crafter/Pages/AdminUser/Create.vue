<template>
  <PageHeader sticky :title="$t('crafter', 'Create user')">
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
    <Form
      :locales="locales"
      :form="form"
      :submit="submit"
      :roles="roles"
      :mailAccounts="mailAccounts"
      :rolesWithAllMailAccess="rolesWithAllMailAccess"
    />
  </PageContent>
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, PageContent, Button } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type { AdminUserForm } from "./types";

interface Props {
  roles: any[];
  locales?: string[];
  defaultLocale: string;
  mailAccounts?: { id: number; name: string }[];
  rolesWithAllMailAccess?: number[];
}

const props = withDefaults(defineProps<Props>(), {
  locales: () => ["en"],
  defaultLocale: 'en',
  mailAccounts: () => [],
  rolesWithAllMailAccess: () => [],
});

const { form, submit } = useForm<AdminUserForm>(
  {
    first_name: "",
    last_name: "",
    email: "",
    password: "",
    password_confirmation: "",
    locale: props.defaultLocale,
    active: true,
    role_id: null,
    avatar: [],
    mail_account_ids: [],
  },
  route("crafter.admin-users.store"),
  "post"
);
</script>
