import { create } from "zustand";
import { persist } from "zustand/middleware";

export type StatusTab = "overview" | "logs";

interface UiState {
    statusTab: StatusTab;
    setStatusTab: (tab: StatusTab) => void;
}

// UI state only. Server data lives in TanStack Query.
export const useUiStore = create<UiState>()(
    persist(
        (set) => ({
            statusTab: "overview",
            setStatusTab: (statusTab) => set({ statusTab }),
        }),
        { name: "flexacommerce:ui" },
    ),
);
