<template>
  <PageHeader
    sticky
    :title="`${$t('crafter', 'Edit permissions for role')} ${role.name}`"
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
    <Form :form="form" :role="role" :submit="submit" />
  </PageContent>
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, PageContent, Button } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type { Role } from "crafter/types/models";

interface Props {
  role: Role;
  permissionsTree: any;
}

const props = defineProps<Props>();

const { form, submit } = useForm<any>(
  {
    permissionsTree: props.permissionsTree,
  },
  route("crafter.roles.update", [props.role.id])
);
</script>
