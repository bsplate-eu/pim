<template>
  <PageHeader
    sticky
    :title="`${$t('crafter', 'Edit permissions for role')} ${role.name}`"
  >
    <Button
      :as="Link"
      :href="route('crafter.roles.index')"
      color="gray"
      variant="outline"
    >
      Wróć do listy
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
    <Card class="mb-6">
      <div class="grid grid-cols-6 gap-6">
        <TextInput
          v-model="form.name"
          name="name"
          label="Nazwa roli"
          :disabled="isProtected"
          class="col-span-6 sm:col-span-3"
        />
      </div>
      <p v-if="isProtected" class="mt-2 text-sm text-gray-500">
        To rola systemowa — nazwy nie można zmienić. Uprawnienia owszem.
      </p>
    </Card>

    <Form :form="form" :role="role" :submit="submit" />
  </PageContent>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import {
  PageHeader,
  PageContent,
  Button,
  Card,
  TextInput,
} from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type { Role } from "crafter/types/models";

interface Props {
  role: Role;
  permissionsTree: any;
}

const props = defineProps<Props>();

// Musi być zgodne z RoleController::PROTECTED_ROLES — serwer i tak odrzuci
// zmianę nazwy, tu chodzi tylko o to, żeby pole było wyszarzone od razu.
const PROTECTED_ROLES = ["Administrator", "Guest"];

const isProtected = computed(() => PROTECTED_ROLES.includes(props.role.name));

const { form, submit } = useForm<any>(
  {
    name: props.role.name,
    permissionsTree: props.permissionsTree,
  },
  route("crafter.roles.update", [props.role.id])
);
</script>
