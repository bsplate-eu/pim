const defaultTheme = require("tailwindcss/defaultTheme");
const colors = require("tailwindcss/colors");

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./resources/views/crafter.blade.php",
    "./resources/js/crafter/**/*.vue",
  ],

  theme: {
    extend: {
      colors: {
        primary: {
            '50': '#eef0f3',
            '100': '#ffcccc',
            '200': '#ff9999',
            '300': '#ff6666',
            '400': '#ff3333',
            '500': '#c30000', // Kolor bazowy
            '600': '#b20000',
            '700': '#990000',
            '800': '#800000',
            '900': '#660000',
            '950': '#4d0000'
        },
        sidebar: {
            '50':  '#f1f3f8',
            '100': '#dde2ed',
            '200': '#bcc5d9',
            '300': '#94a1c1',
            '400': '#6c7eaa',
            '500': '#3e5288',
            '600': '#15275a', // granat (główny kolor sidebara)
            '700': '#11204a',
            '800': '#0d193b',
            '900': '#08112a',
        },
        secondary: colors.fuchsia,
        gray: colors.slate,
        warning: colors.amber,
        danger: colors.red,
        success: colors.lime,
        info: colors.sky,
      },
      fontFamily: {
        sans: ["Nunito", ...defaultTheme.fontFamily.sans],
      },
      screens: {
        '3xl': '1800px',
      },
    },
  },

  plugins: [require("@tailwindcss/typography"), require("@tailwindcss/forms")],
};
