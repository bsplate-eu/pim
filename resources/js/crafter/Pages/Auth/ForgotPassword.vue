<template>
  <div>
    <Head :title="$t('crafter', 'Reset password')" />

    <div
      class="bg-white py-8 px-4 shadow sm:rounded-lg sm:px-10"
      v-auto-animate
    >
      <Alert v-if="status" type="info" class="mb-6">
        {{ status }}
      </Alert>

      <form class="space-y-6" @submit.prevent="submit">
        <TextInput
          v-model="form.email"
          :label="$t('crafter', 'E-mail address')"
          name="email"
        />

        <Button class="w-full" type="submit" :disabled="form.processing">
          {{ $t("crafter", "Email Password Reset Link") }}
        </Button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useForm, Head } from "@inertiajs/vue3";
import { Button, TextInput, Alert } from "crafter/Components";

interface Props {
  status: string;
}

defineProps<Props>();

const form = useForm({
  email: "",
});

const submit = () => {
  form.post(route("crafter.password.email"));
};
</script>
