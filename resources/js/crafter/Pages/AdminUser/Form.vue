<template>
  <div class="flex w-full flex-col gap-6">
    <ProfileCard :form="form" :locales="locales" />

    <PasswordCard
      :form="form"
      :title="$t('crafter', 'Role and password')"
    >
      <Multiselect
        v-model="form.role_id"
        name="role"
        :label="$t('crafter', 'Role')"
        mode="single"
        :options="roles"
        optionsValueProp="id"
        optionsLabel="name"
        class="col-span-6 sm:col-span-3"
      />
    </PasswordCard>
  </div>
</template>

<script setup lang="ts">
import { Card, Multiselect } from "crafter/Components";
import { InertiaForm } from "@inertiajs/vue3";
import type { AdminUserForm } from "./types";
import { AdminUser } from "crafter/types/models";
import ProfileCard from "./Components/ProfileCard.vue";
import PasswordCard from "./Components/PasswordCard.vue";

interface Props {
  form: InertiaForm<AdminUserForm>;
  submit: void;
  adminUser?: AdminUser;
  roles: any[];
  locales?: string[];
}

const props = withDefaults(defineProps<Props>(), {
  locales: () => ["en"],
});
</script>
