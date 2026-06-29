<template>
  <PageHeader title="Status synchronizacji" />

  <PageContent fluid>
    <div class="space-y-4">

      <!-- Legenda -->
      <div class="flex gap-4 text-sm">
        <span v-for="s in statuses" :key="s.value" class="flex items-center gap-1.5">
          <span class="inline-block h-2.5 w-2.5 rounded-full" :class="s.dot" />
          {{ s.label }}
        </span>
      </div>

      <!-- Tabela logów -->
      <div class="overflow-hidden rounded-lg border bg-white shadow-sm">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs uppercase text-gray-500">
            <tr>
              <th class="w-6 px-2 py-3"></th>
              <th class="px-4 py-3 text-left">Integracja</th>
              <th class="px-4 py-3 text-left">Status</th>
              <th class="px-4 py-3 text-left w-56">Postęp</th>
              <th class="px-4 py-3 text-left">Błędy</th>
              <th class="px-4 py-3 text-left">Czas</th>
              <th class="px-4 py-3 text-left">Start</th>
              <th class="px-4 py-3 text-left">Komunikat</th>
              <th class="px-4 py-3 text-left">Akcje</th>
            </tr>
          </thead>
          <tbody>
            <template v-if="logs.length === 0">
              <tr>
                <td colspan="9" class="px-4 py-8 text-center text-gray-400">
                  Brak logów synchronizacji
                </td>
              </tr>
            </template>

            <template v-for="log in logs" :key="log.id">
              <!-- Główny wiersz -->
              <tr
                class="border-t hover:bg-gray-50"
                :class="log.error_count > 0 ? 'cursor-pointer' : ''"
                @click="log.error_count > 0 && toggleErrors(log.id)"
              >
                <!-- Przycisk rozwijania -->
                <td class="px-2 py-3 text-center">
                  <span v-if="log.error_count > 0" class="text-gray-400 transition-transform inline-block" :class="expandedId === log.id ? 'rotate-90' : ''">
                    ▶
                  </span>
                </td>

                <td class="px-4 py-3 font-medium">
                  {{ log.integration_name }}
                  <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500">{{ log.integration_type }}</span>
                </td>

                <td class="px-4 py-3">
                  <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(log.status)">
                    <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(log.status)" />
                    {{ statusLabel(log.status) }}
                  </span>
                </td>

                <td class="px-4 py-3">
                  <div class="flex items-center gap-2">
                    <div class="relative h-2 flex-1 overflow-hidden rounded-full bg-gray-200">
                      <div
                        class="h-full rounded-full transition-all duration-500"
                        :class="progressBarClass(log.status)"
                        :style="{ width: log.progress_percent + '%' }"
                      />
                    </div>
                    <span class="w-10 text-right text-xs text-gray-500">{{ log.progress_percent }}%</span>
                  </div>
                  <div class="mt-0.5 text-xs text-gray-400">{{ log.progress }} / {{ log.total }}</div>
                </td>

                <!-- Liczba błędów -->
                <td class="px-4 py-3">
                  <span v-if="log.error_count > 0"
                    class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700"
                  >
                    <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                    {{ log.error_count }}
                  </span>
                  <span v-else class="text-xs text-gray-400">—</span>
                </td>

                <td class="px-4 py-3 text-xs text-gray-500">{{ log.duration ?? '—' }}</td>
                <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ log.started_at ?? '—' }}</td>
                <td class="px-4 py-3 max-w-xs text-xs text-gray-500">
                  <span
                    v-if="log.message"
                    class="cursor-pointer truncate block hover:text-blue-600 hover:underline"
                    @click.stop="openMessage(log)"
                  >{{ log.message }}</span>
                  <span v-else class="text-gray-400">—</span>
                </td>
                <td class="px-4 py-3 text-xs">
                  <button
                    v-if="log.status === 'running' || log.status === 'pending'"
                    @click.stop="stopAllActiveProcesses"
                    :disabled="stopping"
                    class="rounded border border-red-200 bg-red-50 px-2 py-1 font-medium text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    {{ stopping ? 'Kończenie...' : 'Zakończ proces' }}
                  </button>
                  <span v-else class="text-gray-400">—</span>
                </td>
              </tr>

              <!-- Panel błędów (rozwijany) -->
              <tr v-if="expandedId === log.id && log.errors?.length" class="border-t bg-red-50">
                <td colspan="9" class="px-6 py-3">
                  <p class="mb-2 text-xs font-semibold text-red-700">Błędy ({{ log.errors.length }}):</p>
                  <div class="max-h-64 overflow-y-auto rounded border border-red-200 bg-white">
                    <table class="w-full text-xs">
                      <thead class="bg-red-50">
                        <tr>
                          <th class="px-3 py-2 text-left font-medium text-red-600 w-32">SKU</th>
                          <th class="px-3 py-2 text-left font-medium text-red-600 w-20">Godzina</th>
                          <th class="px-3 py-2 text-left font-medium text-red-600">Błąd</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-red-100">
                        <tr v-for="(err, i) in log.errors" :key="i" class="hover:bg-red-50">
                          <td class="px-3 py-1.5 font-mono text-gray-700">{{ err.sku }}</td>
                          <td class="px-3 py-1.5 text-gray-400">{{ err.at }}</td>
                          <td class="px-3 py-1.5 text-red-700">{{ err.error }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <p class="text-xs text-gray-400">
        Odświeża co {{ pollInterval / 1000 }}s.
        <span v-if="hasRunning" class="font-medium text-blue-500 animate-pulse">● Sync w toku...</span>
      </p>

    </div>
  </PageContent>

  <!-- Modal: pełny komunikat błędu -->
  <Teleport to="body">
    <div
      v-if="modalLog"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="modalLog = null"
    >
      <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl flex flex-col max-h-[80vh]">
        <!-- Nagłówek -->
        <div class="flex items-center justify-between px-6 py-4 border-b">
          <div>
            <p class="font-semibold text-gray-800">{{ modalLog.integration_name }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ modalLog.started_at }} · <span class="capitalize">{{ modalLog.integration_type }}</span></p>
          </div>
          <button @click="modalLog = null" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>

        <!-- Komunikat główny -->
        <div class="px-6 py-4 overflow-y-auto flex-1">
          <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Komunikat</p>
          <pre class="text-sm text-red-700 bg-red-50 border border-red-200 rounded p-4 whitespace-pre-wrap break-all font-mono">{{ modalLog.message }}</pre>

          <!-- Błędy per SKU jeśli są -->
          <template v-if="modalLog.errors?.length">
            <p class="text-xs font-semibold text-gray-500 uppercase mt-5 mb-2">Błędy per produkt ({{ modalLog.errors.length }})</p>
            <div class="rounded border border-red-200 overflow-hidden">
              <table class="w-full text-xs">
                <thead class="bg-red-50">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium text-red-600 w-36">SKU</th>
                    <th class="px-3 py-2 text-left font-medium text-red-600 w-24">Godzina</th>
                    <th class="px-3 py-2 text-left font-medium text-red-600">Błąd</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-red-100">
                  <tr v-for="(err, i) in modalLog.errors" :key="i">
                    <td class="px-3 py-1.5 font-mono text-gray-700">{{ err.sku }}</td>
                    <td class="px-3 py-1.5 text-gray-400">{{ err.at }}</td>
                    <td class="px-3 py-1.5 text-red-700 break-all">{{ err.error }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>

        <!-- Stopka -->
        <div class="px-6 py-3 border-t flex justify-end">
          <button
            @click="modalLog = null"
            class="rounded-lg bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200"
          >Zamknij</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { PageHeader, PageContent } from 'crafter/Components'

const logs        = ref<any[]>([])
const expandedId  = ref<number | null>(null)
const modalLog    = ref<any | null>(null)
const pollInterval = 3000
let timer: ReturnType<typeof setInterval> | null = null
const stopping = ref(false)

const statuses = [
  { value: 'pending',   label: 'Oczekuje',   dot: 'bg-gray-400' },
  { value: 'running',   label: 'W toku',     dot: 'bg-blue-500 animate-pulse' },
  { value: 'completed', label: 'Zakończona', dot: 'bg-green-500' },
  { value: 'failed',    label: 'Błąd',       dot: 'bg-red-500' },
]

const hasRunning = computed(() => logs.value.some(l => l.status === 'running' || l.status === 'pending'))

function toggleErrors(id: number) {
  expandedId.value = expandedId.value === id ? null : id
}

function openMessage(log: any) {
  modalLog.value = log
}

const statusLabel      = (s: string) => statuses.find(x => x.value === s)?.label ?? s
const statusClass      = (s: string) => ({ pending: 'bg-gray-100 text-gray-700', running: 'bg-blue-100 text-blue-700', completed: 'bg-green-100 text-green-700', failed: 'bg-red-100 text-red-700' }[s] ?? 'bg-gray-100 text-gray-700')
const statusDot        = (s: string) => ({ pending: 'bg-gray-400', running: 'bg-blue-500 animate-pulse', completed: 'bg-green-500', failed: 'bg-red-500' }[s] ?? 'bg-gray-400')
const progressBarClass = (s: string) => ({ running: 'bg-blue-500', completed: 'bg-green-500', failed: 'bg-red-400', pending: 'bg-gray-300' }[s] ?? 'bg-gray-300')

async function fetchLogs() {
  try {
    const res = await fetch('/admin/integrations/status/json')
    logs.value = await res.json()
  } catch {}
}

async function stopAllActiveProcesses() {
  if (stopping.value) return

  const confirmed = window.confirm('Zakończyć wszystkie aktywne procesy synchronizacji?')
  if (!confirmed) return

  try {
    stopping.value = true

    const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''

    await fetch('/admin/integrations/status/stop-all', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
    })

    await fetchLogs()
  } catch {
  } finally {
    stopping.value = false
  }
}

onMounted(() => {
  fetchLogs()
  timer = setInterval(fetchLogs, pollInterval)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>
