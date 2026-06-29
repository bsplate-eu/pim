<template>
  <Modal class="flex items-center justify-center">
    <template #trigger="{ setIsOpen }">
      <ToolbarButton
        @click="
          () => {
            form.url = editor?.getAttributes('link').href;
            setIsOpen(true);
          }
        "
        :active="editor?.isActive('link')"
        :icon="LinkIcon"
      />
    </template>
    <template #title> {{ $t("crafter", "Add link") }} </template>
    <template #content>
      <TextInput
        name="link_url"
        :label="$t('crafter', 'URL')"
        v-model="form.url"
      />
    </template>
    <template #buttons="{ setIsOpen }">
      <Button
        @click="
          () => {
            setIsOpen(false);
            submit();
          }
        "
      >
        {{ $t("crafter", "Submit") }}
      </Button>
      <Button variant="outline" @click="() => setIsOpen(false)">
        {{ $t("crafter", "Cancel") }}
      </Button>
    </template>
  </Modal>
</template>
<script setup lang="ts">
import ToolbarButton from "./ToolbarButton.vue";
import { Modal, Button, TextInput } from "crafter/Components";
import { useForm } from "@inertiajs/vue3";
import { LinkIcon } from "./icons";
import { Editor } from "@tiptap/vue-3";

const emit = defineEmits(["linkAdded"]);

interface Props {
  editor?: Editor;
}

const props = defineProps<Props>();

const form = useForm({
  url: "",
});

const submit = () => {
  emit("linkAdded", form.url);
  form.url = "";
};
</script>
