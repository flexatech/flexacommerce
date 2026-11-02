import "@/shared/styles/nav.css";

import React from "react";
import { createRoot } from "react-dom/client";

import { Header } from "./components/Header";

// The store header alone, for core's product, category, tag and review screens (ADR-082).
const container = document.getElementById("flexacommerce-nav-root");

if (container) {
    if (window.flexaCommerceNav?.theme === "dark") {
        document.body.classList.add("dark");
    }
    createRoot(container).render(
        <React.StrictMode>
            <Header />
        </React.StrictMode>,
    );
}
