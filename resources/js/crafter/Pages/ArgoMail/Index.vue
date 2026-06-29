<template>
    <Head title="Argo Mail" />

    <!-- Kompaktowy nagłówek w JEDNEJ linii (bez spacera h-10 i dużych py-5 z PageHeadera — oszczędność wysokości) -->
    <header class="w-full bg-white shadow-sm px-4 sm:px-6 py-2 flex items-center justify-between gap-3">
        <div class="flex items-baseline gap-2 min-w-0">
            <h3 class="whitespace-nowrap text-lg font-semibold leading-tight text-gray-900">Argo Mail</h3>
            <span class="hidden whitespace-nowrap text-xs text-gray-400 sm:inline">Wspólna skrzynka firmowa</span>
            <span v-if="totalUnread > 0" class="whitespace-nowrap text-xs font-medium text-blue-600">· {{ totalUnread }} nieprzeczytanych</span>
        </div>
        <div class="flex flex-shrink-0 items-center gap-2">
            <Button :leftIcon="PencilSquareIcon" @click="openCompose()">Nowa wiadomość</Button>
            <Link :href="route('crafter.argo-mail.settings')">
                <Button variant="outline" color="gray" :leftIcon="Cog6ToothIcon">Ustawienia</Button>
            </Link>
            <Link :href="route('crafter.argo-mail.accounts.index')">
                <Button variant="outline" color="gray" :leftIcon="EnvelopeIcon">Skrzynki</Button>
            </Link>
            <!-- Dzwonek powiadomień przeniesiony z górnego paska (chowanego na tej stronie) — desktop (md+) -->
            <div class="hidden items-center border-l border-gray-200 pl-2 md:flex">
                <NotificationBell />
            </div>
        </div>
    </header>

    <PageContent fluid>
        <div v-if="accounts.length === 0" class="bg-white rounded-lg shadow-sm border border-gray-200 p-10 text-center">
            <EnvelopeIcon class="mx-auto h-12 w-12 text-gray-300" />
            <h3 class="mt-4 text-base font-semibold text-gray-900">Brak wpiętych skrzynek</h3>
            <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">Najpierw wepnij skrzynkę, potem zsynchronizuj.</p>
            <Link :href="route('crafter.argo-mail.accounts.create')">
                <Button :leftIcon="PlusIcon" class="mt-5">Dodaj skrzynkę</Button>
            </Link>
        </div>

        <template v-else>
            <!-- 3 wiersze tabów (ukryte gdy mail otwarty na całą stronę) -->
            <div v-show="!listCollapsed" class="mb-3 space-y-1.5">
                <!-- Konta -->
                <div class="flex items-start gap-2">
                    <span class="w-16 shrink-0 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Konta</span>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" @click="selectAccount(null)" :class="pillClass(!filters.trash && filters.account_id === null)">
                            Wszystkie<span v-if="totalUnread > 0" :class="pillBadge">{{ totalUnread }}</span>
                        </button>
                        <button v-for="a in accounts" :key="a.id" type="button" @click="selectAccount(a.id)" :class="pillClass(!filters.trash && filters.account_id === a.id)">
                            <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: a.color || '#9ca3af' }"></span>
                            {{ a.label }}<span v-if="a.unread > 0" :class="pillBadge">{{ a.unread }}</span>
                        </button>
                    </div>
                </div>
                <!-- Osoby -->
                <div class="flex items-start gap-2">
                    <span class="w-16 shrink-0 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Osoby</span>
                    <div class="flex flex-wrap gap-1 items-center">
                        <button type="button" @click="selectUser(null)" :class="pillClass(!filters.trash && filters.user_id === null)">Wszyscy</button>
                        <button v-for="u in users" :key="u.id" type="button" @click="selectUser(u.id)" :class="pillClass(!filters.trash && filters.user_id === u.id)">
                            <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: u.color || '#9ca3af' }"></span>
                            {{ u.name }}<span v-if="u.unread > 0" :class="pillBadge">{{ u.unread }}</span>
                        </button>
                        <span v-if="users.length === 0" class="text-xs text-gray-400">
                            — dodaj w <Link :href="route('crafter.argo-mail.settings')" class="text-primary-600 underline">Ustawieniach</Link>
                        </span>
                    </div>
                </div>
                <!-- Kategorie -->
                <div class="flex items-start gap-2">
                    <span class="w-16 shrink-0 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Kategorie</span>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" @click="selectCategory(null)" :class="pillClass(!filters.trash && filters.category_id === null)">Wszystkie</button>
                        <button v-for="c in categories" :key="c.id" type="button" @click="selectCategory(c.id)" :class="pillClass(!filters.trash && filters.category_id === c.id)">
                            <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: c.color || '#9ca3af' }"></span>
                            {{ c.name }}<span v-if="c.unread > 0" :class="pillBadge">{{ c.unread }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Pasek narzędzi (ukryty gdy mail otwarty na całą stronę) -->
            <div v-show="!listCollapsed" class="mb-3 flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-[200px] max-w-md">
                    <input v-model="localFilters.q" @input="debouncedApply" type="text" placeholder="Szukaj: temat lub nadawca…"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm pl-9 pr-8" />
                    <MagnifyingGlassIcon class="absolute left-2.5 top-2.5 h-4 w-4 text-gray-400" />
                    <button v-if="localFilters.q" type="button" @click="clearSearch" class="absolute right-2 top-2 p-0.5 text-gray-400 hover:text-gray-700" title="Wyczyść wyszukiwanie"><XMarkIcon class="h-4 w-4" /></button>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                    <input type="checkbox" v-model="localFilters.unread" @change="applyFilters" class="rounded text-primary-600 focus:ring-primary-500" />
                    Tylko nieprzeczytane
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer" title="Pokazuje tylko maile BEZ przypisanego katalogu (nieposortowane)">
                    <input type="checkbox" v-model="localFilters.unfiled" @change="onUnfiledToggle" class="rounded text-primary-600 focus:ring-primary-500" />
                    Ukryj maile w folderach
                </label>
                <div v-if="hasAnyColor || filters.color" class="flex items-center gap-1.5 border-l border-gray-200 pl-3">
                    <span class="text-xs text-gray-400">Kolor:</span>
                    <template v-for="c in colorFilterList" :key="c.key">
                        <button v-if="colorCounts[c.key]" type="button" @click="selectColor(c.key)"
                            :class="['h-5 w-5 rounded ring-1 ring-gray-300 transition hover:scale-110', filters.color === c.key ? 'ring-2 ring-offset-1 ring-gray-800' : '']"
                            :style="{ backgroundColor: c.hex }" :title="`${c.label} (${colorCounts[c.key]}) — pokaż tylko te`"></button>
                    </template>
                    <button v-if="filters.color" type="button" @click="selectColor(filters.color)" class="text-xs text-gray-500 hover:text-gray-700" title="Wyczyść filtr koloru">✕</button>
                </div>
                <div class="flex items-center gap-1.5 border-l border-gray-200 pl-3">
                    <ArrowsUpDownIcon class="h-4 w-4 text-gray-400" />
                    <select v-model="localFilters.sort" @change="applyFilters" class="rounded-md border-gray-300 py-1 pr-7 text-sm focus:border-primary-500 focus:ring-primary-500" title="Sortowanie listy">
                        <option value="date_desc">Najnowsze</option>
                        <option value="date_asc">Najstarsze</option>
                        <option value="subject">Temat (A–Z)</option>
                        <option value="sender">Nadawca (A–Z)</option>
                    </select>
                </div>
                <span v-if="filters.trash" class="text-xs font-medium text-red-600">Widok: Kosz</span>
                <span v-if="filters.spam" class="text-xs font-medium text-orange-600">Widok: Spam</span>
                <span class="ml-auto text-xs text-gray-400">{{ messages.total }} wiadomości</span>
            </div>

            <!-- Pasek akcji masowych (multi-select: Ctrl/Shift + klik) -->
            <div v-if="selectedIds.length > 0 && !listCollapsed" class="mb-3 flex flex-wrap items-center gap-2 rounded-md bg-primary-50 border border-primary-200 px-3 py-2">
                <span class="text-sm font-medium text-primary-700">Zaznaczono {{ selectedIds.length }}</span>
                <button type="button" @click="bulk('trash')" class="text-xs rounded-md border border-gray-300 bg-white px-2 py-1 text-red-600 hover:bg-gray-50">Do kosza</button>
                <button type="button" @click="bulk('read')" class="text-xs rounded-md border border-gray-300 bg-white px-2 py-1 hover:bg-gray-50">Przeczytane</button>
                <button type="button" @click="bulk('unread')" class="text-xs rounded-md border border-gray-300 bg-white px-2 py-1 hover:bg-gray-50">Nieprzeczytane</button>
                <button type="button" @click="bulkSpam" class="inline-flex items-center gap-1 text-xs rounded-md border border-gray-300 bg-white px-2 py-1 text-orange-600 hover:bg-gray-50" title="Przenieś nadawców zaznaczonych maili do spamu"><NoSymbolIcon class="h-3.5 w-3.5" /> Spam</button>
                <select @change="bulkSelect('catalog', $event)" class="text-xs rounded-md border-gray-300 py-1">
                    <option value="">Katalog…</option>
                    <option v-for="c in catalogs" :key="c.id" :value="c.id">{{ catalogIndent(c.depth) + c.name }}</option>
                </select>
                <select @change="bulkSelect('category', $event)" class="text-xs rounded-md border-gray-300 py-1">
                    <option value="">Kategoria…</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-if="users.length" @change="bulkSelect('user', $event)" class="text-xs rounded-md border-gray-300 py-1">
                    <option value="">Osoba…</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <span class="text-xs text-gray-500 ml-1">Kolor:</span>
                <button type="button" @click="bulkColor('red')" class="h-5 w-5 rounded-full bg-red-500 ring-1 ring-gray-300" title="Czerwony (1)"></button>
                <button type="button" @click="bulkColor('green')" class="h-5 w-5 rounded-full bg-green-500 ring-1 ring-gray-300" title="Zielony (2)"></button>
                <button type="button" @click="bulkColor('blue')" class="h-5 w-5 rounded-full bg-blue-500 ring-1 ring-gray-300" title="Niebieski (3)"></button>
                <button type="button" @click="bulkColor('orange')" class="h-5 w-5 rounded-full bg-orange-500 ring-1 ring-gray-300" title="Pomarańczowy (4)"></button>
                <button type="button" @click="bulkColor(null)" class="flex items-center justify-center h-5 w-5 rounded-full bg-white ring-1 ring-gray-300 text-[10px] leading-none text-gray-400" title="Bez koloru (0)">✕</button>
                <button type="button" @click="clearSelection" class="ml-auto text-xs text-gray-500 hover:text-gray-700">Wyczyść</button>
            </div>

            <!-- 3 kolumny (elastyczne: katalogi, lista, podgląd) -->
            <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                <!-- Katalogi -->
                <div v-show="!listCollapsed" class="lg:w-max lg:min-w-[14rem] lg:max-w-sm lg:shrink-0 bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden flex flex-col">
                    <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Katalogi</span>
                        <Link :href="route('crafter.argo-mail.settings')" class="text-gray-300 hover:text-gray-600" title="Zarządzaj katalogami">
                            <Cog6ToothIcon class="h-4 w-4" />
                        </Link>
                    </div>
                    <div v-if="dragMessage" class="px-3 py-1 text-[11px] font-medium text-primary-600 bg-primary-50 border-b border-primary-100">Upuść na katalog → przypisze nadawcę</div>
                    <div class="py-1">
                        <button type="button" @click="selectCatalog(null)"
                            @dragover.prevent="onCatalogDragOver('all')" @dragleave="onCatalogDragLeave('all')" @drop="onCatalogDrop(null)"
                            :class="[catalogNodeClass(null), dragOverCatalogId === 'all' ? 'bg-primary-100 ring-2 ring-inset ring-primary-400' : '']"
                            style="padding-left: 12px">
                            <span class="truncate">Wszystkie</span>
                        </button>
                        <template v-for="c in catalogs" :key="c.id">
                        <button v-if="catalogVisible(c)" type="button" @click="selectCatalog(c.id)"
                            @dragover.prevent="onCatalogDragOver(c.id)" @dragleave="onCatalogDragLeave(c.id)" @drop="onCatalogDrop(c.id)"
                            :class="[catalogNodeClass(c.id), dragOverCatalogId === c.id ? 'bg-primary-100 ring-2 ring-inset ring-primary-400' : '']"
                            :style="{ paddingLeft: (10 + c.depth * 14) + 'px' }">
                            <span v-if="catalogHasChildren(c.id)" @click.stop="toggleCatalog(c.id)" class="shrink-0 -ml-1 p-0.5 text-gray-700 hover:text-gray-900 cursor-pointer" title="Zwiń / rozwiń podkatalogi">
                                <ChevronRightIcon :class="['h-3.5 w-3.5 transition-transform', collapsedCats.has(c.id) ? '' : 'rotate-90']" />
                            </span>
                            <span v-else class="w-3.5 shrink-0"></span>
                            <FolderIcon class="h-4 w-4 text-gray-400 shrink-0" />
                            <span class="truncate" :class="[catalogDepthClass(c.depth), c.unread > 0 ? 'font-bold' : '']">{{ c.name }}</span>
                            <span v-if="c.total > 0" class="ml-auto pl-2 rounded-full bg-gray-100 text-[10px] font-semibold px-1.5 shrink-0 tabular-nums text-gray-800">{{ c.total }}<span v-if="c.unread > 0" class="text-blue-600"> / {{ c.unread }}</span></span>
                        </button>
                        </template>
                        <div v-if="catalogs.length === 0" class="px-3 py-4 text-xs text-gray-400">
                            Brak katalogów. <Link :href="route('crafter.argo-mail.settings')" class="text-primary-600 underline">Dodaj</Link>
                        </div>
                        <button type="button" @click="selectTrash()" :class="catalogNodeClass('trash')" class="border-t border-gray-100 mt-1" style="padding-left: 12px">
                            <TrashIcon class="h-4 w-4 text-gray-400 shrink-0" />
                            <span class="truncate" :class="trashUnread > 0 ? 'font-bold text-gray-900' : 'text-gray-900'">Kosz</span>
                            <span v-if="trashTotal > 0" class="ml-auto pl-2 rounded-full bg-gray-100 text-[10px] font-semibold px-1.5 shrink-0 tabular-nums text-gray-800">{{ trashTotal }}<span v-if="trashUnread > 0" class="text-blue-600"> / {{ trashUnread }}</span></span>
                        </button>
                        <button type="button" @click="selectSpam()" :class="catalogNodeClass('spam')" style="padding-left: 12px">
                            <NoSymbolIcon class="h-4 w-4 text-gray-400 shrink-0" />
                            <span class="truncate" :class="spamUnread > 0 ? 'font-bold text-gray-900' : 'text-gray-900'">Spam</span>
                            <span v-if="spamTotal > 0" class="ml-auto pl-2 rounded-full bg-gray-100 text-[10px] font-semibold px-1.5 shrink-0 tabular-nums text-gray-800">{{ spamTotal }}<span v-if="spamUnread > 0" class="text-blue-600"> / {{ spamUnread }}</span></span>
                        </button>
                    </div>
                </div>

                <!-- Lista -->
                <div v-show="!listCollapsed" :class="previewCollapsed ? 'lg:flex-1' : 'lg:w-2/5 lg:shrink-0'" class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <!-- Nagłówek listy: zaznacz wszystkie (bieżąca strona) + rozszerzenie listy -->
                    <div class="flex items-center gap-2 border-b border-gray-100 bg-gray-50/60 px-4 py-2">
                        <template v-if="messages.data.length > 0">
                            <input type="checkbox" :checked="allSelected" :indeterminate.prop="someSelected" @change="toggleSelectAll"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer" title="Zaznacz / odznacz wszystkie na tej stronie" />
                            <button type="button" @click="toggleSelectAll" class="text-xs font-medium text-gray-600 hover:text-gray-900">
                                {{ allSelected ? "Odznacz wszystkie" : "Zaznacz wszystkie" }}
                            </button>
                            <span v-if="selectedIds.length" class="text-xs font-medium text-primary-600">· zaznaczono {{ selectedIds.length }}</span>
                        </template>
                        <button type="button" @click="previewCollapsed = !previewCollapsed" class="ml-auto inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800" :title="previewCollapsed ? 'Pokaż podgląd (układ 3 kolumn)' : 'Rozszerz listę — ukryj podgląd'">
                            <ViewColumnsIcon class="h-4 w-4" />
                            {{ previewCollapsed ? "Podgląd" : "Rozszerz" }}
                        </button>
                    </div>
                    <div v-if="messages.data.length === 0" class="p-8 text-center text-sm text-gray-500">Brak wiadomości.</div>
                    <ul v-else class="divide-y divide-gray-100 max-h-[calc(100vh-250px)] overflow-y-auto">
                        <li v-for="(m, idx) in messages.data" :key="m.id">
                            <!-- Nagłówek wątku -->
                            <div draggable="true" @dragstart="onDragStart($event, m)" @dragend="onDragEnd"
                                @click="onRowClick($event, m, idx)" @dblclick="dblFull(m.id, m)" @contextmenu.prevent="openContextMenu($event, m)"
                                :style="{ borderLeftColor: colorHex(m.color_flag), color: m.color_flag ? colorHex(m.color_flag) : '' }"
                                :class="['px-4 py-3 cursor-pointer transition-colors select-none border-l-4', rowBg(m)]">
                                <div class="flex items-center gap-2">
                                    <button v-if="m.count > 1" type="button" @click.stop="toggleThreadExpand(m)" class="shrink-0 -ml-1 p-0.5 text-gray-900 hover:text-black" :title="isThreadExpanded(m) ? 'Zwiń wątek' : 'Rozwiń wątek'">
                                        <ChevronRightIcon :class="['h-5 w-5 stroke-[2.5] transition-transform', isThreadExpanded(m) ? 'rotate-90' : '']" />
                                    </button>
                                    <span v-else class="w-4 shrink-0"></span>
                                    <input type="checkbox" :checked="isThreadSelected(m)" @click.stop @change="toggleThread(m)"
                                        class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 shrink-0 cursor-pointer" title="Zaznacz rozmowę" />
                                    <span v-if="!m.is_read" class="h-2.5 w-2.5 rounded-full bg-blue-500 shrink-0"></span>
                                    <span v-else class="h-2.5 w-2.5 shrink-0"></span>
                                    <span :class="['truncate', m.is_read ? 'text-sm' : 'text-[15px] font-bold', m.color_flag ? '' : 'text-gray-900']">{{ m.from_name || m.from_email || "(brak nadawcy)" }}</span>
                                    <span v-if="m.count > 1" class="shrink-0 rounded-full bg-gray-200 text-gray-800 text-[10px] font-semibold px-1.5" :title="m.count + ' wiadomości w rozmowie'">{{ m.count }}</span>
                                    <PaperClipIcon v-if="m.has_attachments" :class="['h-3.5 w-3.5 shrink-0', m.color_flag ? 'opacity-70' : 'text-gray-400']" />
                                    <span :class="['ml-auto text-xs shrink-0', m.color_flag ? 'opacity-80' : 'text-gray-700']">{{ shortDate(m.date) }}</span>
                                </div>
                                <div :class="['truncate mt-0.5 text-sm', m.is_read ? '' : 'font-semibold', m.color_flag ? '' : 'text-gray-900']">{{ m.subject || "(bez tematu)" }}</div>
                                <div :class="['truncate text-xs mt-0.5', m.color_flag ? 'opacity-80' : 'text-gray-700']">{{ m.snippet }}</div>
                                <div v-if="m.category || m.catalog || m.assigned_user" class="mt-1 flex flex-wrap gap-1">
                                    <span v-if="m.assigned_user" class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium" :style="chipStyle(m.assigned_user.color)">{{ m.assigned_user.name }}</span>
                                    <span v-if="m.category" class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium" :style="chipStyle(m.category.color)">{{ m.category.name }}</span>
                                    <span v-if="m.catalog" class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600"><FolderIcon class="h-3 w-3" /> {{ m.catalog.name }}</span>
                                </div>
                            </div>
                            <!-- Maile wątku rozwinięte inline (Outlook): wcięte, klik = pojedynczy mail -->
                            <div v-if="m.count > 1 && isThreadExpanded(m)" class="divide-y divide-gray-100 bg-gray-50/40">
                                <div v-for="sub in m.messages" :key="sub.id" @click="openSingle(sub.id, m)" @dblclick="dblFull(sub.id, m)"
                                    :style="{ borderLeftColor: colorHex(sub.color_flag), paddingLeft: '48px' }"
                                    :class="['cursor-pointer border-l-4 py-2 pr-4 transition-colors', subRowBg(sub)]">
                                    <div class="flex items-center gap-2">
                                        <span v-if="!sub.is_read" class="h-2 w-2 rounded-full bg-blue-500 shrink-0"></span>
                                        <span v-else class="h-2 w-2 shrink-0"></span>
                                        <span :class="['truncate text-sm', sub.is_read ? 'text-gray-900' : 'font-bold text-gray-900']">{{ sub.from_name }}</span>
                                        <PaperClipIcon v-if="sub.has_attachments" class="h-3 w-3 text-gray-500 shrink-0" />
                                        <span class="ml-auto text-xs text-gray-700 shrink-0">{{ shortDate(sub.date) }}</span>
                                    </div>
                                    <div :class="['truncate text-xs mt-0.5', sub.is_read ? 'text-gray-700' : 'text-gray-900 font-medium']">{{ sub.subject || sub.snippet }}</div>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <div v-if="messages.last_page > 1" class="flex items-center justify-between px-4 py-2 border-t border-gray-100 text-sm">
                        <button :disabled="!messages.prev_page_url" @click="goto(messages.prev_page_url)" class="disabled:opacity-40 text-gray-600 hover:text-gray-900">← Nowsze</button>
                        <span class="text-xs text-gray-400">{{ messages.current_page }} / {{ messages.last_page }}</span>
                        <button :disabled="!messages.next_page_url" @click="goto(messages.next_page_url)" class="disabled:opacity-40 text-gray-600 hover:text-gray-900">Starsze →</button>
                    </div>
                </div>

                <!-- Podgląd -->
                <div v-show="!previewCollapsed" :class="listCollapsed ? 'min-h-[calc(100vh-120px)]' : 'min-h-[calc(100vh-230px)]'" class="flex-1 min-w-0 bg-white rounded-lg shadow-sm border border-gray-200 flex flex-col">
                    <div v-if="!thread.length && !loadingMsg" class="flex-1 flex items-center justify-center text-sm text-gray-400">Wybierz rozmowę z listy</div>
                    <div v-else-if="loadingMsg" class="flex-1 flex items-center justify-center text-sm text-gray-400">Wczytuję…</div>
                    <template v-else>
                        <!-- Pasek akcji na całej rozmowie -->
                        <div class="border-b border-gray-200 p-4">
                            <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                                <div class="flex items-center gap-2">
                                    <Button :leftIcon="ArrowUturnLeftIcon" @click="openReply">Odpowiedz</Button>
                                    <Button variant="outline" color="gray" :leftIcon="ArrowUturnRightIcon" @click="openForward">Przekaż</Button>
                                    <Button variant="outline" color="gray" :leftIcon="listCollapsed ? ArrowsPointingInIcon : ArrowsPointingOutIcon" @click="listCollapsed = !listCollapsed">{{ listCollapsed ? 'Pomniejsz' : 'Full size' }}</Button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="openFilterModal" class="p-1.5 rounded-md text-gray-400 hover:text-primary-600 hover:bg-gray-100" title="Utwórz filtr z tego maila (nadawca → folder)"><FunnelIcon class="h-5 w-5" /></button>
                                    <button v-if="!filters.spam" type="button" @click="markSpamSelected" class="p-1.5 rounded-md text-gray-400 hover:text-orange-600 hover:bg-gray-100" title="Oznacz nadawcę jako SPAM"><NoSymbolIcon class="h-5 w-5" /></button>
                                    <button v-else type="button" @click="unspamSelected" class="p-1.5 rounded-md text-gray-400 hover:text-green-600 hover:bg-gray-100" title="Nie spam — przywróć nadawcę"><ArrowUturnLeftIcon class="h-5 w-5" /></button>
                                    <button type="button" @click="trashThread" class="p-1.5 rounded-md text-gray-400 hover:bg-gray-100" :class="filters.trash ? 'hover:text-green-600' : 'hover:text-red-600'" :title="filters.trash ? 'Przywróć rozmowę (Del)' : 'Rozmowa do kosza (Del)'">
                                        <component :is="filters.trash ? ArrowUturnLeftIcon : TrashIcon" class="h-5 w-5" />
                                    </button>
                                    <select :value="selected?.catalog_id ?? ''" @change="assignCatalogThread(($event.target as HTMLSelectElement).value)" class="rounded-md border-gray-300 text-xs py-1.5" :disabled="assigningCatalog" title="Przenieś rozmowę do katalogu">
                                        <option value="">📁 katalog…</option>
                                        <option v-for="c in catalogs" :key="c.id" :value="c.id">{{ catalogIndent(c.depth) + c.name }}</option>
                                    </select>
                                </div>
                            </div>
                            <h2 class="text-base font-semibold text-gray-900">{{ threadSubject || "(bez tematu)" }}</h2>
                            <div class="mt-0.5 text-xs text-gray-600">{{ thread.length }} {{ thread.length === 1 ? 'wiadomość' : 'wiadomości' }} w rozmowie</div>
                            <div v-if="selected && (selected.assigned_user || selected.category || selected.catalog)" class="mt-2 flex flex-wrap gap-1">
                                <span v-if="selected.assigned_user" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :style="chipStyle(selected.assigned_user.color)">{{ selected.assigned_user.name }}</span>
                                <span v-if="selected.category" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :style="chipStyle(selected.category.color)">{{ selected.category.name }}</span>
                                <span v-if="selected.catalog" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-600"><FolderIcon class="h-3.5 w-3.5" /> {{ selected.catalog.name }}</span>
                            </div>
                        </div>
                        <!-- Stos wiadomości rozmowy (Gmail): nagłówek zawsze, treść po rozwinięciu; najnowszy rozwinięty -->
                        <div class="flex-1 overflow-y-auto divide-y divide-gray-100">
                            <div v-for="m in thread" :key="m.id">
                                <button type="button" @click="toggleExpand(m.id)" class="w-full text-left px-4 py-3 hover:bg-gray-50 flex flex-col gap-0.5">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold shrink-0" :class="m.is_sent ? 'text-primary-700' : 'text-gray-900'">{{ m.is_sent ? 'Ja' : (m.from_name || m.from_email || '(brak nadawcy)') }}</span>
                                        <PaperClipIcon v-if="m.has_attachments" class="h-3.5 w-3.5 text-gray-400 shrink-0" />
                                        <span class="ml-auto text-xs text-gray-600 shrink-0">{{ shortDate(m.date) }}</span>
                                    </div>
                                    <div v-if="!isExpanded(m.id)" class="truncate text-xs text-gray-700 w-full">{{ snippetOf(m) }}</div>
                                </button>
                                <div v-show="isExpanded(m.id)" class="px-4 pb-4">
                                    <div class="text-xs text-gray-600 mb-2">
                                        <template v-if="m.is_sent">do: {{ recipientList(m.to) || "—" }}</template>
                                        <template v-else>od: {{ m.from_name || m.from_email }}<span v-if="m.from_name" class="font-medium text-gray-900"> &lt;{{ m.from_email }}&gt;</span></template>
                                        <button v-if="!m.is_sent && m.from_email" type="button" @click.stop="copyEmail(m.from_email)" class="ml-1 align-middle text-gray-400 hover:text-primary-600" title="Kopiuj adres nadawcy"><ClipboardDocumentIcon class="inline h-3.5 w-3.5" /></button>
                                        · {{ fullDate(m.date) }}
                                    </div>
                                    <div v-if="m.attachments?.length" class="mb-2 flex flex-wrap gap-2">
                                        <a v-for="att in m.attachments" :key="att.id" :href="route('crafter.argo-mail.messages.attachment', [m.id, att.id])"
                                            class="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-700 hover:bg-gray-100">
                                            <PaperClipIcon class="h-3.5 w-3.5" /> {{ att.filename }} <span class="text-gray-400">({{ formatSize(att.size) }})</span>
                                        </a>
                                    </div>
                                    <iframe :srcdoc="bodyDocFor(m)" @load="resizeIframe" sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin" class="w-full" style="min-height: 200px;" referrerpolicy="no-referrer"></iframe>
                                </div>
                            </div>
                        </div>
                        <div class="border-t border-gray-200 p-3 flex gap-2">
                            <Button :leftIcon="ArrowUturnLeftIcon" @click="openReply">Odpowiedz</Button>
                            <Button variant="outline" color="gray" :leftIcon="ArrowUturnRightIcon" @click="openForward">Przekaż</Button>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Menu kontekstowe (prawy-klik) -->
            <template v-if="ctx.open">
                <div class="fixed inset-0 z-40" @click="closeCtx" @contextmenu.prevent="closeCtx"></div>
                <div class="fixed z-50 w-60 bg-white rounded-md shadow-lg border border-gray-200 py-1" :style="{ top: ctx.y + 'px', left: ctx.x + 'px' }">
                    <div class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Działania</div>
                    <button type="button" :class="ctxItem" @click="markReadCtx(false)"><EnvelopeIcon class="h-4 w-4 text-gray-400" /> Oznacz jako nieodczytany</button>
                    <button type="button" :class="ctxItem" @click="markReadCtx(true)"><EnvelopeOpenIcon class="h-4 w-4 text-gray-400" /> Oznacz jako przeczytany</button>
                    <div class="border-t border-gray-100 my-1"></div>
                    <div class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Przypisz osobę</div>
                    <label class="flex items-center gap-2 px-3 py-1 text-xs text-gray-600 cursor-pointer">
                        <input type="checkbox" v-model="ctx.permanent" class="rounded text-primary-600 focus:ring-primary-500" />
                        Na stałe (kolejne od tego nadawcy)
                    </label>
                    <div class="max-h-36 overflow-y-auto">
                        <button v-for="u in users" :key="u.id" type="button" :class="ctxItem" @click="assignUserCtx(u.id)">
                            <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: u.color || '#9ca3af' }"></span> {{ u.name }}
                        </button>
                        <button type="button" :class="[ctxItem, 'text-gray-500']" @click="assignUserCtx(null)">— bez osoby —</button>
                        <div v-if="users.length === 0" class="px-3 py-1.5 text-xs text-gray-400">Brak osób (Ustawienia → Osoby)</div>
                    </div>
                    <div class="border-t border-gray-100 my-1"></div>
                    <div class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Kategoria</div>
                    <div class="max-h-36 overflow-y-auto">
                        <button v-for="c in categories" :key="c.id" type="button" :class="ctxItem" @click="assignCategoryCtx(c.id)">
                            <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: c.color || '#9ca3af' }"></span> {{ c.name }}
                        </button>
                        <button type="button" :class="[ctxItem, 'text-gray-500']" @click="assignCategoryCtx(null)">— bez kategorii —</button>
                    </div>
                    <div class="border-t border-gray-100 my-1"></div>
                    <div class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Katalog</div>
                    <div class="max-h-36 overflow-y-auto">
                        <button v-for="c in catalogs" :key="c.id" type="button" :class="ctxItem" :style="{ paddingLeft: (12 + c.depth * 12) + 'px' }" @click="assignCatalogCtx(c.id)">
                            <FolderIcon class="h-3.5 w-3.5 text-gray-400" /> {{ c.name }}
                        </button>
                        <button type="button" :class="[ctxItem, 'text-gray-500']" @click="assignCatalogCtx(null)">— bez katalogu —</button>
                    </div>
                    <div class="border-t border-gray-100 my-1"></div>
                    <button v-if="!filters.spam" type="button" :class="[ctxItem, 'text-orange-600']" @click="markSpamCtx"><NoSymbolIcon class="h-4 w-4" /> Oznacz jako SPAM (nadawca)</button>
                    <button v-else type="button" :class="[ctxItem, 'text-green-600']" @click="unspamCtx"><ArrowUturnLeftIcon class="h-4 w-4" /> Nie spam (przywróć nadawcę)</button>
                    <button type="button" :class="[ctxItem, 'text-red-600']" @click="trashCtx"><TrashIcon class="h-4 w-4" /> Do kosza</button>
                </div>
            </template>

            <!-- Szybki filtr z maila (reguła nadawca → folder, jak „Filtry") -->
            <template v-if="filterModal.open">
                <div class="fixed inset-0 z-40 bg-black/30" @click="closeFilterModal"></div>
                <div class="fixed z-50 inset-x-0 top-24 mx-auto w-full max-w-md bg-white rounded-lg shadow-2xl border border-gray-200">
                    <div class="flex items-center justify-between px-4 py-2 border-b border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5"><FunnelIcon class="h-4 w-4" /> Nowy filtr z maila</h3>
                        <button type="button" @click="closeFilterModal" class="text-gray-400 hover:text-gray-700"><XMarkIcon class="h-5 w-5" /></button>
                    </div>
                    <div class="p-4 space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Nadawca (adres lub @domena)</label>
                            <input v-model="filterModal.from_email" type="text" class="block w-full rounded-md border-gray-300 text-sm" placeholder="np. seller@amazon.it albo @amazon.it" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Tytuł zawiera <span class="text-gray-400">(opcjonalnie)</span></label>
                            <input v-model="filterModal.subject_contains" type="text" class="block w-full rounded-md border-gray-300 text-sm" placeholder="słowo w temacie — puste = każdy temat" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Folder docelowy</label>
                            <select v-model="filterModal.catalog_id" class="block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">— wybierz folder —</option>
                                <option v-for="c in catalogs" :key="c.id" :value="c.id">{{ catalogIndent(c.depth) + c.name }}</option>
                            </select>
                        </div>
                        <p class="text-xs text-gray-500">Pasujące maile (też już istniejące) trafią do tego folderu, a kolejne będą trafiać automatycznie.</p>
                    </div>
                    <div class="flex items-center justify-end gap-2 px-4 py-2 border-t border-gray-200">
                        <Button variant="outline" color="gray" @click="closeFilterModal">Anuluj</Button>
                        <Button :leftIcon="FunnelIcon" :loading="filterModal.saving" @click="submitFilter">Utwórz filtr</Button>
                    </div>
                </div>
            </template>

            <!-- Oznacz jako SPAM: ten adres albo CAŁA DOMENA -->
            <template v-if="spamModal.open">
                <div class="fixed inset-0 z-40 bg-black/30" @click="closeSpamModal"></div>
                <div class="fixed z-50 inset-x-0 top-24 mx-auto w-full max-w-md bg-white rounded-lg shadow-2xl border border-gray-200">
                    <div class="flex items-center justify-between px-4 py-2 border-b border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5"><NoSymbolIcon class="h-4 w-4 text-orange-600" /> Oznacz jako SPAM</h3>
                        <button type="button" @click="closeSpamModal" class="text-gray-400 hover:text-gray-700"><XMarkIcon class="h-5 w-5" /></button>
                    </div>
                    <div class="p-4 space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Nadawca</label>
                            <input v-model="spamModal.from_email" type="text" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <label class="flex items-start gap-2 cursor-pointer select-none">
                            <input type="checkbox" v-model="spamModal.wholeDomain" :disabled="!spamModalDomain" class="mt-0.5 rounded border-gray-300 text-orange-600 focus:ring-orange-500" />
                            <span class="text-sm text-gray-700">
                                Cała domena <span class="font-semibold">@{{ spamModalDomain || "—" }}</span> do spamu
                                <span class="block text-xs text-gray-400">zamiast tylko tego adresu — wszystkie maile z tej domeny</span>
                            </span>
                        </label>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Tytuł zawiera <span class="text-gray-400">(opcjonalnie)</span></label>
                            <input v-model="spamModal.subject_contains" type="text" class="block w-full rounded-md border-gray-300 text-sm" placeholder="np. Dyskusja — puste = wszystkie maile nadawcy" />
                        </div>
                        <p class="text-xs text-gray-500">Z <b>fragmentem tytułu</b> spamujesz tylko część maili nadawcy (np. Allegro „Dyskusja"), resztę zostawiasz. Pasujące maile (też już istniejące) znikną z głównej skrzynki i trafią do „Spam"; kolejne — automatycznie przy synchronizacji.</p>
                    </div>
                    <div class="flex items-center justify-end gap-2 px-4 py-2 border-t border-gray-200">
                        <Button variant="outline" color="gray" @click="closeSpamModal">Anuluj</Button>
                        <Button :leftIcon="NoSymbolIcon" :loading="spamModal.saving" @click="submitSpam">Do spamu</Button>
                    </div>
                </div>
            </template>

            <!-- Kompozytor: Nowa / Odpowiedz / Przekaż -->
            <template v-if="compose.open">
                <div class="fixed inset-0 z-40 bg-black/30" @click="closeCompose"></div>
                <div :class="compose.fullSize ? 'fixed z-50 inset-3' : 'fixed z-50 inset-x-0 bottom-0 mx-auto sm:inset-auto sm:right-6 sm:bottom-6 w-full sm:w-[680px]'" class="bg-white rounded-lg shadow-2xl border border-gray-200 flex flex-col max-h-[96vh]">
                    <div class="flex items-center justify-between px-4 py-2 border-b border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900">{{ compose.title }}</h3>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="compose.fullSize = !compose.fullSize" class="p-1 text-gray-400 hover:text-gray-700" :title="compose.fullSize ? 'Pomniejsz' : 'Full size'">
                                <component :is="compose.fullSize ? ArrowsPointingInIcon : ArrowsPointingOutIcon" class="h-5 w-5" />
                            </button>
                            <button type="button" @click="closeCompose" class="p-1 text-gray-400 hover:text-gray-700"><XMarkIcon class="h-5 w-5" /></button>
                        </div>
                    </div>
                    <div class="p-4 space-y-2 overflow-y-auto flex-1">
                        <div class="flex items-center gap-2">
                            <label class="w-14 shrink-0 text-xs text-gray-500">Od</label>
                            <select v-model="compose.account_id" class="flex-1 rounded-md border-gray-300 text-sm">
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }} &lt;{{ a.email }}&gt;</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="w-14 shrink-0 text-xs text-gray-500">Do</label>
                            <input v-model="compose.to" type="text" placeholder="adres@firma.pl, drugi@…" class="flex-1 rounded-md border-gray-300 text-sm" />
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="w-14 shrink-0 text-xs text-gray-500">DW</label>
                            <input v-model="compose.cc" type="text" placeholder="kopia (opcjonalnie)" class="flex-1 rounded-md border-gray-300 text-sm" />
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="w-14 shrink-0 text-xs text-gray-500">Temat</label>
                            <input v-model="compose.subject" type="text" class="flex-1 rounded-md border-gray-300 text-sm" />
                        </div>
                        <div class="flex items-center justify-end">
                            <label class="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer">
                                <input type="checkbox" v-model="compose.html" class="rounded text-primary-600 focus:ring-primary-500" /> Edytor HTML
                            </label>
                        </div>
                        <Wysiwyg v-if="compose.html" v-model="compose.body" name="compose-body" label="" :class="compose.fullSize ? '[&_.ProseMirror]:min-h-[55vh]' : '[&_.ProseMirror]:min-h-[220px]'" />
                        <textarea v-else v-model="compose.body" :rows="compose.fullSize ? 22 : 12" class="w-full rounded-md border-gray-300 text-sm" placeholder="Treść wiadomości…"></textarea>

                        <!-- Załączniki -->
                        <div class="pt-1">
                            <input ref="fileInput" type="file" multiple class="hidden" @change="onPickFiles" />
                            <button type="button" @click="fileInput?.click()" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs text-gray-700 hover:bg-gray-50">
                                <PaperClipIcon class="h-4 w-4 text-gray-400" /> Dodaj załącznik
                            </button>
                            <div v-if="compose.files.length" class="mt-2 flex flex-wrap gap-1.5">
                                <span v-for="(f, i) in compose.files" :key="i" class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-700">
                                    <PaperClipIcon class="h-3 w-3 text-gray-400" /> {{ f.name }} <span class="text-gray-400">({{ formatSize(f.size) }})</span>
                                    <button type="button" @click="removeFile(i)" class="text-gray-400 hover:text-red-600"><XMarkIcon class="h-3.5 w-3.5" /></button>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2 px-4 py-2 border-t border-gray-200">
                        <Button variant="outline" color="gray" @click="closeCompose">Anuluj</Button>
                        <Button :leftIcon="PaperAirplaneIcon" :loading="compose.sending" @click="sendMail">Wyślij</Button>
                    </div>
                </div>
            </template>

            <!-- Przypomnienie (prawy dolny róg) -->
            <div v-if="showReminder" class="fixed z-30 right-4 bottom-4 w-72 bg-white rounded-lg shadow-2xl border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-2 px-3 py-2 bg-amber-50 border-b border-amber-100">
                    <BellAlertIcon class="h-5 w-5 text-amber-500 shrink-0" />
                    <span class="text-sm font-semibold text-gray-800">Przypomnienie</span>
                    <button type="button" @click="dismissReminder" class="ml-auto text-gray-400 hover:text-gray-600" title="Ukryj"><XMarkIcon class="h-4 w-4" /></button>
                </div>
                <div class="p-3">
                    <p class="text-sm text-gray-700">Nieprzeczytane wiadomości: <span class="font-bold text-amber-600">{{ totalUnread }}</span></p>
                    <button type="button" @click="showUnread" class="mt-2 w-full rounded-md bg-amber-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-600">Pokaż nieprzeczytane</button>
                </div>
            </div>
        </template>
    </PageContent>
</template>

<script setup lang="ts">
import { reactive, ref, computed, onMounted, onBeforeUnmount } from "vue";
import { Link, router, Head } from "@inertiajs/vue3";
import axios from "axios";
import { debounce } from "lodash";
import { useToast } from "@brackets/vue-toastification";
import { PageContent, Button, Wysiwyg, NotificationBell } from "crafter/Components";
import {
    EnvelopeIcon, PlusIcon, PaperClipIcon, MagnifyingGlassIcon, Cog6ToothIcon,
    ArrowUturnLeftIcon, ArrowUturnRightIcon, ArrowsPointingOutIcon, ArrowsPointingInIcon,
    PencilSquareIcon, XMarkIcon, PaperAirplaneIcon, EnvelopeOpenIcon, NoSymbolIcon, BellAlertIcon, ChevronRightIcon, ArrowsUpDownIcon, ViewColumnsIcon, ClipboardDocumentIcon, FunnelIcon,
} from "@heroicons/vue/24/outline";
import { FolderIcon, TrashIcon } from "@heroicons/vue/24/solid";

const props = defineProps<{
    accounts: Array<{ id: number; label: string; email: string; color: string | null; is_active: boolean; sync_status: string; last_sync_at: string | null; unread: number; signature?: string | null }>;
    users: Array<{ id: number; name: string; color: string | null; unread: number }>;
    messages: any;
    categories: Array<{ id: number; name: string; color: string; unread: number }>;
    catalogs: Array<{ id: number; name: string; color: string | null; parent_id: number | null; depth: number; unread: number; total: number }>;
    filters: { account_id: number | null; user_id: number | null; category_id: number | null; catalog_id: number | null; unread: boolean; unfiled: boolean; trash: boolean; spam: boolean; color: string | null; q: string | null; sort: string };
    totalUnread: number;
    trashUnread: number;
    trashTotal: number;
    spamUnread: number;
    spamTotal: number;
    colorCounts: Record<string, number>;
}>();

const toast = useToast();
const localFilters = reactive({ q: props.filters.q ?? "", unread: props.filters.unread ?? false, unfiled: props.filters.unfiled ?? false, sort: props.filters.sort ?? "date_desc" });

// Zwijanie katalogów z podkatalogami (lewa kolumna) — stan w localStorage (per przeglądarka).
const collapsedCats = ref<Set<number>>(new Set());
try {
    const saved = JSON.parse(localStorage.getItem("argoMailCollapsedCats") || "[]");
    if (Array.isArray(saved)) collapsedCats.value = new Set(saved.map(Number));
} catch (e) { /* brak/uszkodzony zapis — ignoruj */ }

const catalogParent = computed(() => {
    const m = new Map<number, number | null>();
    props.catalogs.forEach((c) => m.set(c.id, c.parent_id));
    return m;
});
function catalogHasChildren(id: number): boolean {
    return props.catalogs.some((c) => c.parent_id === id);
}
// Kolor nazwy katalogu wg poziomu zagnieżdżenia: 1=czarny … 4+=najjaśniejszy szary.
function catalogDepthClass(depth: number): string {
    return depth >= 0 ? "text-gray-900" : "text-gray-900"; // czarna czcionka na każdym poziomie; głębokość = wcięcie
}
function catalogVisible(c: { parent_id: number | null }): boolean {
    let p = c.parent_id;
    while (p != null) {
        if (collapsedCats.value.has(p)) return false;
        p = catalogParent.value.get(p) ?? null;
    }
    return true;
}
function toggleCatalog(id: number) {
    const s = new Set(collapsedCats.value);
    s.has(id) ? s.delete(id) : s.add(id);
    collapsedCats.value = s;
    try { localStorage.setItem("argoMailCollapsedCats", JSON.stringify([...s])); } catch (e) { /* ignoruj */ }
}

const selected = ref<any | null>(null);
const selectedId = ref<number | null>(null);
const selectedIds = ref<number[]>([]);
let lastClickedIdx: number | null = null;
const loadingMsg = ref(false);
const listCollapsed = ref(false);
const previewCollapsed = ref(false); // „Rozszerz" — ukrywa podgląd (kol. 3), poszerza listę (kol. 2)
const thread = ref<any[]>([]);                     // cała konwersacja (od najstarszego)
const expandedIds = ref<Set<number>>(new Set());   // które maile w wątku są rozwinięte
const threadSubject = ref<string>("");
const assigningCatalog = ref(false);
const selectedAccountId = ref<number | null>(null);
const dragMessage = ref<{ id: number; from: string } | null>(null);
const dragOverCatalogId = ref<number | "all" | null>(null);
const compose = reactive<{ open: boolean; fullSize: boolean; html: boolean; title: string; account_id: number | null; to: string; cc: string; subject: string; body: string; in_reply_to: string | null; sending: boolean; files: File[] }>({
    open: false, fullSize: false, html: true, title: "Nowa wiadomość", account_id: null, to: "", cc: "", subject: "", body: "", in_reply_to: null, sending: false, files: [],
});
const fileInput = ref<HTMLInputElement | null>(null);

// Przypomnienie (prawy dolny róg): pokazuj dopóki liczba nieprzeczytanych > poziomu, na którym je schowano
const remindBaseline = ref(-1);
const showReminder = computed(() => props.totalUnread > 0 && props.totalUnread > remindBaseline.value);
const hasAnyColor = computed(() => Object.values(props.colorCounts || {}).some((n) => (n as number) > 0));

const ctx = reactive<{ open: boolean; x: number; y: number; messageId: number | null; from_email: string; permanent: boolean }>({ open: false, x: 0, y: 0, messageId: null, from_email: "", permanent: false });
const ctxItem = "w-full flex items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-50";
const pillBadge = "ml-1 rounded-full bg-gray-200 text-gray-700 px-1 text-[10px] font-semibold";

function pick(key: string, overrides: Record<string, any>) {
    return (key in overrides ? overrides[key] : (props.filters as any)[key]) || undefined;
}
function navigate(overrides: Record<string, any>) {
    clearSelection(); // zmiana widoku/sortu/strony czyści zaznaczenie (jak w Gmailu)
    router.get(route("crafter.argo-mail.index"), {
        account_id: pick("account_id", overrides),
        user_id: pick("user_id", overrides),
        category_id: pick("category_id", overrides),
        catalog_id: pick("catalog_id", overrides),
        trash: pick("trash", overrides),
        spam: pick("spam", overrides),
        color: pick("color", overrides),
        unread: localFilters.unread || undefined,
        unfiled: localFilters.unfiled || undefined,
        q: localFilters.q || undefined,
        sort: localFilters.sort && localFilters.sort !== "date_desc" ? localFilters.sort : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}
function applyFilters() { navigate({}); }
function clearSearch() { localFilters.q = ""; applyFilters(); } // „X" w wyszukiwarce
const debouncedApply = debounce(applyFilters, 400);
function selectAccount(id: number | null) { navigate({ account_id: id, trash: false, spam: false }); }
function selectUser(id: number | null) { navigate({ user_id: id, trash: false, spam: false }); }
function selectCategory(id: number | null) { navigate({ category_id: id, trash: false, spam: false }); }
function selectCatalog(id: number | null) {
    // Wejście w konkretny folder wyłącza „Ukryj maile w folderach" (inaczej: folder + tylko-bez-folderu = pusto).
    if (id !== null) localFilters.unfiled = false;
    navigate({ catalog_id: id, trash: false, spam: false });
}
// Symetria: włączenie „Ukryj maile w folderach" wychodzi z folderu do „Wszystkie".
function onUnfiledToggle() {
    if (localFilters.unfiled) navigate({ catalog_id: null, trash: false, spam: false });
    else applyFilters();
}
function selectTrash() { navigate({ trash: true, spam: false, catalog_id: null }); }
function selectSpam() { navigate({ spam: true, trash: false, catalog_id: null }); }
function selectColor(color: string) {
    const next = props.filters.color === color ? null : color;
    if (next) localFilters.unread = false; // kolor pokazuje wszystkie (też przeczytane) — zdejmij „tylko nieprzeczytane"
    navigate({ color: next });
}
const colorFilterList = [
    { key: "red", hex: "#ef4444", label: "Czerwony" },
    { key: "green", hex: "#22c55e", label: "Zielony" },
    { key: "blue", hex: "#3b82f6", label: "Niebieski" },
    { key: "orange", hex: "#f97316", label: "Pomarańczowy" },
];
function goto(url: string | null) { if (url) { clearSelection(); router.get(url, {}, { preserveState: true, preserveScroll: true }); } }

// ===== Drag & drop maila na katalog (→ reguła nadawcy + przeniesienie wszystkich jego maili) =====
function onDragStart(e: DragEvent, m: any) {
    const dragId = m.drag_id || m.id; // przeciągamy po adresie DRUGIEJ STRONY (nie po naszym wysłanym)
    dragMessage.value = { id: dragId, from: m.from_name || m.from_email || "nadawca" };
    if (e.dataTransfer) { e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", String(dragId)); }
}
function onDragEnd() { dragMessage.value = null; dragOverCatalogId.value = null; }
function onCatalogDragOver(key: number | "all") { if (dragMessage.value) dragOverCatalogId.value = key; }
function onCatalogDragLeave(key: number | "all") { if (dragOverCatalogId.value === key) dragOverCatalogId.value = null; }
async function onCatalogDrop(catalogId: number | null) {
    const drag = dragMessage.value;
    dragOverCatalogId.value = null;
    dragMessage.value = null;
    if (!drag) return;
    try {
        const { data } = await axios.post(route("crafter.argo-mail.messages.file-sender", drag.id), { catalog_id: catalogId });
        if (data.cleared) {
            toast.success(`Zdjęto katalog z ${data.count} maili od „${drag.from}" — nadawca wrócił do „Wszystkie".`);
        } else {
            toast.success(`Przeniesiono ${data.count} maili od „${drag.from}" do „${data.catalog?.name ?? "katalogu"}" — nadawca przypisany na stałe.`);
        }
        if (selectedId.value === drag.id && selected.value) {
            selected.value.catalog_id = catalogId;
            selected.value.catalog = data.catalog ? { id: data.catalog.id, name: data.catalog.name, color: null } : null;
        }
        reloadAll();
    } catch (e) {
        toast.error("Nie udało się przenieść.");
    }
}

// ===== Multi-select (Ctrl / Shift + klik) =====
function isSelected(id: number): boolean { return selectedIds.value.includes(id); }
function toggleSelect(id: number) {
    const i = selectedIds.value.indexOf(id);
    if (i >= 0) selectedIds.value.splice(i, 1);
    else selectedIds.value.push(id);
}
function clearSelection() { selectedIds.value = []; }
// „Zaznacz / odznacz wszystkie" — działa na wiadomościach z bieżącej strony listy.
const pageIds = computed(() => (props.messages.data as any[]).flatMap((m) => (m.ids && m.ids.length ? m.ids : [m.id])));
const allSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id: number) => selectedIds.value.includes(id)));
const someSelected = computed(() => !allSelected.value && pageIds.value.some((id: number) => selectedIds.value.includes(id)));
function toggleSelectAll() {
    if (allSelected.value) {
        selectedIds.value = selectedIds.value.filter((id) => !pageIds.value.includes(id));
    } else {
        const set = new Set(selectedIds.value);
        pageIds.value.forEach((id: number) => set.add(id));
        selectedIds.value = [...set];
    }
}
// Wątki: zaznaczenie operuje na WSZYSTKICH mailach wątku (selectedIds = id maili, nie wątków).
function threadIds(m: any): number[] { return m.ids && m.ids.length ? m.ids : [m.id]; }
function isThreadSelected(m: any): boolean { return threadIds(m).every((id: number) => selectedIds.value.includes(id)); }
function toggleThread(m: any) {
    const ids = threadIds(m);
    if (isThreadSelected(m)) selectedIds.value = selectedIds.value.filter((id) => !ids.includes(id));
    else { const s = new Set(selectedIds.value); ids.forEach((id: number) => s.add(id)); selectedIds.value = [...s]; }
}
function selectThreadRange(a: number, b: number) {
    const lo = Math.min(a, b), hi = Math.max(a, b);
    const s = new Set(selectedIds.value);
    (props.messages.data as any[]).slice(lo, hi + 1).forEach((t) => threadIds(t).forEach((id: number) => s.add(id)));
    selectedIds.value = [...s];
}
function onRowClick(e: MouseEvent, m: any, idx: number) {
    if (e.ctrlKey || e.metaKey) { toggleThread(m); lastClickedIdx = idx; return; }
    if (e.shiftKey && lastClickedIdx != null) { selectThreadRange(lastClickedIdx, idx); return; }
    clearSelection();
    lastClickedIdx = idx;
    openSingle(m.id, m); // klik nagłówka = najnowszy mail wątku (pojedynczo)
}
async function bulk(action: string, value: number | null = null) {
    if (selectedIds.value.length === 0) return;
    try {
        const { data } = await axios.post(route("crafter.argo-mail.messages.bulk"), { ids: selectedIds.value, action, value });
        toast.success(`Zaktualizowano ${data.count} wiadomości.`);
        clearSelection();
        reloadAll();
    } catch (e) {
        toast.error("Operacja masowa nie powiodła się.");
    }
}
async function bulkSpam() {
    if (selectedIds.value.length === 0) return;
    try {
        const { data } = await axios.post(route("crafter.argo-mail.messages.bulk"), { ids: selectedIds.value, action: "spam" });
        toast.success(`Przeniesiono do spamu: ${data.count} wiad. (${data.senders} nadawców).`);
        clearSelection();
        reloadAll();
    } catch (e) {
        toast.error("Nie udało się przenieść do spamu.");
    }
}
function bulkSelect(action: string, e: Event) {
    const el = e.target as HTMLSelectElement;
    if (!el.value) return;
    bulk(action, Number(el.value));
    el.value = "";
}
async function setColor(ids: number[], color: string | null) {
    if (!ids.length) return;
    try {
        await axios.post(route("crafter.argo-mail.messages.color"), { ids, color });
        (props.messages.data as any[]).forEach((m) => { if (ids.includes(m.id)) m.color_flag = color; });
        if (selected.value && ids.includes(selected.value.id)) selected.value.color_flag = color;
        toast.success(color ? "Pokolorowano." : "Usunięto kolor.");
        // odśwież liczniki kolorów (kwadraciki-filtry) bez przeładowania listy
        router.reload({ only: ["colorCounts"], preserveScroll: true, preserveState: true });
    } catch (e) {
        toast.error("Nie udało się ustawić koloru.");
    }
}
function colorOf(id: number): string | null {
    const m = (props.messages.data as any[]).find((x) => x.id === id);
    if (m) return m.color_flag ?? null;
    if (selected.value && selected.value.id === id) return selected.value.color_flag ?? null;
    return null;
}
function applyColor(ids: number[], color: string | null) {
    if (!ids.length) return;
    if (color === null) { setColor(ids, null); return; }
    // toggle: jeśli wszystkie zaznaczone mają już ten kolor → zdejmij
    const allHave = ids.every((id) => colorOf(id) === color);
    setColor(ids, allHave ? null : color);
}
function bulkColor(color: string | null) {
    applyColor([...selectedIds.value], color);
}
function colorHex(flag: string | null): string {
    const map: Record<string, string> = { red: "#ef4444", green: "#22c55e", blue: "#3b82f6", orange: "#f97316" };
    return map[flag ?? ""] ?? "transparent";
}
function rowBg(m: any): string {
    if (isThreadSelected(m)) return "bg-blue-100";
    if (selectedId.value === m.id) return "bg-blue-50";
    return "hover:bg-gray-50";
}

async function openThread(row: any) {
    previewCollapsed.value = false; // klik w rozmowę wraca do układu 1‑2‑3 (podgląd widoczny)
    selectedId.value = row.id;
    selectedAccountId.value = row.account_id ?? null;
    loadingMsg.value = true;
    selected.value = null;
    thread.value = [];
    row.is_read = true; row.unread = 0; // optymistycznie: cały wątek przeczytany
    try {
        const { data } = await axios.get(route("crafter.argo-mail.messages.thread", row.id));
        thread.value = data.messages || [];
        threadSubject.value = data.subject || (thread.value[0]?.subject ?? "");
        selected.value = thread.value.length ? thread.value[thread.value.length - 1] : null; // najnowszy = kontekst odpowiedzi
        expandedIds.value = new Set(selected.value ? [selected.value.id] : []); // rozwiń najnowszy
    } catch (e) {
        toast.error("Nie udało się wczytać rozmowy.");
    } finally {
        loadingMsg.value = false;
    }
}
// ===== Outlook-style: rozwijanie wątku INLINE w liście (strzałka + wcięte maile) =====
const expandedThreads = ref<Set<number>>(new Set());
function isThreadExpanded(m: any): boolean { return expandedThreads.value.has(m.id); }
function toggleThreadExpand(m: any) {
    const s = new Set(expandedThreads.value);
    s.has(m.id) ? s.delete(m.id) : s.add(m.id);
    expandedThreads.value = s;
}
function subRowBg(sub: any): string { return selectedId.value === sub.id ? "bg-blue-50" : "hover:bg-gray-100"; }
function markRowRead(id: number, row: any) {
    if (!row) return;
    const sub = (row.messages || []).find((s: any) => s.id === id);
    if (sub) { if (!sub.is_read) { sub.is_read = true; row.unread = Math.max(0, (row.unread || 0) - 1); } }
    else if (row.id === id) { row.unread = Math.max(0, (row.unread || 0) - 1); }
    row.is_read = (row.unread || 0) === 0;
}
// Klik w mail (nagłówek wątku lub wcięty wiersz) → otwiera POJEDYNCZY mail w podglądzie.
async function openSingle(id: number, row: any) {
    previewCollapsed.value = false;
    selectedId.value = id;
    selectedAccountId.value = row?.account_id ?? selectedAccountId.value;
    loadingMsg.value = true;
    selected.value = null;
    thread.value = [];
    try {
        const { data } = await axios.get(route("crafter.argo-mail.messages.show", id));
        thread.value = [data];
        threadSubject.value = data.subject || "";
        selected.value = data;
        expandedIds.value = new Set([data.id]);
        markRowRead(id, row);
    } catch (e) {
        toast.error("Nie udało się wczytać wiadomości.");
    } finally {
        loadingMsg.value = false;
    }
}
// Stos wątku: rozwijanie/zwijanie pojedynczych maili
function isExpanded(id: number): boolean { return expandedIds.value.has(id); }
function toggleExpand(id: number) {
    const s = new Set(expandedIds.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expandedIds.value = s;
}
function baseDoc(html: string): string {
    return '<base target="_blank"><meta name="referrer" content="no-referrer">' + html;
}
function bodyDocFor(m: any): string {
    if (m.body_html) return baseDoc(m.body_html);
    const text = m.body_text || "(brak treści)";
    return baseDoc('<pre style="white-space:pre-wrap;word-break:break-word;font-family:ui-sans-serif,system-ui,sans-serif;font-size:14px;color:#111;padding:16px;margin:0;">' + escapeHtml(text) + "</pre>");
}
function resizeIframe(e: Event) {
    const f = e.target as HTMLIFrameElement;
    const fit = () => {
        try {
            const d = f.contentDocument || f.contentWindow?.document;
            const h = d ? Math.max(d.body?.scrollHeight || 0, d.documentElement?.scrollHeight || 0) : 0;
            if (h > 0) f.style.height = Math.min(h + 24, 12000) + "px";
        } catch (_) { /* brak dostępu do treści — zostaw bieżącą wysokość (min-height) */ }
    };
    fit();
    setTimeout(fit, 300); // ponowny pomiar po doładowaniu obrazków/layoutu
}
function snippetOf(m: any): string {
    if (m.snippet) return m.snippet;
    return (m.body_text || "").replace(/\s+/g, " ").trim().slice(0, 140);
}
// ===== Akcje na CAŁEJ rozmowie =====
function threadMsgIds(): number[] { return thread.value.map((m: any) => m.id); }
function clearThreadView() { thread.value = []; selected.value = null; selectedId.value = null; listCollapsed.value = false; }
async function trashThread() {
    const ids = threadMsgIds();
    if (!ids.length) return;
    try {
        await axios.post(route("crafter.argo-mail.messages.bulk"), { ids, action: props.filters.trash ? "restore" : "trash" });
        toast.success(props.filters.trash ? "Przywrócono rozmowę." : "Rozmowa w koszu.");
        clearThreadView();
        reloadAll();
    } catch (e) { toast.error("Nie udało się."); }
}
async function assignCatalogThread(value: string | number) {
    const ids = threadMsgIds();
    if (!ids.length) return;
    const catalogId = value === "" || value === null ? null : Number(value);
    assigningCatalog.value = true;
    try {
        await axios.post(route("crafter.argo-mail.messages.bulk"), { ids, action: "catalog", value: catalogId });
        thread.value.forEach((m: any) => { m.catalog_id = catalogId; });
        toast.success(catalogId ? "Przeniesiono rozmowę do katalogu." : "Zdjęto rozmowę z katalogu.");
        router.reload({ only: ["catalogs", "messages"] });
    } catch (e) { toast.error("Nie udało się przypisać katalogu."); } finally { assigningCatalog.value = false; }
}
// Spam celuje w adres DRUGIEJ STRONY (ostatni przychodzący w wątku), nie w nasz własny.
const spamTargetId = computed<number | null>(() => {
    for (let i = thread.value.length - 1; i >= 0; i--) if (!thread.value[i].is_sent) return thread.value[i].id;
    return selected.value?.id ?? null;
});

async function assignCatalog(value: string | number) {
    if (!selected.value) return;
    const catalogId = value === "" || value === null ? null : Number(value);
    assigningCatalog.value = true;
    try {
        const { data } = await axios.post(route("crafter.argo-mail.messages.catalog", selected.value.id), { catalog_id: catalogId });
        selected.value.catalog = data.catalog;
        selected.value.catalog_id = data.catalog ? data.catalog.id : null;
        toast.success(data.catalog ? `Przeniesiono do: ${data.catalog.name}` : "Usunięto z katalogu");
        router.reload({ only: ["catalogs", "messages"] });
    } catch (e) {
        toast.error("Nie udało się przypisać katalogu.");
    } finally {
        assigningCatalog.value = false;
    }
}

async function trashMessage() {
    if (!selected.value) return;
    try {
        await axios.post(route("crafter.argo-mail.messages.trash", selected.value.id), { trashed: true });
        toast.success("Przeniesiono do kosza.");
        afterTrashChange();
    } catch (e) { toast.error("Nie udało się przenieść do kosza."); }
}
async function restoreMessage() {
    if (!selected.value) return;
    try {
        await axios.post(route("crafter.argo-mail.messages.trash", selected.value.id), { trashed: false });
        toast.success("Przywrócono z kosza.");
        afterTrashChange();
    } catch (e) { toast.error("Nie udało się przywrócić."); }
}
function afterTrashChange() {
    selected.value = null;
    selectedId.value = null;
    listCollapsed.value = false;
    reloadAll();
}

// ===== Kompozytor =====
function defaultAccountId(): number | null {
    return selectedAccountId.value || props.accounts.find((a) => a.is_active)?.id || props.accounts[0]?.id || null;
}
function signatureBlock(accId: number | null): string {
    const sig = props.accounts.find((a) => a.id === accId)?.signature;
    return sig && String(sig).trim() !== "" ? `\n\n-- \n${sig}` : "";
}
function quoteOriginal(): string {
    const s = selected.value;
    if (!s) return "";
    const orig = s.body_text || (s.body_html ? String(s.body_html).replace(/<[^>]+>/g, " ") : "") || "";
    return `\n\n--- Oryginalna wiadomość ---\nOd: ${s.from_name || ""} <${s.from_email || ""}>\nData: ${fullDate(s.date)}\nTemat: ${s.subject || ""}\n\n${orig}`;
}
function nl2brHtml(text: string): string {
    return (text || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, "<br>");
}
function bodyFor(acc: number | null, withQuote: boolean): string {
    const plain = signatureBlock(acc) + (withQuote ? quoteOriginal() : "");
    return compose.html ? nl2brHtml(plain) : plain;
}
function openCompose() {
    const acc = defaultAccountId();
    Object.assign(compose, { open: true, fullSize: false, title: "Nowa wiadomość", account_id: acc, to: "", cc: "", subject: "", body: bodyFor(acc, false), in_reply_to: null, files: [] });
}
function openReply() {
    if (!selected.value) return;
    const acc = selectedAccountId.value || defaultAccountId();
    const subj = selected.value.subject || "";
    Object.assign(compose, {
        open: true, fullSize: false, title: "Odpowiedz",
        account_id: acc,
        to: selected.value.is_sent ? (selected.value.to?.[0]?.email || "") : (selected.value.from_email || ""), cc: "",
        subject: /^re:/i.test(subj) ? subj : "Re: " + subj,
        body: bodyFor(acc, true),
        in_reply_to: selected.value.message_id || null,
        files: [],
    });
}
function openForward() {
    if (!selected.value) return;
    const acc = selectedAccountId.value || defaultAccountId();
    const subj = selected.value.subject || "";
    Object.assign(compose, {
        open: true, fullSize: false, title: "Przekaż",
        account_id: acc,
        to: "", cc: "",
        subject: /^fwd:/i.test(subj) ? subj : "Fwd: " + subj,
        body: bodyFor(acc, true),
        in_reply_to: null,
        files: [],
    });
}
function closeCompose() { compose.open = false; }
async function sendMail() {
    if (!compose.account_id) { toast.error("Wybierz skrzynkę nadawcy."); return; }
    if (!compose.to.trim()) { toast.error("Podaj odbiorcę."); return; }
    compose.sending = true;
    try {
        const fd = new FormData();
        fd.append("account_id", String(compose.account_id));
        fd.append("to", compose.to);
        fd.append("cc", compose.cc || "");
        fd.append("subject", compose.subject || "");
        fd.append("body", compose.body || "");
        fd.append("is_html", compose.html ? "1" : "0");
        if (compose.in_reply_to) fd.append("in_reply_to", compose.in_reply_to);
        compose.files.forEach((f) => fd.append("attachments[]", f));
        const { data } = await axios.post(route("crafter.argo-mail.send"), fd);
        if (data.ok) { toast.success("Wiadomość wysłana."); closeCompose(); reloadAll(); }
        else { toast.error(data.message || "Nie udało się wysłać."); }
    } catch (e: any) {
        toast.error(e?.response?.data?.message || "Nie udało się wysłać.");
    } finally {
        compose.sending = false;
    }
}

// ===== Menu kontekstowe (prawy-klik) =====
function openContextMenu(e: MouseEvent, m: any) {
    ctx.open = true;
    ctx.x = Math.min(e.clientX, window.innerWidth - 250);
    ctx.y = Math.min(e.clientY, window.innerHeight - 440);
    ctx.messageId = m.id;
    ctx.from_email = m.from_email || "";
    ctx.permanent = false;
}
function closeCtx() { ctx.open = false; }
function reloadAll() {
    router.reload({ only: ["messages", "accounts", "users", "categories", "catalogs", "totalUnread", "trashUnread", "trashTotal", "spamUnread", "spamTotal", "colorCounts"] });
}
async function assignUserCtx(userId: number | null) {
    const id = ctx.messageId, perm = ctx.permanent;
    closeCtx();
    try {
        await axios.post(route("crafter.argo-mail.messages.user", id), { user_id: userId, permanent: perm });
        toast.success(perm && userId ? "Przypisano osobę (na stałe)." : "Zapisano przypisanie osoby.");
        reloadAll();
    } catch (e) { toast.error("Nie udało się przypisać osoby."); }
}
async function assignCategoryCtx(categoryId: number | null) {
    const id = ctx.messageId;
    closeCtx();
    try {
        await axios.post(route("crafter.argo-mail.messages.category", id), { category_id: categoryId });
        toast.success("Zapisano kategorię.");
        reloadAll();
    } catch (e) { toast.error("Nie udało się przypisać kategorii."); }
}
async function assignCatalogCtx(catalogId: number | null) {
    const id = ctx.messageId;
    closeCtx();
    try {
        await axios.post(route("crafter.argo-mail.messages.catalog", id), { catalog_id: catalogId });
        toast.success("Przeniesiono do katalogu.");
        reloadAll();
    } catch (e) { toast.error("Nie udało się przenieść."); }
}
async function trashCtx() {
    const id = ctx.messageId;
    closeCtx();
    try {
        await axios.post(route("crafter.argo-mail.messages.trash", id), { trashed: true });
        toast.success("Przeniesiono do kosza.");
        if (selectedId.value === id) { selected.value = null; selectedId.value = null; }
        reloadAll();
    } catch (e) { toast.error("Nie udało się."); }
}
async function markReadCtx(read: boolean) {
    const id = ctx.messageId;
    closeCtx();
    if (!id) return;
    try {
        await axios.post(route("crafter.argo-mail.messages.bulk"), { ids: [id], action: read ? "read" : "unread" });
        const m = (props.messages.data as any[]).find((x) => x.id === id);
        if (m) m.is_read = read;
        toast.success(read ? "Oznaczono jako przeczytane." : "Oznaczono jako nieodczytane.");
        reloadAll();
    } catch (e) {
        toast.error("Nie udało się.");
    }
}

// ===== SPAM (nadawca → spam / nie spam) =====
async function spamRequest(messageId: number, unspam: boolean) {
    const url = unspam
        ? route("crafter.argo-mail.messages.unspam", messageId)
        : route("crafter.argo-mail.messages.spam", messageId);
    const { data } = await axios.post(url);
    selected.value = null; selectedId.value = null; thread.value = [];
    reloadAll();
    return data;
}
function markSpamCtx() {
    const em = ctx.from_email; closeCtx(); openSpamModal(em);
}
async function unspamCtx() {
    const id = ctx.messageId; closeCtx(); if (!id) return;
    try { await spamRequest(id, true); toast.success("Przywrócono nadawcę (nie spam)."); }
    catch (e) { toast.error("Nie udało się."); }
}
function markSpamSelected() {
    openSpamModal(selected.value?.from_email);
}
async function unspamSelected() {
    const id = spamTargetId.value;
    if (!id) return;
    try { await spamRequest(id, true); toast.success("Przywrócono nadawcę (nie spam)."); }
    catch (e) { toast.error("Nie udało się."); }
}

// ===== Przypomnienie (prawy dolny róg) =====
function dismissReminder() { remindBaseline.value = props.totalUnread; }
function showUnread() {
    localFilters.unread = true;
    remindBaseline.value = props.totalUnread;
    navigate({ account_id: null, user_id: null, category_id: null, catalog_id: null, trash: false, spam: false });
}

// ===== Załączniki w kompozytorze =====
function onPickFiles(e: Event) {
    const input = e.target as HTMLInputElement;
    if (input.files) compose.files.push(...Array.from(input.files));
    input.value = "";
}
function removeFile(i: number) { compose.files.splice(i, 1); }

function onKeydown(e: KeyboardEvent) {
    if (e.key === "Escape" && ctx.open) { closeCtx(); return; }
    // Kolorowanie: 1=czerwony, 2=zielony, 3=niebieski, 0=bez koloru (bez Ctrl — przeglądarka przejmuje Ctrl+cyfra na zakładki)
    if (["1", "2", "3", "4", "0"].includes(e.key) && !e.ctrlKey && !e.metaKey && !e.altKey) {
        const tc = e.target as HTMLElement | null;
        if (tc && (tc.tagName === "INPUT" || tc.tagName === "TEXTAREA" || tc.tagName === "SELECT" || tc.isContentEditable)) return;
        const ids = selectedIds.value.length ? [...selectedIds.value] : (selectedId.value ? [selectedId.value] : []);
        if (!ids.length) return;
        e.preventDefault();
        const map: Record<string, string | null> = { "1": "red", "2": "green", "3": "blue", "4": "orange", "0": null };
        applyColor(ids, map[e.key]);
        return;
    }
    if (e.key !== "Delete") return;
    const t = e.target as HTMLElement | null;
    if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.tagName === "SELECT" || t.isContentEditable)) return;
    if (!thread.value.length) return;
    e.preventDefault();
    trashThread();
}
let syncTimer: number | undefined;
onMounted(() => {
    window.addEventListener("keydown", onKeydown);
    document.body.classList.add("argo-mail-hide-topbar");   // chowa pusty globalny górny pasek na tej stronie (dzwonek jest w nagłówku)
    // Auto-odświeżanie co 60 s — pokazuje maile dociągnięte w tle (mail-sync-loop.bat / cron) + odświeża licznik przypomnienia.
    syncTimer = window.setInterval(() => {
        router.reload({
            only: ["messages", "accounts", "users", "categories", "catalogs", "totalUnread", "trashUnread", "trashTotal", "spamUnread", "spamTotal", "colorCounts"],
            preserveScroll: true,
            preserveState: true,
        });
    }, 60000);
});
onBeforeUnmount(() => {
    window.removeEventListener("keydown", onKeydown);
    document.body.classList.remove("argo-mail-hide-topbar");
    if (syncTimer) window.clearInterval(syncTimer);
});

const bodyDoc = computed(() => {
    if (!selected.value) return "";
    // <base target="_blank"> → linki z maila otwierają się w nowej karcie
    const base = '<base target="_blank"><meta name="referrer" content="no-referrer">';
    if (selected.value.body_html) return base + selected.value.body_html;
    const text = selected.value.body_text || "(brak treści)";
    return base + '<pre style="white-space:pre-wrap;word-break:break-word;font-family:ui-sans-serif,system-ui,sans-serif;font-size:14px;color:#111;padding:16px;margin:0;">' + escapeHtml(text) + "</pre>";
});
function escapeHtml(s: string): string { return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); }
function recipientList(list: Array<{ email: string; name: string }> | undefined): string {
    if (!list || !list.length) return "";
    return list.map((r) => r.name || r.email).join(", ");
}
function copyEmail(email: string | null) {
    if (!email) return;
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(email).then(
            () => toast.success(`Skopiowano: ${email}`),
            () => toast.error("Nie udało się skopiować."),
        );
    } else {
        toast.error("Przeglądarka nie wspiera kopiowania.");
    }
}

// ===== Szybki filtr z maila (reguła nadawca → folder, jak „Filtry") =====
const filterModal = reactive<{ open: boolean; from_email: string; subject_contains: string; catalog_id: number | null; saving: boolean }>({ open: false, from_email: "", subject_contains: "", catalog_id: null, saving: false });
function openFilterModal() {
    if (!selected.value) return;
    filterModal.open = true;
    filterModal.from_email = selected.value.from_email || "";
    filterModal.subject_contains = "";
    filterModal.catalog_id = selected.value.catalog_id ?? null;
    filterModal.saving = false;
}
function closeFilterModal() { filterModal.open = false; }

// ===== Oznacz jako SPAM (popup: ten adres albo CAŁA DOMENA) =====
const spamModal = reactive<{ open: boolean; from_email: string; subject_contains: string; wholeDomain: boolean; saving: boolean }>({ open: false, from_email: "", subject_contains: "", wholeDomain: false, saving: false });
const spamModalDomain = computed(() => {
    const at = spamModal.from_email.lastIndexOf("@");
    return at >= 0 ? spamModal.from_email.slice(at + 1).trim().toLowerCase() : "";
});
function openSpamModal(fromEmail: string | null | undefined) {
    const email = (fromEmail || "").trim().toLowerCase();
    if (!email) { toast.error("Brak adresu nadawcy."); return; }
    spamModal.from_email = email;
    spamModal.subject_contains = "";
    spamModal.wholeDomain = false;
    spamModal.saving = false;
    spamModal.open = true;
}
function closeSpamModal() { spamModal.open = false; }
function submitSpam() {
    const value = (spamModal.wholeDomain && spamModalDomain.value) ? "@" + spamModalDomain.value : spamModal.from_email.trim().toLowerCase();
    if (!value) { toast.error("Podaj adres lub domenę."); return; }
    const subject = spamModal.subject_contains.trim();
    spamModal.saving = true;
    router.post(route("crafter.argo-mail.spam.store"), { from_email: value, subject_contains: subject || null }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const who = spamModal.wholeDomain ? `Cała domena @${spamModalDomain.value}` : "Nadawca";
            toast.success(subject ? `${who} + temat „${subject}" w spamie.` : `${who} w spamie.`);
            closeSpamModal();
            selected.value = null; selectedId.value = null; thread.value = [];
            reloadAll();
        },
        onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? "Nie udało się oznaczyć spamu."),
        onFinish: () => { spamModal.saving = false; },
    });
}
// Dwuklik w mail = otwórz pojedynczo + tryb Full size (lista schowana, podgląd na całość)
function dblFull(id: number, row: any) { openSingle(id, row); listCollapsed.value = true; }
function submitFilter() {
    const email = filterModal.from_email.trim();
    if (!email || !filterModal.catalog_id) { toast.error("Podaj nadawcę i wybierz folder."); return; }
    filterModal.saving = true;
    router.post(route("crafter.argo-mail.rules.store"), {
        from_email: email,
        subject_contains: filterModal.subject_contains.trim() || null,
        catalog_id: filterModal.catalog_id,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { toast.success("Filtr zapisany — pasujące maile trafiły do folderu."); closeFilterModal(); },
        onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? "Nie udało się zapisać filtru."),
        onFinish: () => { filterModal.saving = false; },
    });
}
function catalogIndent(depth: number): string { return depth > 0 ? "   ".repeat(depth - 1) + "└ " : ""; }

function pillClass(active: boolean) {
    return [
        "inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs whitespace-nowrap transition-colors",
        active ? "border-primary-300 bg-primary-50 text-primary-700" : "border-gray-200 bg-white text-gray-600 hover:bg-gray-50",
    ];
}
function catalogNodeClass(id: number | null | string) {
    let active: boolean;
    if (id === "trash") active = !!props.filters.trash;
    else if (id === "spam") active = !!props.filters.spam;
    else active = !props.filters.trash && !props.filters.spam && (props.filters.catalog_id ?? null) === id;
    return [
        "w-full flex items-center gap-2 pr-2 py-1.5 text-sm text-left hover:bg-gray-50 transition-colors",
        active ? "bg-primary-50 text-primary-700" : "text-gray-600",
    ];
}
function shortDate(iso: string | null): string {
    if (!iso) return "";
    const d = new Date(iso), now = new Date();
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString("pl-PL", { hour: "2-digit", minute: "2-digit" });
    return d.toLocaleDateString("pl-PL", { day: "2-digit", month: "2-digit", year: "numeric" });
}
function fullDate(iso: string | null): string {
    if (!iso) return "";
    return new Date(iso).toLocaleString("pl-PL", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
}
function formatSize(bytes: number | null): string {
    if (!bytes) return "?";
    if (bytes < 1024) return bytes + " B";
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + " KB";
    return (bytes / 1024 / 1024).toFixed(1) + " MB";
}
function chipStyle(color: string | null) {
    const c = color || "#9ca3af";
    return { backgroundColor: c + "22", color: c };
}
</script>

<style>
/* Na stronie Argo Mail chowamy pusty globalny górny pasek (dzwonek przeniesiony do nagłówka). Tylko desktop (md+) — na mobile zostaje hamburger. */
@media (min-width: 768px) {
    body.argo-mail-hide-topbar [data-app-topbar] { display: none !important; }
}
</style>
