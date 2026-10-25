import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { FieldValues, SettingField } from "../../components/FieldControl";

export interface Gateway {
    id: string;
    title: string;
    enabled: boolean;
    supports: { refunds: boolean };
    fields: SettingField[];
    values: FieldValues;
}

const GATEWAYS_KEY = ["payments", "gateways"] as const;

export function useGateways() {
    return useQuery({
        queryKey: GATEWAYS_KEY,
        queryFn: () => api.get<Gateway[]>("admin/payments/gateways"),
    });
}

export function useSaveGateway() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, values }: { id: string; values: FieldValues }) =>
            api.patch<Gateway>(`admin/payments/gateways/${encodeURIComponent(id)}`, { values }),
        onSuccess: (gateway) =>
            qc.setQueryData<Gateway[]>(GATEWAYS_KEY, (list) => list?.map((g) => (g.id === gateway.id ? gateway : g))),
    });
}
