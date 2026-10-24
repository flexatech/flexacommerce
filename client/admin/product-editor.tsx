import "@/shared/styles/product-editor.css";

import React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { createRoot } from "react-dom/client";

import { Toaster } from "@/shared/components/ui/sonner";
import { ProductEditor } from "./features/product/ProductEditor";

// Module scope on purpose: one cache for the page.
const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: 1,
            refetchOnWindowFocus: false,
        },
    },
});

function mount(): void {
    const container = document.getElementById("flexacommerce-product-root");
    const productId = Number(container?.dataset.productId ?? window.flexaCommerce?.productId ?? 0);
    if (!container || productId <= 0) {
        return;
    }
    createRoot(container).render(
        <React.StrictMode>
            <QueryClientProvider client={queryClient}>
                <Toaster />
                <ProductEditor productId={productId} />
            </QueryClientProvider>
        </React.StrictMode>,
    );
}

// Block editor metaboxes are in the DOM by DOMContentLoaded, classic ones even earlier.
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mount);
} else {
    mount();
}
