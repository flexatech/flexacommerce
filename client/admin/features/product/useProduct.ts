import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { GenerateResult, ProductData, StoreAttribute } from "./types";

export const productKey = (id: number) => ["products", id] as const;
export const ATTRIBUTES_KEY = ["attributes"] as const;

export function useProduct(id: number) {
    return useQuery<ProductData>({
        queryKey: productKey(id),
        queryFn: () => api.get<ProductData>(`admin/products/${id}`),
        // The draft in the editor is the source of truth once loaded.
        staleTime: Number.POSITIVE_INFINITY,
    });
}

export function useSaveProduct(id: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (payload: Record<string, unknown>) => api.post<ProductData>(`admin/products/${id}`, payload),
        onSuccess: (next) => qc.setQueryData(productKey(id), next),
    });
}

export function useGenerateVariants(id: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (values: Record<string, string[]>) =>
            api.post<GenerateResult>(`admin/products/${id}/variants/generate`, { values }),
        onSuccess: (result) => qc.setQueryData(productKey(id), result.product),
    });
}

export function useStoreAttributes() {
    return useQuery<StoreAttribute[]>({
        queryKey: ATTRIBUTES_KEY,
        queryFn: async () => (await api.get<{ items: StoreAttribute[] }>("admin/attributes")).items,
    });
}
