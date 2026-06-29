import { defineConfig, splitVendorChunkPlugin } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";
const path = require("path");

export default defineConfig({
    plugins: [
        splitVendorChunkPlugin(),
        laravel({
            input: [
                "resources/js/crafter/index.ts",
                "resources/css/crafter.css",
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    css: {
        postcss: {
            plugins: [
                require("tailwindcss")({
                    config: "./tailwind.config.js",
                }),
            ],
        },
    },
    resolve: {
        alias: {
            "@": path.resolve(__dirname, "./resources/js"),
            "crafter": path.resolve(
                __dirname,
                "./resources/js/crafter"
            ),
            ziggy: path.resolve(__dirname, "./vendor/tightenco/ziggy"),
        },
    }
});
