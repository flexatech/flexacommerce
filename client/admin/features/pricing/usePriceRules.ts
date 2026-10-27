import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { ConditionBlock } from "../conditions/useConditions";

/**
 * `bulk` prices every unit by the tier reached; `graduated` prices each unit by the tier its position reaches (ADR-042).
 * `fee` adds a charge to the cart total instead of changing a price (ADR-043).
 */
export type RuleType = "adjust" | "bulk" | "graduated" | "fee";
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
    /** A percent fee's largest amount, in minor units; 0 for no limit. */
    cap: number;
    /** With `bulk` and `graduated`, sorted by `min`. */
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
    /** Extra conditions (ADR-041); none means the rule always applies. */
    conditions: ConditionBlock;
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
