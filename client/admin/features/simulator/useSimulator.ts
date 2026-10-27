import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { Condition } from "../conditions/useConditions";

export interface Amount {
    amount: number;
    formatted: string;
}

export type RuleStatus =
    | "applied"
    | "beaten"
    | "no_change"
    | "no_tier"
    | "off"
    | "scheduled"
    | "ended"
    | "customer"
    | "not_targeted"
    | "on_sale"
    | "unmet"
    | "waiting";

export interface RuleTrace {
    id: number;
    name: string;
    type: "adjust" | "bulk" | "graduated" | "fee";
    status: RuleStatus;
    /** The line total the rule gives, or the fee it charges. */
    amount: Amount | null;
    /** The condition behind `unmet` or `waiting`. */
    condition: Condition | null;
}

export interface SimLine {
    key: string;
    product_id: number;
    variant_id: number;
    name: string;
    quantity: number;
    status: string;
    regular: Amount | null;
    /** The unit price before pricing rules: the sale price when there is one. */
    before: Amount | null;
    unit: Amount | null;
    total: Amount | null;
    /** What coupons take off this line. */
    discount: Amount;
    rules: RuleTrace[];
}

export interface SimCoupon {
    code: string;
    automatic: boolean;
    status: "applied" | "rejected" | "waiting" | "skipped";
    amount: Amount | null;
    message: string;
}

export interface SimResult {
    customer_id: number;
    lines: SimLine[];
    fees: RuleTrace[];
    coupons: SimCoupon[];
    next_discount: { label: string; message: string } | null;
    shipping_rates: { id: string; label: string; cost: Amount }[];
    shipping_rate: string;
    needs_shipping: boolean;
    payment_method: string;
    prices_include_tax: boolean;
    totals: Record<"subtotal" | "discount" | "fees" | "shipping" | "tax" | "total", Amount>;
    problems: string[];
    notices: string[];
}

export interface SimInput {
    lines: { type: "product" | "variant"; id: number; quantity: number }[];
    customer_id: number;
    country: string;
    state: string;
    postcode: string;
    shipping_rate: string;
    payment_method: string;
    coupons: string[];
}

export const MAX_LINES = 20;

/** Read only: the server prices the cart and stores nothing, so the answer is cached like a read. */
export function useSimulation(input: SimInput) {
    return useQuery({
        queryKey: ["simulator", input],
        queryFn: () => api.post<SimResult>("admin/simulator", { ...input }),
        enabled: input.lines.length > 0,
        placeholderData: keepPreviousData,
        staleTime: 0,
    });
}

export interface Account {
    id: number;
    name: string;
    email: string;
    /** Role names, translated. */
    roles: string[];
}

/** Any account, ordered before or not: a role is what a rule names. */
export function useCustomerSearch(search: string, enabled: boolean) {
    return useQuery({
        queryKey: ["simulator", "customers", search],
        queryFn: () => api.get<Account[]>("admin/simulator/customers", { search }),
        enabled,
        placeholderData: keepPreviousData,
        staleTime: 30_000,
    });
}
