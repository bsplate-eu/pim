<template>
  <PageHeader sticky title="Nowa rola">
    <Button
      :as="Link"
      :href="route('crafter.roles.index')"
      color="gray"
      variant="outline"
    >
      {{ $t("crafter", "Cancel") }}
    </Button>
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
    >
      {{ $t("crafter", "Save") }}
    </Button>
  </PageHeader>

  <PageContent>
    <Card>
      <div class="grid grid-cols-6 gap-6">
        <TextInput
          v-model="form.name"
          name="name"
          label="Nazwa roli"
          placeholder="np. Księgowość"
          class="col-span-6 sm:col-span-3"
        />

        <Multiselect
          v-model="form.copy_from_role_id"
          name="copy_from_role_id"
          label="Skopiuj uprawnienia z roli (opcjonalnie)"
          mode="single"
          :options="roles"
          optionsValueProp="id"
          optionsLabel="name"
          class="col-span-6 sm:col-span-3"
        />
      </div>

      <p class="mt-4 text-sm text-gray-500">
        Nowa rola zawsze dostaje uprawnienie „Dostęp do panelu". Resztę ustawisz
        na następnym ekranie albo w macierzy uprawnień.
      </p>
    </Card>
  </PageContent>
</template>

<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import {
  PageHeader,
  PageContent,
  Button,
  Card,
  TextInput,
  Multiselect,
} from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";

interface Props {
  roles: { id: number; name: string }[];
}

defineProps<Props>();

const { form, submit } = useForm<{
  name: string;
  copy_from_role_id: number | null;
}>(
  {
    name: "",
    copy_from_role_id: null,
  },
  route("crafter.roles.store"),
  "post"
);
</script>
