import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { OrderAddress, OrderDetail } from "./types";

export interface Amount {
    amount: number;
    formatted: string;
}

export type OptionValue = string | string[];

/** One product line as the editor posts it (ADR-061). */
export interface DraftLine {
    product_id: number;
    variant_id: number;
    quantity: number;
    attributes: Record<string, string>;
    options: Record<string, OptionValue>;
}

export interface Draft {
    lines: DraftLine[];
    customer_id: number;
    billing: OrderAddress;
    shipping: OrderAddress | null;
    coupons: string[];
    shipping_rate: string;
    payment_method: string;
    customer_note: string;
}

/** A stored order as the editor opens it, with names for its lines. */
export interface StoredDraft extends Draft {
    id: number;
    lines: (DraftLine & { name: string; sku: string; labels: string[] })[];
    customer: { id: number; name: string; email: string } | null;
    editable: boolean;
}

export interface QuoteLine {
    key: string;
    product_id: number;
    variant_id: number;
    name: string;
    sku: string;
    labels: string[];
    quantity: number;
    status: string;
    unit: Amount | null;
    total: Amount | null;
    discount: Amount;
}

export interface Quote {
    lines: QuoteLine[];
    coupons: { code: string; automatic: boolean; applied: boolean; amount: Amount | null; message: string }[];
    fees: { name: string; amount: Amount }[];
    needs_shipping: boolean;
    shipping_rates: { id: string; label: string; cost: Amount }[];
    shipping_rate: string;
    payment_methods: { id: string; title: string }[];
    payment_method: string;
    prices_include_tax: boolean;
    totals: Record<"subtotal" | "discount" | "fees" | "shipping" | "tax" | "total", Amount>;
    /** What stops the order, by field: lines, coupons, billing.email, billing.country, shipping.country, payment_method. */
    errors: Record<string, string>;
    notices: string[];
}

export interface AttributeChoice {
    key: string;
    name: string;
    options: { key: string; label: string }[];
}

export interface VariantChoice {
    id: number;
    /** Attribute key => value key, `*` for any value. */
    attributes: Record<string, string>;
    sku: string | null;
    in_stock: boolean;
    price: string;
}

export interface OptionChoice {
    id: number;
    label: string;
}

export interface OptionFieldDef {
    key: string;
    id: number;
    type: string;
    label: string;
    help: string;
    required: boolean;
    placeholder: string;
    min: number | null;
    max: number | null;
    step: number | null;
    choices: OptionChoice[];
}

export interface ProductForm {
    product_id: number;
    name: string;
    sku: string;
    type: string;
    purchasable: boolean;
    attributes: AttributeChoice[];
    variants: VariantChoice[];
    options: { id: number; name: string; fields: OptionFieldDef[] }[];
}

const KEY = ["orders", "editor"] as const;

/** Read only: the server prices the draft and stores nothing. */
export function useQuote(draft: Draft, enabled: boolean) {
    return useQuery({
        queryKey: [...KEY, "quote", draft],
        queryFn: () => api.post<Quote>("admin/orders/quote", { ...draft }),
        enabled,
        placeholderData: keepPreviousData,
        staleTime: 0,
    });
}

export function useStoredDraft(id: number) {
    return useQuery({
        queryKey: [...KEY, "draft", id],
        queryFn: () => api.get<StoredDraft>(`admin/orders/${id}/draft`),
        enabled: id > 0,
        staleTime: 0,
        gcTime: 0,
    });
}

export function useProductForm(productId: number) {
    return useQuery({
        queryKey: [...KEY, "product", productId],
        queryFn: () => api.get<ProductForm>("admin/orders/product-form", { product_id: productId }),
        staleTime: 60_000,
    });
}

export function useCustomerAddresses() {
    return useMutation({
        mutationFn: (customerId: number) =>
            api.get<{ billing: Partial<OrderAddress>; shipping: OrderAddress | null }>("admin/orders/customer-addresses", { customer_id: customerId }),
    });
}

/** Create or change the order; the detail cache gets the answer and the lists refetch. */
export function useSaveOrder(id: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (vars: { draft: Draft; send_details: boolean }) =>
            id > 0
                ? api.put<OrderDetail>(`admin/orders/${id}`, { ...vars.draft })
                : api.post<OrderDetail & { details_sent?: boolean }>("admin/orders", { ...vars.draft, send_details: vars.send_details }),
        onSuccess: (order) => qc.setQueryData(["orders", "detail", order.id], order),
        onSettled: () => {
            void qc.invalidateQueries({ queryKey: ["orders", "list"] });
            void qc.invalidateQueries({ queryKey: ["orders", "counts"] });
        },
    });
}

export function useUpdateAddresses(id: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (vars: { billing: OrderAddress; shipping: OrderAddress | null }) => api.put<OrderDetail>(`admin/orders/${id}/addresses`, { ...vars }),
        onSuccess: (order) => qc.setQueryData(["orders", "detail", id], order),
        onSettled: () => void qc.invalidateQueries({ queryKey: ["orders", "list"] }),
    });
}

export function useSendDetails(id: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: () => api.post<OrderDetail>(`admin/orders/${id}/details-email`),
        onSuccess: (order) => qc.setQueryData(["orders", "detail", id], order),
    });
}

export const EMPTY_ADDRESS: OrderAddress = {
    first_name: "",
    last_name: "",
    company: "",
    address_1: "",
    address_2: "",
    city: "",
    state: "",
    postcode: "",
    country: "",
    email: "",
    phone: "",
};
