<template>
  <Head title="ARGO" />

  <div class="p-4 space-y-4">
    <!-- Hero w granacie PIM, z czerwoną kreską akcentu pod nagłówkiem -->
    <div class="rounded-2xl bg-sidebar-600 px-5 py-5 text-white shadow-sm">
      <h1 class="text-2xl font-extrabold">
        Cześć{{ firstName ? ', ' + firstName : '' }} 👋
      </h1>
      <div class="mt-2 h-0.5 w-10 rounded-full bg-primary-500"></div>
      <p class="mt-2 text-sm text-sidebar-100">Twoje centrum: magazyn i poczta.</p>
    </div>

    <!-- Dwa kafelki = dwa moduly apki. Alerty siedza w dolnym pasku, bo to
         skrzynka powiadomien push, a nie osobny modul. -->
    <div class="grid grid-cols-1 gap-3">
      <Link href="/admin/m/magazyn" class="block rounded-2xl bg-white shadow-sm border border-gray-100 p-4 active:scale-[.99] transition">
        <div class="flex items-center gap-4">
          <div class="h-12 w-12 rounded-xl bg-sidebar-50 flex items-center justify-center">
            <ArchiveBoxIcon class="h-6 w-6 text-sidebar-600" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-gray-900">Magazyn</div>
            <div class="text-sm text-gray-500">Gdzie leży kod i ile jest</div>
          </div>
          <span v-if="counts.warehouse > 0" class="rounded-full bg-gray-100 text-gray-600 text-sm font-semibold px-2.5 py-0.5">
            {{ counts.warehouse }}
          </span>
          <ChevronRightIcon class="h-5 w-5 text-gray-300 shrink-0" />
        </div>
      </Link>

      <Link href="/admin/m/mail" class="block rounded-2xl bg-white shadow-sm border border-gray-100 p-4 active:scale-[.99] transition">
        <div class="flex items-center gap-4">
          <div class="h-12 w-12 rounded-xl bg-sidebar-50 flex items-center justify-center">
            <EnvelopeIcon class="h-6 w-6 text-sidebar-600" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-gray-900">Poczta</div>
            <div class="text-sm text-gray-500">Wspólna skrzynka firmowa</div>
          </div>
          <span v-if="counts.mailUnread > 0" class="rounded-full bg-primary-500 text-white text-sm font-semibold px-2.5 py-0.5">
            {{ counts.mailUnread }}
          </span>
          <ChevronRightIcon class="h-5 w-5 text-gray-300 shrink-0" />
        </div>
      </Link>
    </div>

    <!-- Powiadomienia push -->
    <div class="rounded-2xl border bg-white p-4" :class="pushState === 'subscribed' ? 'border-green-200' : 'border-gray-200'">
      <div class="flex items-start gap-3">
        <BellAlertIcon class="h-6 w-6 text-sidebar-600 shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="font-semibold text-gray-900">Powiadomienia push</div>
          <p class="text-sm text-gray-500">
            Telefon zadzwoni przy nowym mailu i wzmiance.
          </p>

          <p v-if="pushState === 'unsupported'" class="mt-2 text-xs text-amber-600">
            Ta przeglądarka nie obsługuje powiadomień push (lub brak konfiguracji).
          </p>
          <p v-else-if="pushState === 'denied'" class="mt-2 text-xs text-amber-600">
            Powiadomienia są zablokowane — odblokuj je dla tej strony w ustawieniach przeglądarki.
          </p>
          <p v-else-if="pushState === 'subscribed'" class="mt-2 text-xs font-medium text-green-600">
            ✓ Powiadomienia włączone na tym urządzeniu.
          </p>

          <button
            v-if="pushState === 'idle'"
            type="button"
            class="mt-3 inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white active:opacity-80 disabled:opacity-50"
            :disabled="pushBusy"
            @click="enablePush"
          >
            <BellAlertIcon class="h-4 w-4" />
            {{ pushBusy ? 'Włączam…' : 'Włącz powiadomienia' }}
          </button>
        </div>
      </div>
    </div>

    <p class="text-center text-xs text-gray-400 px-6 pt-2">
      Dodaj ARGO do ekranu początkowego (menu przeglądarki → „Dodaj do ekranu głównego"), aby działało jak aplikacja.
    </p>
  </div>
</template>

<script>
import MobileLayout from "crafter/Layouts/MobileLayout.vue";
export default { layout: MobileLayout };
</script>

<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, Link, usePage } from "@inertiajs/vue3";
import axios from "axios";
import {
  EnvelopeIcon,
  ArchiveBoxIcon,
  BellAlertIcon,
  ChevronRightIcon,
} from "@heroicons/vue/24/outline";

const props = defineProps({
  counts: { type: Object, default: () => ({ mailUnread: 0, warehouse: 0, notifications: 0 }) },
  userName: { type: String, default: "" },
});

const firstName = computed(() => (props.userName || "").trim().split(" ")[0] || "");

/* ── Powiadomienia push (Web Push) ───────────────────────────────── */
const page = usePage();
const vapidKey = computed(() => page.props?.auth?.vapidPublicKey || "");
const pushState = ref("idle"); // idle | subscribed | denied | unsupported
const pushBusy = ref(false);

const pushSupported = () =>
  typeof window !== "undefined" &&
  "serviceWorker" in navigator &&
  "PushManager" in window &&
  "Notification" in window;

const csrfToken = () =>
  document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

const urlBase64ToUint8Array = (base64String) => {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
  return output;
};

const refreshPushState = async () => {
  if (!pushSupported() || !vapidKey.value) {
    pushState.value = "unsupported";
    return;
  }
  if (Notification.permission === "denied") {
    pushState.value = "denied";
    return;
  }
  try {
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    pushState.value = sub ? "subscribed" : "idle";
  } catch (e) {
    pushState.value = "idle";
  }
};

const enablePush = async () => {
  if (!pushSupported() || !vapidKey.value) {
    pushState.value = "unsupported";
    return;
  }
  pushBusy.value = true;
  try {
    const permission = await Notification.requestPermission();
    if (permission !== "granted") {
      pushState.value = permission === "denied" ? "denied" : "idle";
      return;
    }
    const reg = await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKey.value),
      });
    }
    const json = sub.toJSON();
    await axios.post(
      "/admin/push/subscribe",
      { endpoint: json.endpoint, keys: { p256dh: json.keys.p256dh, auth: json.keys.auth } },
      { headers: { "X-CSRF-TOKEN": csrfToken() } }
    );
    pushState.value = "subscribed";
  } catch (e) {
    pushState.value = "idle";
  } finally {
    pushBusy.value = false;
  }
};

onMounted(refreshPushState);
</script>
