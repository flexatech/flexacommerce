import { useQuery } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

/** Conditions a pricing rule or a coupon may add (ADR-041). */
/** `cart_subtotal` is for a checkout fee only (ADR-043). */
export type NumberType = "cart_quantity" | "cart_weight" | "cart_subtotal" | "lifetime_spend" | "order_count";
export type ListType = "country" | "payment_method" | "shipping_method" | "coupon" | "purchased";
export type ConditionType = NumberType | ListType;

export interface NumberCondition {
    type: NumberType;
    op: "gte" | "lte";
    /** A count, grams, or minor units of the store currency. */
    value: number;
}

export interface ListCondition {
    type: ListType;
    op: "in" | "not_in";
    /** Country codes, gateway ids, shipping methods, coupon codes, or product ids. */
    value: (string | number)[];
}

export type Condition = NumberCondition | ListCondition;

export interface ConditionBlock {
    match: "all" | "any";
    items: Condition[];
}

export const NO_CONDITIONS: ConditionBlock = { match: "all", items: [] };

export const MAX_CONDITIONS = 10;

export const NUMBER_TYPES: NumberType[] = ["cart_quantity", "cart_weight", "cart_subtotal", "lifetime_spend", "order_count"];

export const isNumber = (c: Condition): c is NumberCondition => (NUMBER_TYPES as string[]).includes(c.type);

export interface Choice {
    id: string;
    title: string;
}

interface Choices {
    countries: Choice[];
    payment_methods: Choice[];
    shipping_methods: Choice[];
}

export function useConditionChoices() {
    return useQuery({
        queryKey: ["conditions", "choices"],
        queryFn: () => api.get<Choices>("admin/conditions/choices"),
        staleTime: 60_000,
    });
}
