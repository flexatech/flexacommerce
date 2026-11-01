import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api, getPluginGlobal } from "@/shared/lib/api";

export interface InvoiceSettings {
    prefix: string;
    suffix: string;
    padding: number;
    reset: "never" | "yearly";
    attach_to: string[];
    customer_access: "paid" | "invoiced" | "never";
    shop_name: string;
    shop_address: string;
    shop_details: string;
    logo_id: number;
    footer: string;
    paper: "" | "a4" | "letter";
    show_sku: boolean;
    credit_prefix: string;
    credit_suffix: string;
    /** Attach the credit note to the Refunded order email (ADR-064). */
    credit_attach: boolean;
}

export type InvoiceSettingsChanges = Partial<InvoiceSettings> & { next_number?: number; next_credit?: number };

export interface InvoiceSettingsResponse {
    settings: InvoiceSettings;
    /** The counter value the next invoice gets. */
    next_number: number;
    next_formatted: string;
    /** The counter value the next credit note gets. */
    next_credit: number;
    next_credit_formatted: string;
    /** The paper in use, with "" (automatic) resolved. */
    paper: "a4" | "letter";
    emails: { id: string; title: string; to_customer: boolean }[];
    logo_url: string;
}

/** A succeeded refund and its credit note, which is made the first time its PDF is. */
export interface OrderCreditNote {
    refund_id: number;
    /** The refunded amount, formatted. */
    amount: string;
    refunded_at: string | null;
    number: string | null;
    date: string | null;
    customer_can: boolean;
    customer_url: string;
}

export interface OrderInvoice {
    invoice: { number: string; date: string | null } | null;
    customer_can: boolean;
    customer_url: string;
    credit_notes: OrderCreditNote[];
}

export type InvoiceDocument = "invoice" | "packing_slip";

const SETTINGS_KEY = ["invoices", "settings"] as const;

export function useInvoiceSettings() {
    return useQuery({
        queryKey: SETTINGS_KEY,
        queryFn: () => api.get<InvoiceSettingsResponse>("admin/invoices/settings"),
    });
}

/** Sends only the changed values; the server merges them over what is stored. */
export function useSaveInvoiceSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (changes: InvoiceSettingsChanges) =>
            api.patch<InvoiceSettingsResponse>("admin/invoices/settings", changes),
        onSuccess: (data) => qc.setQueryData(SETTINGS_KEY, data),
    });
}

export const orderInvoiceKey = (orderId: number) => ["orders", orderId, "invoice"] as const;

/** Refetched on focus: the PDF opens in another tab and numbers the invoice there. */
export function useOrderInvoice(orderId: number) {
    return useQuery({
        queryKey: orderInvoiceKey(orderId),
        queryFn: () => api.get<OrderInvoice>(`admin/orders/${orderId}/invoice`),
        refetchOnWindowFocus: true,
    });
}

export function useCreateInvoice(orderId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: () => api.post<OrderInvoice>(`admin/orders/${orderId}/invoice`, {}),
        onSuccess: (data) => qc.setQueryData(orderInvoiceKey(orderId), data),
    });
}

/** The admin-post URL that streams the PDF of one or more orders. */
export function invoicePdfUrl(orderIds: number[], document: InvoiceDocument): string {
    const { adminUrl, restNonce } = getPluginGlobal();
    const query = new URLSearchParams({ action: "fxc_invoice", orders: orderIds.join(","), document, _wpnonce: restNonce });
    return `${adminUrl.replace(/admin\.php$/, "admin-post.php")}?${query.toString()}`;
}

/** The admin-post URL that streams the credit note of one refund. */
export function creditNotePdfUrl(orderId: number, refundId: number): string {
    const { adminUrl, restNonce } = getPluginGlobal();
    const query = new URLSearchParams({
        action: "fxc_invoice",
        orders: String(orderId),
        document: "credit_note",
        refund: String(refundId),
        _wpnonce: restNonce,
    });
    return `${adminUrl.replace(/admin\.php$/, "admin-post.php")}?${query.toString()}`;
}
