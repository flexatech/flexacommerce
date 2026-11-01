import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { GiftCardActivityRow } from "../giftcards/useGiftCards";

export interface Customer {
    customer_id: number;
    registered: boolean;
    email: string;
    name: string;
    orders: number;
    /** Paid less refunded, in the store currency's minor unit. */
    spent: number;
    currency: string;
    first_order: string;
    last_order: string;
    edit_url: string;
    /** Accounts only; guests are never exempt. */
    tax_exempt: boolean;
    /** The current user may change `tax_exempt` (an account and the store manager capability). */
    can_exempt: boolean;
    /** Store credit balance in minor units (ADR-072); null for guests. */
    credit: number | null;
}

export interface CustomerCredit {
    customer_id: number;
    balance: number;
    currency: string;
    activity: GiftCardActivityRow[];
}

export type CustomerType = "" | "registered" | "guest";
export type CustomerOrderBy = "last_order" | "orders" | "spent";

export interface CustomerListParams {
    type: CustomerType;
    search: string;
    orderby: CustomerOrderBy;
    page: number;
}

export const CUSTOMERS_PER_PAGE = 20;

export function useCustomers(params: CustomerListParams) {
    return useQuery({
        queryKey: ["customers", "list", params],
        queryFn: () =>
            api.list<Customer>("admin/customers", {
                type: params.type === "" ? undefined : params.type,
                search: params.search,
                orderby: params.orderby,
                page: params.page,
                per_page: CUSTOMERS_PER_PAGE,
            }),
        placeholderData: keepPreviousData,
    });
}

export function useSetTaxExempt() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, exempt }: { id: number; exempt: boolean }) =>
            api.patch<{ customer_id: number; tax_exempt: boolean }>(`admin/customers/${id}`, { tax_exempt: exempt }),
        onSettled: () => qc.invalidateQueries({ queryKey: ["customers"] }),
    });
}

export function useCustomerCredit(id: number | null) {
    return useQuery({
        queryKey: ["customers", "credit", id],
        queryFn: () => api.get<CustomerCredit>(`admin/customers/${id}/credit`),
        enabled: id !== null,
    });
}

export function useAdjustCredit(id: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: { amount: number; note: string }) => api.post<CustomerCredit>(`admin/customers/${id}/credit`, input),
        onSuccess: (data) => {
            qc.setQueryData(["customers", "credit", id], data);
            void qc.invalidateQueries({ queryKey: ["customers", "list"] });
        },
    });
}
