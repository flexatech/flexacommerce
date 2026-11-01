import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type StockAlertStatus = "waiting" | "sent" | "cancelled";

export interface StockAlertItem {
    id: number;
    product_id: number;
    /** 0 when the shopper asked for any variation. */
    variant_id: number;
    /** The product name, with the variation's values. */
    product: string;
    email: string;
    /** The account's display name; empty for a guest. */
    customer: string;
    user_id: number | null;
    status: StockAlertStatus;
    created_at: string | null;
    sent_at: string | null;
}

export interface WantedProduct {
    product_id: number;
    name: string;
    waiting: number;
    in_stock: boolean;
}

export interface StockAlertList {
    items: StockAlertItem[];
    total: number;
    pages: number;
    wanted: WantedProduct[];
    stats: { days: number; waiting: number; sent: number };
    enabled: boolean;
}

export interface StockAlertSettings {
    enabled: boolean;
    guests: boolean;
    subject: string;
    heading: string;
    message: string;
}

export interface StockAlertSettingsResponse {
    settings: StockAlertSettings;
    /** The texts the email sends when the own ones are empty. */
    defaults: { subject: string; heading: string; message: string };
    email: string;
}

export const STOCK_ALERTS_PER_PAGE = 20;

export function useStockAlertList(status: StockAlertStatus | "", productId: number, page: number) {
    return useQuery({
        queryKey: ["stock-alerts", "list", status, productId, page],
        queryFn: () =>
            api.get<StockAlertList>("admin/stock-alerts", { status, product_id: productId, page, per_page: STOCK_ALERTS_PER_PAGE }),
        placeholderData: keepPreviousData,
    });
}

export function useDeleteStockAlert() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => api.delete<{ deleted: boolean }>(`admin/stock-alerts/${id}`),
        onSettled: () => qc.invalidateQueries({ queryKey: ["stock-alerts", "list"] }),
    });
}

const SETTINGS_KEY = ["stock-alerts", "settings"] as const;

export function useStockAlertSettings() {
    return useQuery({
        queryKey: SETTINGS_KEY,
        queryFn: () => api.get<StockAlertSettingsResponse>("admin/stock-alerts/settings"),
    });
}

/** Sends only the changed values; the server merges them over the stored ones. */
export function useSaveStockAlertSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (changes: Partial<StockAlertSettings>) => api.patch<StockAlertSettingsResponse>("admin/stock-alerts/settings", changes),
        onSuccess: (data) => {
            qc.setQueryData(SETTINGS_KEY, data);
            void qc.invalidateQueries({ queryKey: ["stock-alerts", "list"] });
        },
    });
}

export function useTestStockAlert() {
    return useMutation({
        mutationFn: (input: { to: string }) => api.post<{ sent: string }>("admin/stock-alerts/test", input),
    });
}
