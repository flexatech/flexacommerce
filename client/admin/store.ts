import { create } from "zustand";
import { persist } from "zustand/middleware";

export type StatusTab = "overview" | "logs";
export type MigrationTab = "jobs" | "cutover";

interface UiState {
    statusTab: StatusTab;
    setStatusTab: (tab: StatusTab) => void;
    migrationTab: MigrationTab;
    setMigrationTab: (tab: MigrationTab) => void;
}

// UI state only. Server data lives in TanStack Query.
export const useUiStore = create<UiState>()(
    persist(
        (set) => ({
            statusTab: "overview",
            setStatusTab: (statusTab) => set({ statusTab }),
            migrationTab: "jobs",
            setMigrationTab: (migrationTab) => set({ migrationTab }),
        }),
        { name: "flexacommerce:ui" },
    ),
);
