import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type SubscriptionStatus = "pending" | "active" | "on-hold" | "pending-cancel" | "cancelled" | "expired";

export type SubscriptionAction = "cancel" | "cancel_end" | "reactivate" | "hold" | "resume" | "renew";

export interface SubscriptionPlan {
    period: "day" | "week" | "month" | "year";
    interval: number;
    /** Payments in all; 0 until cancelled. */
    length: number;
    trial_days: number;
    /** Per unit, in minor units. */
    signup_fee: number;
}

export interface SubscriptionItem {
    id: number;
    status: SubscriptionStatus;
    status_label: string;
    title: string;
    customer_id: number;
    customer_email: string;
    customer_name: string;
    currency: string;
    plan: SubscriptionPlan;
    /** Payments made so far, the first order included. */
    billed: number;
    /** What each renewal charges, in minor units. */
    total: number;
    /** "$10.00 / month" */
    price: string;
    parent_order_id: number | null;
    payment_method: string;
    payment_method_title: string;
    start_at: string | null;
    trial_end_at: string | null;
    next_payment_at: string | null;
    end_at: string | null;
    cancelled_at: string | null;
    created_at: string | null;
}

export interface SubscriptionAddress {
    first_name?: string;
    last_name?: string;
    company?: string;
    address_1?: string;
    address_2?: string;
    city?: string;
    state?: string;
    postcode?: string;
    country?: string;
    email?: string;
    phone?: string;
}

export interface SubscriptionOrder {
    id: number;
    number: string;
    relation: "parent" | "renewal";
    status: string;
    status_label: string;
    total: number;
    created_at: string;
}

export interface SubscriptionEvent {
    type: string;
    message: string;
    order_id: number | null;
    actor_type: string;
    actor_id: number | null;
    /** The display name of the person; empty for the store or the customer without an account. */
    actor: string;
    created_at: string;
}

export interface SubscriptionDetail extends SubscriptionItem {
    items: { name: string; quantity: number; total: number; product_id: number }[];
    subtotal: number;
    shipping_total: number;
    tax_total: number;
    billing: SubscriptionAddress | null;
    shipping: SubscriptionAddress | null;
    orders: SubscriptionOrder[];
    events: SubscriptionEvent[];
    /** The renewal order still waiting for payment, if any. */
    unpaid_order_id: number | null;
    /** Store-local YYYY-MM-DD; empty when no payment is due. */
    next_payment_on: string;
}

export interface SubscriptionList {
    items: SubscriptionItem[];
    total: number;
    pages: number;
    counts: Partial<Record<SubscriptionStatus, number>>;
}

export const SUBSCRIPTIONS_PER_PAGE = 20;

const KEY = ["subscriptions"] as const;

export function useSubscriptionList(search: string, status: SubscriptionStatus | "", page: number) {
    return useQuery({
        queryKey: [...KEY, "list", search, status, page],
        queryFn: () => api.get<SubscriptionList>("admin/subscriptions", { search, status, page, per_page: SUBSCRIPTIONS_PER_PAGE }),
        placeholderData: keepPreviousData,
    });
}

export function useSubscription(id: number | null) {
    return useQuery({
        queryKey: [...KEY, "one", id],
        queryFn: () => api.get<SubscriptionDetail>(`admin/subscriptions/${id}`),
        enabled: id !== null,
    });
}

function useSettle() {
    const qc = useQueryClient();
    return (row: SubscriptionDetail) => {
        qc.setQueryData([...KEY, "one", row.id], row);
        void qc.invalidateQueries({ queryKey: [...KEY, "list"] });
    };
}

export function useSubscriptionAction(id: number) {
    const settle = useSettle();
    return useMutation({
        mutationFn: (action: SubscriptionAction) => api.post<SubscriptionDetail>(`admin/subscriptions/${id}/actions`, { action }),
        onSuccess: settle,
    });
}

export function useReschedule(id: number) {
    const settle = useSettle();
    return useMutation({
        mutationFn: (day: string) => api.patch<SubscriptionDetail>(`admin/subscriptions/${id}`, { next_payment_on: day }),
        onSuccess: settle,
    });
}
