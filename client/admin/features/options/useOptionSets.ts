import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type FieldType = "text" | "textarea" | "number" | "date" | "color" | "select" | "radio" | "checkboxes" | "swatch" | "button" | "heading";
/** An amount in minor units, a percent of the unit price in hundredths, or a formula (ADR-039). */
export type PriceMode = "none" | "fixed" | "percent" | "once" | "formula";
export type SetScope = "all" | "products" | "categories";
export type LogicOp = "is" | "is_not" | "empty" | "not_empty";

export interface Choice {
    id: number;
    label: string;
    price_mode: PriceMode;
    price: number;
    default: boolean;
    /** `#rrggbb` for a swatch, empty for the other types. */
    color: string;
    /** With the formula price mode, in the main currency unit per item. */
    formula: string;
}

export interface LogicRule {
    /** The id of a field above, in the same set. */
    field: number;
    op: LogicOp;
    /** A choice id with `is` and `is_not`. */
    value: string;
}

export interface Logic {
    action: "show" | "hide";
    match: "any" | "all";
    rules: LogicRule[];
}

export interface OptionField {
    id: number;
    type: FieldType;
    label: string;
    help: string;
    required: boolean;
    placeholder: string;
    max_length: number;
    min: number | null;
    max: number | null;
    step: number | null;
    price_mode: PriceMode;
    price: number;
    choices: Choice[];
    logic: Logic | null;
    /** With the formula price mode, in the main currency unit per item. */
    formula: string;
}

export interface OptionSet {
    id: number;
    name: string;
    enabled: boolean;
    applies_to: SetScope;
    product_ids: number[];
    category_ids: number[];
    exclude_product_ids: number[];
    fields: OptionField[];
}

export type OptionSetInput = Omit<OptionSet, "id">;

interface Listing {
    items: OptionSet[];
}

const KEY = ["product-options"] as const;

export const CHOICE_TYPES: FieldType[] = ["select", "radio", "checkboxes", "swatch", "button"];

export function useOptionSets() {
    return useQuery({
        queryKey: KEY,
        queryFn: () => api.get<Listing>("admin/product-options"),
    });
}

/** Every call answers with the whole list; it replaces the cached one. */
function useListMutation<T>(call: (arg: T) => Promise<Listing>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: call,
        onSuccess: (data) => qc.setQueryData(KEY, data),
        onError: () => qc.invalidateQueries({ queryKey: KEY }),
    });
}

export function useSaveOptionSet() {
    return useListMutation(({ id, input }: { id: number; input: Partial<OptionSetInput> }) =>
        id > 0 ? api.patch<Listing>(`admin/product-options/${id}`, { ...input }) : api.post<Listing>("admin/product-options", { ...input }),
    );
}

export function useDeleteOptionSet() {
    return useListMutation((id: number) => api.delete<Listing>(`admin/product-options/${id}`));
}

export function useReorderOptionSets() {
    return useListMutation((ids: number[]) => api.post<Listing>("admin/product-options/reorder", { ids }));
}
