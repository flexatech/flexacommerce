import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import { api } from "@/shared/lib/api";
import { download } from "../productcsv/csv";
import type {
    OrderCounts,
    OrderDetail,
    OrderExportLayout,
    OrderExportPage,
    OrderListParams,
    OrderStatus,
    OrderSummary,
    RefundLineInput,
    ShipmentCarriers,
    ShipmentInput,
} from "./types";

export const ORDERS_PER_PAGE = 20;

const ORDERS_KEY = ["orders"] as const;

/** The list filters as request parameters; the export sends the same ones. */
function filters(params: OrderListParams) {
    return {
        status: params.status,
        needs_attention: params.needs_attention ? 1 : undefined,
        search: params.search,
        customer_id: params.customer_id > 0 ? params.customer_id : undefined,
        date_from: params.date_from || undefined,
        date_to: params.date_to || undefined,
    };
}

export function useOrderList(params: OrderListParams) {
    return useQuery({
        queryKey: [...ORDERS_KEY, "list", params],
        queryFn: () => api.list<OrderSummary>("admin/orders", { ...filters(params), page: params.page, per_page: ORDERS_PER_PAGE }),
        placeholderData: keepPreviousData,
    });
}

/** Fetches the CSV export a page at a time (ADR-070) and saves the file. */
export function useOrderExport() {
    const [progress, setProgress] = useState<{ done: number; total: number } | null>(null);

    const mutation = useMutation({
        mutationFn: async ({ params, layout }: { params: OrderListParams; layout: OrderExportLayout }) => {
            const query = { ...filters(params), layout };
            const first = await api.get<OrderExportPage>("admin/orders/export", query);
            const total = first.total ?? 0;
            const parts = ["\uFEFF", first.header ?? "", first.lines];
            let rows = first.rows;
            let done = first.orders;
            let page = first;
            setProgress({ done, total });
            while (!page.done) {
                page = await api.get<OrderExportPage>("admin/orders/export", { ...query, after: page.last, until: first.until });
                parts.push(page.lines);
                rows += page.rows;
                done += page.orders;
                setProgress({ done: Math.min(done, total), total });
            }
            download(first.name ?? "orders.csv", parts);
            return { rows, orders: done };
        },
        onSettled: () => setProgress(null),
    });

    return { ...mutation, progress };
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
            // A payment or refund changes the invoice card: customer access and credit notes.
            void qc.invalidateQueries({ queryKey: [...ORDERS_KEY, id, "invoice"] });
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
        (vars: { amount: number; lines: RefundLineInput[]; reason: string; via_gateway: boolean; to_credit?: boolean }) =>
            api.post<OrderDetail>(`admin/orders/${id}/refunds`, { ...vars, lines: vars.lines.map((l) => ({ ...l })) }),
    );
}

export function useClearAttention(id: number) {
    return useOrderAction(id, () => api.delete<OrderDetail>(`admin/orders/${id}/attention`));
}

export function useGrantDownloads(id: number) {
    return useOrderAction(id, () => api.post<OrderDetail>(`admin/orders/${id}/downloads`));
}

export function useUpdateDownload(id: number) {
    return useOrderAction(id, (vars: { permission: number; downloads_remaining: number | null; access_expires: string | null }) =>
        api.patch<OrderDetail>(`admin/orders/${id}/downloads/${vars.permission}`, {
            downloads_remaining: vars.downloads_remaining,
            access_expires: vars.access_expires,
        }),
    );
}

export function useRevokeDownload(id: number) {
    return useOrderAction(id, (permission: number) => api.delete<OrderDetail>(`admin/orders/${id}/downloads/${permission}`));
}

export function useShipmentCarriers(enabled: boolean) {
    return useQuery({
        queryKey: ["shipment-carriers"],
        queryFn: () => api.get<ShipmentCarriers>("admin/shipment-carriers"),
        enabled,
        staleTime: 5 * 60_000,
    });
}

export function useAddShipment(id: number) {
    return useOrderAction(id, (vars: ShipmentInput) => api.post<OrderDetail>(`admin/orders/${id}/shipments`, { ...vars }));
}

export function useDeleteShipment(id: number) {
    return useOrderAction(id, (shipment: number) => api.delete<OrderDetail>(`admin/orders/${id}/shipments/${shipment}`));
}
