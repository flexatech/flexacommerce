import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { LogFilters, LogsPayload, StatusPayload } from "./types";

export const LOGS_PER_PAGE = 50;

export function useStatus() {
    return useQuery<StatusPayload>({
        queryKey: ["status"],
        queryFn: () => api.get<StatusPayload>("admin/status"),
    });
}

export function useLogs(filters: LogFilters) {
    return useQuery<LogsPayload>({
        queryKey: ["logs", filters],
        queryFn: () =>
            api.get<LogsPayload>("admin/logs", {
                level: filters.level,
                channel: filters.channel,
                search: filters.search,
                page: filters.page,
                per_page: LOGS_PER_PAGE,
            }),
        placeholderData: keepPreviousData,
    });
}

export function useClearLogs() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: () => api.delete<unknown>("admin/logs"),
        onSettled: () => void qc.invalidateQueries({ queryKey: ["logs"] }),
    });
}
