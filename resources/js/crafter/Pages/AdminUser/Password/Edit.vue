<template>
  <PageHeader sticky :title="$t('crafter', 'Password')">
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
    <div class="flex w-full flex-col gap-6">
      <PasswordCard :form="form" />
    </div>
  </PageContent>
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, PageContent, Button } from "crafter/Components";
import type { AdminUser } from "crafter/types/models";
import { useForm } from "crafter/hooks/useForm";
import PasswordCard from "../Components/PasswordCard.vue";

interface Props {
  adminUser: AdminUser;
}

const props = defineProps<Props>();

const { form, submit } = useForm(
  {
    password: "",
    password_confirmation: "",
  },
  route("crafter.admin-users.password.update")
);
</script>
