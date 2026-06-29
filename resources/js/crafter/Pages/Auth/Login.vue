<template>
  <div>
    <Head :title="$t('crafter', 'Login')" />

    <div

      v-auto-animate
    >
      <Alert v-if="status" type="info" class="mb-6">
        {{ status }}
      </Alert>

      <form class="space-y-6" @submit.prevent="submit">
        <TextInput
          v-model="form.email"
          :label="$t('crafter', 'E-mail')"
          name="email"
        />

        <TextInput
          v-model="form.password"
          :label="$t('crafter', 'Password')"
          name="password"
          type="password"
          autocomplete="current-password"
        />

        <div class="flex items-center justify-between">
          <Checkbox
            v-model="form.remember"
            :label="$t('crafter', 'Remember me')"
            name="remember-me"
          />

          <div v-if="canResetPassword" class="text-sm">
            <Link
              :href="route('crafter.password.request')"
              class="font-medium text-primary-600 hover:text-primary-500"
            >
              {{ $t("crafter", "Forgot your password?") }}
            </Link>
          </div>
        </div>

        <Button class="w-full" type="submit" :disabled="form.processing">
          {{ $t("crafter", "Login") }}
        </Button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useForm, Head } from "@inertiajs/vue3";
import { Button, TextInput, Checkbox, Alert } from "crafter/Components";

interface Props {
  canResetPassword: boolean;
  status: string;
}

defineProps<Props>();

const form = useForm({
  email: "",
  password: "",
  remember: false,
});

const submit = () => {
  form.post(route("crafter.login"), {
    onFinish: () => form.reset("password"),
  });
};
</script>
