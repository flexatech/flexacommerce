import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type BadgePosition = "top-left" | "top-right" | "bottom-left" | "bottom-right";
export type BadgeShape = "rounded" | "pill" | "square" | "circle";
export type BadgeCondition = "always" | "on_sale" | "new" | "featured" | "low_stock" | "out_of_stock";
export type BadgeScope = "all" | "products" | "categories";
export type BadgePlaces = "all" | "single" | "loop";
export type BadgeCustomers = "all" | "logged_in" | "guests" | "roles";

export interface Role {
    value: string;
    label: string;
}

export interface ProductBadge {
    id: number;
    /** `{percent}` becomes the sale discount. */
    label: string;
    bg_color: string;
    text_color: string;
    position: BadgePosition;
    shape: BadgeShape;
    enabled: boolean;
    condition: BadgeCondition;
    /** Days a product counts as new, for the `new` condition. */
    new_days: number;
    applies_to: BadgeScope;
    product_ids: number[];
    category_ids: number[];
    exclude_product_ids: number[];
    places: BadgePlaces;
    /** UTC, ISO 8601 with Z. */
    starts_at: string | null;
    ends_at: string | null;
    /** Who sees it (ADR-049); `roles` is used with `roles` only. */
    customers: BadgeCustomers;
    roles: string[];
}

export type BadgeInput = Omit<ProductBadge, "id">;

interface Listing {
    items: ProductBadge[];
    /** The roles a badge can name. */
    roles: Role[];
}

const KEY = ["badges"] as const;

export function useBadges() {
    return useQuery({
        queryKey: KEY,
        queryFn: () => api.get<Listing>("admin/badges"),
        select: (data) => data.items,
    });
}

export function useBadgeRoles(): Role[] {
    const query = useQuery({
        queryKey: KEY,
        queryFn: () => api.get<Listing>("admin/badges"),
        select: (data) => data.roles,
    });
    return query.data ?? [];
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

export function useSaveBadge() {
    return useListMutation(({ id, input }: { id: number; input: Partial<BadgeInput> }) =>
        id > 0 ? api.patch<Listing>(`admin/badges/${id}`, { ...input }) : api.post<Listing>("admin/badges", { ...input }),
    );
}

export function useDeleteBadge() {
    return useListMutation((id: number) => api.delete<Listing>(`admin/badges/${id}`));
}

export function useReorderBadges() {
    return useListMutation((ids: number[]) => api.post<Listing>("admin/badges/reorder", { ids }));
}
