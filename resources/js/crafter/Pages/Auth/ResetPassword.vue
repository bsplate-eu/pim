<template>
  <div>
    <Head :title="$t('crafter', 'Reset password')" />

    <div class="bg-white py-8 px-4 shadow sm:rounded-lg sm:px-10">
      <form class="space-y-6" @submit.prevent="submit">
        <TextInput
          v-model="form.email"
          :label="$t('crafter', 'E-mail address')"
          name="email"
        />

        <TextInput
          v-model="form.password"
          :label="$t('crafter', 'Password')"
          name="password"
          type="password"
          autocomplete="new-password"
        />

        <TextInput
          v-model="form.password_confirmation"
          :label="$t('crafter', 'Confirm Password')"
          name="password_confirmation"
          type="password"
          autocomplete="new-password"
        />

        <Button class="w-full" type="submit" :disabled="form.processing">
          {{ $t("crafter", "Reset Password") }}
        </Button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useForm, Head } from "@inertiajs/vue3";
import { Button, TextInput } from "crafter/Components";

interface Props {
  email: string;
  token: string;
}

const props = defineProps<Props>();

const form = useForm({
  token: props.token,
  email: props.email,
  password: "",
  password_confirmation: "",
});

const submit = () => {
  form.post(route("crafter.password.update"), {
    onFinish: () => form.reset("password", "password_confirmation"),
  });
};
</script>
