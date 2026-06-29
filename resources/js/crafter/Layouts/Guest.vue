<template>
    <div class="grid h-screen grid-cols-12 overflow-y-hidden bg-white">

        <div
            class="
        flex
        items-center
        justify-center
        w-full
        max-w-sm
        col-span-12
        p-4
        mx-auto
        text-gray-900
        md:p-8 md:col-span-6
        lg:col-span-6
        flex-2
        md:pb-48 md:pt-40
      "
        >
            <div class="w-full">
                <img src="/logo.jpg" alt="PIM">

                <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
                    <slot/>
                </div>
                <div
                    class="
            pt-24
            mt-0
            text-sm
            not-italic
            font-medium
            leading-relaxed
            text-left text-gray-400
            md:pt-40
          "
                >
                    <p class="mb-3">
                        Copyright © {{ new Date().getFullYear() }} PIM

                    </p>
                </div>
            </div>
        </div>
        <div
            class="
        relative
        flex-col
        items-center
        justify-center
        hidden
        w-full
        h-full
        pl-10
        bg-no-repeat bg-cover
        md:col-span-6
        lg:col-span-6
        md:flex
        content-box
        overflow-hidden
        background-image
      "
        >


        </div>
    </div>
</template>
<style>
.background-image{
    background-image: url(/baner.jpg);
    background-position: center;
}
.background-image::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(8, 63, 115, 0.58); /* Kolor z przezroczystością */
    z-index: 1; /* Ustawienie wyższego indeksu z-index niż obrazek */
}
</style>

<script setup lang="ts">

</script>
