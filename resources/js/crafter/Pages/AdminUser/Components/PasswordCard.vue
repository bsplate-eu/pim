<template>
  <Card :title="title">
    <div class="grid grid-cols-6 gap-6">
      <slot></slot>
      <TextInput
        v-model="form.password"
        name="password"
        :label="$t('crafter', 'Password')"
        type="password"
        autocomplete="new-password"
        class="col-span-6 sm:col-span-3 sm:col-start-1"
      />
      <TextInput
        v-model="form.password_confirmation"
        name="password_confirmation"
        :label="$t('crafter', 'Password confirmation')"
        type="password"
        autocomplete="new-password"
        class="col-span-6 sm:col-span-3 sm:col-start-1"
      />
    </div>
  </Card>
</template>

<script setup lang="ts">
import { Card, TextInput } from "crafter/Components";
import { InertiaForm } from "@inertiajs/vue3";
import type { AdminUserPasswordForm } from "../types";
import { wTrans } from "crafter/plugins/laravel-vue-i18n";

interface Props {
  form: InertiaForm<AdminUserPasswordForm>;
  title?: string;
}

const props = withDefaults(defineProps<Props>(), {
  title: wTrans("crafter", "Password").value,
});
</script>
