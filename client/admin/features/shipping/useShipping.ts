import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { FieldValues, SettingField } from "../../components/FieldControl";

export interface MethodInstance {
    instance_id: number;
    type: string;
    enabled: boolean;
    settings: FieldValues;
    /** The plugin that provides this method is not active. */
    missing?: boolean;
}

export interface Zone {
    id: number;
    name: string;
    regions: string[];
    postcodes: string[];
    methods: MethodInstance[];
}

export interface MethodType {
    type: string;
    title: string;
    fields: SettingField[];
}

export interface Country {
    code: string;
    name: string;
    states: { code: string; name: string }[];
}

export interface ShippingPayload {
    zones: Zone[];
    methods: MethodType[];
    countries: Country[];
}

const SHIPPING_KEY = ["shipping", "zones"] as const;

export function useShipping() {
    return useQuery({
        queryKey: SHIPPING_KEY,
        queryFn: () => api.get<ShippingPayload>("admin/shipping/zones"),
    });
}

export function useSaveZones() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (zones: Zone[]) =>
            api.put<ShippingPayload>("admin/shipping/zones", {
                zones: zones.map((z) => ({ ...z, methods: z.methods.map((m) => ({ ...m })) })),
            }),
        onSuccess: (next) => qc.setQueryData(SHIPPING_KEY, next),
    });
}
