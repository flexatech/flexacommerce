import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type CouponType = "percent" | "fixed_cart" | "fixed_product";
export type CouponStatus = "active" | "disabled";

export interface Coupon {
    id: number;
    code: string;
    type: CouponType;
    /** Minor units, or hundredths of a percent for `percent` (1000 = 10 %). */
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
    individual_use: boolean;
    exclude_sale_items: boolean;
    product_ids: number[];
    exclude_product_ids: number[];
    category_ids: number[];
    exclude_category_ids: number[];
    emails: string[];
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
