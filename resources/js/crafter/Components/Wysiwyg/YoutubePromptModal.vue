<template>
  <Modal class="flex items-center justify-center">
    <template #trigger="{ setIsOpen }">
      <ToolbarButton @click="() => setIsOpen(true)" :icon="VideoCameraIcon" />
    </template>
    <template #title> {{ $t("crafter", "Add Youtube video") }} </template>
    <template #content>
      <TextInput
        name="youtube_url"
        :label="$t('crafter', 'Youtube URL')"
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
import { VideoCameraIcon } from "@heroicons/vue/24/solid";

const emit = defineEmits(["youtubeAdded"]);

interface Props {
  url?: string;
}

const props = defineProps<Props>();

const form = useForm({
  url: props.url || "",
});

const submit = () => {
  emit("youtubeAdded", form.url);
  form.url = "";
};
</script>
