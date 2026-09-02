<!--
  Fabryka — ikona dzialu Produkcja.

  Heroicons (jedyny zestaw w projekcie) nie ma fabryki: WrenchScrewdriver to
  warsztat, Cog gryzie sie z "Ustawieniami systemu", BuildingOffice2 jest zajete
  przez Argo HQ. Stad wlasny rysunek — pilnowana konwencja heroicons/24/outline:
  viewBox 24x24, stroke-width 1.5, currentColor, zaokraglone laczenia. Dzieki
  temu klasy rozmiaru z SidebarGroup dzialaja bez zmian.

  Ksztalt: zabkowany dach hali (trzy spadki) + komin po prawej.
-->
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        stroke-width="1.5"
        stroke="currentColor"
        aria-hidden="true"
    >
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M2.25 20.25h19.5"
        />
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M4.5 20.25v-9l4.5 3v-3l4.5 3v-3l4.5 3V6.75h2.25v13.5"
        />
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M8.25 20.25v-2.25h2.25v2.25"
        />
    </svg>
</template>
