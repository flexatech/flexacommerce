import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type CouponType = "percent" | "fixed_cart" | "fixed_product" | "buy_x_get_y";

/** Types whose amount is in hundredths of a percent. */
export const isPercentType = (type: CouponType): boolean => type === "percent" || type === "buy_x_get_y";
export type CouponStatus = "active" | "disabled";

export interface Coupon {
    id: number;
    code: string;
    type: CouponType;
    /** Minor units, or hundredths of a percent for `percent` and `buy_x_get_y` (1000 = 10 %). */
    amount: number;
    status: CouponStatus;
    description: string;
    /** UTC, ISO 8601 with Z. */
    starts_at: string | null;
    expires_at: string | null;
    min_subtotal: number | null;
    max_subtotal: number | null;
    /** 0 is no limit. */
    usage_limit: number;
    usage_count: number;
    per_customer_limit: number;
    /** Units a percent or per-product coupon discounts at most, the dearest first. 0 is every unit. */
    item_limit: number;
    individual_use: boolean;
    exclude_sale_items: boolean;
    /** Turns on the free shipping methods that ask for a coupon. */
    free_shipping: boolean;
    /** Applies by itself to every cart it fits; `code` is then the name shoppers see. */
    automatic: boolean;
    /** Product post ids; each covers every variation of the product. */
    product_ids: number[];
    exclude_product_ids: number[];
    /** Single variations (variant ids). */
    variant_ids: number[];
    exclude_variant_ids: number[];
    category_ids: number[];
    exclude_category_ids: number[];
    emails: string[];
    /** Buy X get Y: units bought and units discounted per set; 0 for other types. */
    buy_qty: number;
    get_qty: number;
    /** Sets one order gets at most. 0 is no limit. */
    repeat_limit: number;
    /** What a set discounts. Empty: the same items that count as bought. */
    get_product_ids: number[];
    get_variant_ids: number[];
    get_category_ids: number[];
    created_at: string | null;
    updated_at: string | null;
    currency: string;
}

export type CouponInput = Omit<Coupon, "id" | "usage_count" | "created_at" | "updated_at" | "currency">;

export interface CouponListParams {
    status: "" | CouponStatus;
    search: string;
    page: number;
}

export const COUPONS_PER_PAGE = 20;

export function useCoupons(params: CouponListParams) {
    return useQuery({
        queryKey: ["coupons", "list", params],
        queryFn: () =>
            api.list<Coupon>("admin/coupons", {
                status: params.status === "" ? undefined : params.status,
                search: params.search,
                page: params.page,
                per_page: COUPONS_PER_PAGE,
            }),
        placeholderData: keepPreviousData,
    });
}

export function useSaveCoupon() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, input }: { id: number; input: Partial<CouponInput> }) =>
            id > 0 ? api.patch<Coupon>(`admin/coupons/${id}`, { ...input }) : api.post<Coupon>("admin/coupons", { ...input }),
        onSettled: () => qc.invalidateQueries({ queryKey: ["coupons"] }),
    });
}

export function useDeleteCoupon() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => api.delete<{ deleted: boolean }>(`admin/coupons/${id}`),
        onSettled: () => qc.invalidateQueries({ queryKey: ["coupons"] }),
    });
}

export type TargetType = "product" | "variant" | "category";

/** A row of the product and category pickers. */
export interface Target {
    type: TargetType;
    id: number;
    label: string;
    sku: string;
}

export type TargetKind = "product" | "category";

/** Products (each followed by its variations) or categories matching the text, by name or SKU. */
export function useTargetSearch(kind: TargetKind, search: string, enabled: boolean) {
    return useQuery({
        queryKey: ["coupons", "targets", kind, search],
        queryFn: () => api.get<Target[]>("admin/coupons/targets", { kind, search }),
        enabled,
        placeholderData: keepPreviousData,
        staleTime: 30_000,
    });
}

/** The rows of ids a coupon already holds; ids that no longer exist are missing from the result. */
export function useTargetLookup(picks: { type: TargetType; id: number }[]) {
    const of = (type: TargetType) =>
        picks
            .filter((p) => p.type === type)
            .map((p) => p.id)
            .join(",");
    const query = { product_ids: of("product"), variant_ids: of("variant"), category_ids: of("category") };
    return useQuery({
        queryKey: ["coupons", "targets", "lookup", query],
        queryFn: () => api.get<Target[]>("admin/coupons/targets", query),
        enabled: picks.length > 0,
        staleTime: Infinity,
    });
}
