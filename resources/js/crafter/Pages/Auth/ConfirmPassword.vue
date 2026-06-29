<template>
  <div>
    <Head :title="$t('crafter', 'Confirm password')" />

    <div class="bg-white py-8 px-4 shadow sm:rounded-lg sm:px-10">
      <p class="mb-6 text-center text-sm text-gray-600">
        {{
          $t(
            "crafter",
            "This is a secure area of the application. Please confirm your password before continuing."
          )
        }}
      </p>

      <form class="space-y-6" @submit.prevent="submit">
        <TextInput
          v-model="form.password"
          :label="$t('crafter', 'Password')"
          name="password"
          type="password"
          autocomplete="current-password"
        />

        <Button class="w-full" type="submit" :disabled="form.processing">
          {{ $t("crafter", "Confirm") }}
        </Button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useForm, Head } from "@inertiajs/vue3";
import { Button, TextInput } from "crafter/Components";

const form = useForm({
  password: "",
});

const submit = () => {
  form.post(route("crafter.password.confirm"), {
    onFinish: () => form.reset(),
  });
};
</script>
