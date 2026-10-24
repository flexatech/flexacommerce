import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { resolve } from "node:path";

const root = resolve(__dirname);

// Entries are keyed by their path from this folder ("admin/main.tsx"); that
// key is what Admin\Enqueue looks up in the manifest and on the dev server.
export default defineConfig({
    root,
    base: "./",
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: {
            "@": root,
        },
    },
    server: {
        port: 5181,
        strictPort: true,
        cors: true,
        origin: "http://localhost:5181",
        hmr: {
            host: "localhost",
            protocol: "ws",
        },
    },
    build: {
        manifest: true,
        emptyOutDir: true,
        outDir: resolve(root, "../assets/dist"),
        rollupOptions: {
            input: {
                admin: resolve(root, "admin/main.tsx"),
                "product-editor": resolve(root, "admin/product-editor.tsx"),
            },
        },
    },
});
