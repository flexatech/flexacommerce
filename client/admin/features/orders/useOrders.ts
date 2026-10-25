import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { OrderCounts, OrderDetail, OrderListParams, OrderStatus, OrderSummary, RefundLineInput } from "./types";

export const ORDERS_PER_PAGE = 20;

const ORDERS_KEY = ["orders"] as const;

export function useOrderList(params: OrderListParams) {
    return useQuery({
        queryKey: [...ORDERS_KEY, "list", params],
        queryFn: () =>
            api.list<OrderSummary>("admin/orders", {
                status: params.status,
                needs_attention: params.needs_attention ? 1 : undefined,
                search: params.search,
                customer_id: params.customer_id > 0 ? params.customer_id : undefined,
                page: params.page,
                per_page: ORDERS_PER_PAGE,
            }),
        placeholderData: keepPreviousData,
    });
}

export function useOrderCounts() {
    return useQuery({
        queryKey: [...ORDERS_KEY, "counts"],
        queryFn: () => api.get<OrderCounts>("admin/orders/counts"),
    });
}

export function useOrder(id: number) {
    return useQuery({
        queryKey: [...ORDERS_KEY, "detail", id],
        queryFn: () => api.get<OrderDetail>(`admin/orders/${id}`),
    });
}

/**
 * Every action returns the order as the action left it, so the detail cache
 * is replaced in place; lists and counts are refetched because the status
 * or the totals may have moved.
 */
function useOrderAction<V>(id: number, run: (vars: V) => Promise<OrderDetail>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: run,
        onSuccess: (order) => qc.setQueryData([...ORDERS_KEY, "detail", id], order),
        onSettled: () => {
            void qc.invalidateQueries({ queryKey: [...ORDERS_KEY, "list"] });
            void qc.invalidateQueries({ queryKey: [...ORDERS_KEY, "counts"] });
        },
    });
}

export function useTransition(id: number) {
    return useOrderAction(id, (vars: { status: OrderStatus; note: string; notify_customer?: boolean }) =>
        api.post<OrderDetail>(`admin/orders/${id}/transitions`, vars),
    );
}

export function useAddNote(id: number) {
    return useOrderAction(id, (vars: { note: string; customer_visible: boolean }) =>
        api.post<OrderDetail>(`admin/orders/${id}/notes`, vars),
    );
}

export function useRecordPayment(id: number) {
    return useOrderAction(id, (vars: { amount: number; note: string }) =>
        api.post<OrderDetail>(`admin/orders/${id}/payments`, vars),
    );
}

export function useRefund(id: number) {
    return useOrderAction(
        id,
        (vars: { amount: number; lines: RefundLineInput[]; reason: string; via_gateway: boolean }) =>
            api.post<OrderDetail>(`admin/orders/${id}/refunds`, { ...vars, lines: vars.lines.map((l) => ({ ...l })) }),
    );
}

export function useClearAttention(id: number) {
    return useOrderAction(id, () => api.delete<OrderDetail>(`admin/orders/${id}/attention`));
}
