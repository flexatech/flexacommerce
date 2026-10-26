import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type RuleType = "adjust" | "bulk";
/** Percent off (hundredths), an amount off each unit, or a set unit price (minor units). */
export type RuleMode = "percent" | "fixed" | "price";
export type RuleCount = "product" | "variant";
export type RuleScope = "all" | "products" | "categories";
export type RuleCustomers = "all" | "logged_in" | "roles";
export type RuleSaleItems = "apply" | "skip";

export interface Tier {
    min: number;
    mode: RuleMode;
    amount: number;
}

export interface PriceRule {
    id: number;
    name: string;
    enabled: boolean;
    type: RuleType;
    mode: RuleMode;
    amount: number;
    /** With `bulk`, sorted by `min`. */
    tiers: Tier[];
    count: RuleCount;
    applies_to: RuleScope;
    product_ids: number[];
    category_ids: number[];
    exclude_product_ids: number[];
    customers: RuleCustomers;
    roles: string[];
    sale_items: RuleSaleItems;
    /** UTC, ISO 8601 with Z. */
    starts_at: string | null;
    ends_at: string | null;
}

export type PriceRuleInput = Omit<PriceRule, "id">;

export interface Role {
    value: string;
    label: string;
}

interface Listing {
    items: PriceRule[];
    roles: Role[];
}

const KEY = ["pricing-rules"] as const;

export function usePriceRules() {
    return useQuery({
        queryKey: KEY,
        queryFn: () => api.get<Listing>("admin/pricing-rules"),
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

export function useSavePriceRule() {
    return useListMutation(({ id, input }: { id: number; input: Partial<PriceRuleInput> }) =>
        id > 0 ? api.patch<Listing>(`admin/pricing-rules/${id}`, { ...input }) : api.post<Listing>("admin/pricing-rules", { ...input }),
    );
}

export function useDeletePriceRule() {
    return useListMutation((id: number) => api.delete<Listing>(`admin/pricing-rules/${id}`));
}
