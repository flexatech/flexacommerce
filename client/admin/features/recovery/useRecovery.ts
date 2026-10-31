import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type RecoveryStatus = "open" | "recovered" | "lost";

export interface RecoveryMoney {
    amount: number;
    currency: string;
    formatted: string;
}

export interface RecoveryItem {
    id: number;
    email: string;
    /** The account's display name; empty for a guest. */
    customer: string;
    user_id: number | null;
    status: RecoveryStatus;
    total: RecoveryMoney;
    item_count: number;
    items: { name: string; quantity: number }[];
    emails_sent: number;
    reminders_done: boolean;
    next_send_at: string | null;
    last_activity_at: string | null;
    clicked_at: string | null;
    coupon_code: string;
    order_id: number | null;
    order_number: string | null;
    recovered_at: string | null;
    unsubscribed: boolean;
}

export interface RecoveryList {
    items: RecoveryItem[];
    total: number;
    pages: number;
    stats: {
        days: number;
        open: number;
        recovered: number;
        lost: number;
        /** Recovered out of settled carts, in percent; null before any settled. */
        rate: number | null;
        revenue: RecoveryMoney;
    };
    enabled: boolean;
}

export type CouponType = "" | "percent" | "fixed";

export interface Reminder {
    enabled: boolean;
    delay_hours: number;
    subject: string;
    heading: string;
    message: string;
    coupon_type: CouponType;
    /** Whole percent, or an amount in the minor unit. */
    coupon_amount: number;
    coupon_days: number;
}

export interface RecoverySettings {
    enabled: boolean;
    abandon_minutes: number;
    capture_guests: boolean;
    reminders: Reminder[];
}

export interface RecoverySettingsResponse {
    settings: RecoverySettings;
    /** The texts each reminder sends when its own are empty. */
    defaults: { subject: string; heading: string; message: string }[];
    currency: string;
    email: string;
}

export const RECOVERY_PER_PAGE = 20;

export function useRecoveryList(status: RecoveryStatus | "", page: number) {
    return useQuery({
        queryKey: ["recovery", "list", status, page],
        queryFn: () => api.get<RecoveryList>("admin/recovery", { status, page, per_page: RECOVERY_PER_PAGE }),
        placeholderData: keepPreviousData,
    });
}

export function useDeleteRecovery() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => api.delete<{ deleted: boolean }>(`admin/recovery/${id}`),
        onSettled: () => qc.invalidateQueries({ queryKey: ["recovery", "list"] }),
    });
}

const SETTINGS_KEY = ["recovery", "settings"] as const;

export function useRecoverySettings() {
    return useQuery({
        queryKey: SETTINGS_KEY,
        queryFn: () => api.get<RecoverySettingsResponse>("admin/recovery/settings"),
    });
}

/** Sends only the changed values; reminders go by position and the server merges them. */
export function useSaveRecoverySettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (changes: Partial<Omit<RecoverySettings, "reminders">> & { reminders?: Partial<Reminder>[] }) =>
            api.patch<RecoverySettingsResponse>("admin/recovery/settings", changes),
        onSuccess: (data) => {
            qc.setQueryData(SETTINGS_KEY, data);
            void qc.invalidateQueries({ queryKey: ["recovery", "list"] });
        },
    });
}

export function useTestRecovery() {
    return useMutation({
        mutationFn: (input: { reminder: number; to: string }) => api.post<{ sent: string }>("admin/recovery/test", input),
    });
}
