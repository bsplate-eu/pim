<!--
  Magazyn — ikona dzialu w menu glownym.

  Heroicons nie ma magazynu, a najblizsze kandydatki odpadaja: ArchiveBox to
  pojedyncze pudlo (za maly desygnat na caly dzial), BuildingOffice2 jest
  zajety przez Argo HQ, Cube przez Argo PIM. Stad wlasny rysunek — tak samo
  jak przy LaserCutIcon dla Produkcji.

  Konwencja heroicons/24/outline: viewBox 24x24, stroke-width 1.5, currentColor,
  zaokraglone laczenia — rozmiar dziedziczy z klasy nadanej w Sidebar.vue.

  Ksztalt: hala ze spadzistym dachem, pod nia brama z ryglem. Brama zamiast
  regalow, bo przy 20 px poziome kreski regalow zlewaja sie w plame.
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
            d="M3.75 20.25V9.75L12 4.5l8.25 5.25v10.5"
        />
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M2.25 20.25h19.5"
        />
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M9.75 20.25V15h4.5v5.25M9.75 17.25h4.5"
        />
    </svg>
</template>
