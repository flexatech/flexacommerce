import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type Section = "billing" | "shipping" | "additional";
export type Answer = "required" | "optional" | "hidden";
export type Width = "full" | "half";
export type CustomType = "text" | "textarea" | "select" | "radio" | "checkbox" | "date" | "number" | "tel" | "email";

export interface LayoutChoice {
    /** Saved on the order; stays when the label changes. */
    value: string;
    label: string;
}

/** `any` cart, a cart that `ships`, or one where nothing ships. */
export type CartShipping = "any" | "ships" | "no_shipping";

/** When a field of the merchant's shows. Empty lists mean no limit; products and categories are either-or. */
export interface When {
    countries: string[];
    shipping: CartShipping;
    products: number[];
    categories: number[];
}

export const ALWAYS: When = { countries: [], shipping: "any", products: [], categories: [] };

export const isConditional = (when?: When) => !!when && (when.countries.length > 0 || when.shipping !== "any" || when.products.length > 0 || when.categories.length > 0);

export interface LayoutEntry {
    id: string;
    /** Empty on a core field means its translated default label. */
    label: string;
    placeholder: string;
    help: string;
    width: Width;
    answer: Answer;
    /** A field the merchant added, as opposed to an address field. */
    custom: boolean;
    type?: CustomType;
    options?: LayoutChoice[];
    /** Show the answer in the order emails. */
    email?: boolean;
    when?: When;
}

export type LayoutFields = Record<Section, LayoutEntry[]>;

/** The parts of the page: the three field sections and the three the checkout draws. */
export type BlockId = Section | "shipping_method" | "summary" | "payment";
export type ContentType = "heading" | "paragraph";
export type Column = "main" | "side";

/** A section of the page, or a heading or paragraph of the merchant's (id `text_…`). */
export interface LayoutBlock {
    id: string;
    /** On a section: empty means its default title. */
    title?: string;
    type?: ContentType;
    text?: string;
}

export interface LayoutPage {
    columns: "two" | "one";
    /** Where phones show the order summary: in its place, or folded at the top. */
    summary: "end" | "top";
    /** One page, or Information, Shipping and Payment one at a time (doc 11 §12). */
    flow: "page" | "steps";
    /** Whether the order summary has a box for a coupon code (doc 11 §13). */
    coupons: "show" | "hide";
    main: LayoutBlock[];
    /** Empty with one column. */
    side: LayoutBlock[];
}

export interface Layout {
    fields: LayoutFields;
    page: LayoutPage;
}

export interface LayoutPayload extends Layout {
    default: Layout;
    /** Default labels of the core fields, by id. */
    labels: Record<string, string>;
    /** The countries a field can be shown for. */
    countries: { code: string; name: string }[];
}

export const SECTIONS: Section[] = ["billing", "shipping", "additional"];

/** Country and email are always shown and required. */
export const LOCKED = ["country", "email"];
/** Shown or hidden; whether they are required depends on the country. */
export const BY_COUNTRY = ["state", "postcode"];
/** Per section, as the server checks. */
export const MAX_CUSTOM = 30;
export const MAX_CHOICES = 30;
export const LABEL_MAX = 80;
export const MAX_CONTENT = 20;
export const HEADING_MAX = 120;
export const PARAGRAPH_MAX = 1000;
export const HELP_MAX = 200;
export const MAX_TARGETS = 100;

const KEY = ["checkout-layout"] as const;

export function useCheckoutLayout() {
    return useQuery({
        queryKey: KEY,
        queryFn: () => api.get<LayoutPayload>("admin/checkout-layout"),
    });
}

/** The server answers with the layout it stored, normalised. */
export function useSaveCheckoutLayout() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (layout: Layout) => api.put<LayoutPayload>("admin/checkout-layout", { ...layout }),
        onSuccess: (data) => qc.setQueryData(KEY, data),
    });
}

export function useResetCheckoutLayout() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: () => api.delete<LayoutPayload>("admin/checkout-layout"),
        onSuccess: (data) => qc.setQueryData(KEY, data),
    });
}

/** Keeps the unsaved layout for an hour and answers with the checkout URL that shows it. */
export function usePreviewCheckoutLayout() {
    return useMutation({
        mutationFn: (layout: Layout) => api.post<{ url: string }>("admin/checkout-layout/preview", { ...layout }),
    });
}
