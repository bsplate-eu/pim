<template>
  <Modal :open="open" @toggle-open="toggleOpen" :external-open="true">
    <template #title>{{ $t("crafter", "Export translations") }}</template>
    <template #content>
      <div class="space-y-6">
        <Multiselect
          v-model="form.exportLanguages"
          :options="locales"
          :label="$t('crafter', 'Languages')"
          mode="tags"
          name=""
        />
      </div>
    </template>

    <template #buttons="{ setIsOpen }">
      <Button :loading="form.processing" @click.prevent="submit(setIsOpen)">
        {{ $t("crafter", "Export") }}
      </Button>
      <Button color="gray" variant="outline" @click.prevent="() => setIsOpen()">
        {{ $t("crafter", "Cancel") }}
      </Button>
    </template>
  </Modal>
</template>

<script lang="ts" setup>
import { Button, Modal, Multiselect } from "crafter/Components";
import { useForm } from "@inertiajs/vue3";

interface Props {
  locales: string[];
  open: boolean;
}

const props = defineProps<Props>();

const form = useForm({
  exportLanguages: [],
});

const submit = (setIsOpen: Function) => {
  window.location.href =
    "/admin/translations/export?exportLanguages[]=" +
    form.exportLanguages.join("&exportLanguages[]=");
  setIsOpen();
};

const emit = defineEmits(["toggleOpen"]);

const toggleOpen = () => {
  emit("toggleOpen");
};
</script>
