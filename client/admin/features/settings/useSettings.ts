import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { CurrencyPosition } from "@/shared/types/global";
import type { LogLevel } from "../status/types";

export interface Settings {
    currency: string;
    currency_position: CurrencyPosition;
    thousand_separator: string;
    decimal_separator: string;
    order_number_prefix: string;
    migration_number_prefix: string;
    hold_minutes: number;
    max_line_quantity: number;
    store_customer_ip: boolean;
    checkout_paused: boolean;
    log_level: LogLevel;
    log_retention_days: number;
    remove_data_on_uninstall: boolean;
}

export interface SettingsPayload {
    settings: Settings;
    currencies: string[];
}

const SETTINGS_KEY = ["settings"] as const;

export function useSettings() {
    return useQuery<SettingsPayload>({
        queryKey: SETTINGS_KEY,
        queryFn: () => api.get<SettingsPayload>("admin/settings"),
    });
}

/**
 * Sends only the changed fields. The server merges them over what is stored,
 * so a full payload could overwrite a change made in another tab.
 */
export function useSaveSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: Partial<Settings> & { confirm?: string }) =>
            api.patch<SettingsPayload>("admin/settings", input as Record<string, unknown>),
        onSuccess: (next) => qc.setQueryData(SETTINGS_KEY, next),
        onSettled: () => void qc.invalidateQueries({ queryKey: SETTINGS_KEY }),
    });
}
