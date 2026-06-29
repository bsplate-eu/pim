<template>
  <Card :title="$t('crafter', 'Profile')">
    <div class="grid grid-cols-6 gap-6">
      <ImageUpload
        v-model="form.avatar"
        name="avatar"
        :label="$t('crafter', 'User photo')"
        class="col-span-6"
      />
      <TextInput
        v-model="form.first_name"
        name="first_name"
        :label="$t('crafter', 'First name')"
        class="col-span-6 sm:col-span-3"
      />
      <TextInput
        v-model="form.last_name"
        name="last_name"
        :label="$t('crafter', 'Last name')"
        class="col-span-6 sm:col-span-3"
      />
      <TextInput
        v-model="form.email"
        name="email"
        :label="$t('crafter', 'E-mail')"
        type="email"
        class="col-span-6 sm:col-span-3"
        disabled
      />
      <Multiselect
        v-model="form.locale"
        name="locale"
        :label="$t('crafter', 'Locale')"
        mode="single"
        :options="availableLocales"
        class="col-span-6 sm:col-span-3 sm:col-start-1"
        :canDeselect="false"
      >
        <template #singlelabel="{ value }">
          <LocaleFlag :locale="value.value" />
        </template>
        <template #option="{ option, search }">
          <LocaleFlag :locale="option.value" />
        </template>
      </Multiselect>
    </div>
  </Card>
</template>

<script setup lang="ts">
import { computed } from "vue";
import {
  Card,
  ImageUpload,
  Multiselect,
  TextInput,
  LocaleFlag,
} from "crafter/Components";
import { InertiaForm, usePage } from "@inertiajs/vue3";
import type { AdminUserProfileForm } from "../types";

interface Props {
  form: InertiaForm<AdminUserProfileForm>;
}

const props = defineProps<Props>();

const availableLocales = computed(() => {
  return usePage().props.settings.available_locales;
});
</script>
