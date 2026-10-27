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
}

export type LayoutFields = Record<Section, LayoutEntry[]>;

export interface LayoutPayload {
    fields: LayoutFields;
    default: LayoutFields;
    /** Default labels of the core fields, by id. */
    labels: Record<string, string>;
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
export const HELP_MAX = 200;

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
        mutationFn: (fields: LayoutFields) => api.put<LayoutPayload>("admin/checkout-layout", { fields }),
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
