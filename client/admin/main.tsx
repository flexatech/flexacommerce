import "@/shared/styles/index.css";

import React from "react";
import { QueryCache, QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { createRoot } from "react-dom/client";

import { showToast } from "@/shared/components/showToast";
import { __, sprintf } from "@/shared/lib/i18n";
import { App } from "./App";

// Module scope on purpose: every root mounted on the page shares one cache.
const queryClient = new QueryClient({
    queryCache: new QueryCache({
        onError: (error: Error) => {
            showToast.error(sprintf(__("Something went wrong: %s"), error.message));
        },
    }),
    defaultOptions: {
        queries: {
            retry: 1,
            refetchOnWindowFocus: false,
            staleTime: 30_000,
        },
    },
});

const container = document.getElementById("flexacommerce-admin-root");

if (container) {
    if (window.flexaCommerce?.theme === "dark") {
        document.body.classList.add("dark");
    }
    createRoot(container).render(
        <React.StrictMode>
            <QueryClientProvider client={queryClient}>
                <App page={window.flexaCommerce?.page ?? "flexacommerce"} />
            </QueryClientProvider>
        </React.StrictMode>,
    );
}
