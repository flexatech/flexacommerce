import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useCallback, useEffect, useRef, useState } from "react";

import { showToast } from "@/shared/components/showToast";
import { api } from "@/shared/lib/api";
import type { BatchResult, CutoverStatus, Direction, Job, JobOptions, LogRow, MigrationsIndex, PurgeResult } from "./types";

export const LOG_PER_PAGE = 50;

const ACTIVE: Job["status"][] = ["running", "verifying"];

export function isActive(job: Job): boolean {
    return ACTIVE.includes(job.status);
}

export function useMigrations() {
    return useQuery<MigrationsIndex>({
        queryKey: ["migrations"],
        queryFn: () => api.get<MigrationsIndex>("admin/migrations"),
    });
}

export function useJob(id: number | null) {
    return useQuery<Job>({
        queryKey: ["migrations", "job", id],
        queryFn: () => api.get<Job>(`admin/migrations/${id}`),
        enabled: id !== null,
        // A job driven by the background driver or the CLI moves on its own.
        refetchInterval: (query) => (query.state.data && isActive(query.state.data) ? 5_000 : false),
    });
}

export function useJobLog(id: number, level: string, page: number) {
    return useQuery({
        queryKey: ["migrations", "log", id, level, page],
        queryFn: () => api.list<LogRow>(`admin/migrations/${id}/log`, { level, page, per_page: LOG_PER_PAGE }),
        placeholderData: keepPreviousData,
    });
}

export interface NewJob {
    direction: Direction;
    dry_run: boolean;
    options: Partial<JobOptions>;
}

export function useCreateJob() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (job: NewJob) => api.post<Job>("admin/migrations", { ...job }),
        onSuccess: (job) => qc.setQueryData(["migrations", "job", job.id], job),
        onSettled: () => void qc.invalidateQueries({ queryKey: ["migrations"], exact: true }),
    });
}

export type JobAction = "preflight" | "start" | "pause" | "cancel" | "verify" | "background";

/** One POST per lifecycle action; the response is the job. */
export function useJobAction(id: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ action, body }: { action: JobAction; body?: Record<string, unknown> }) =>
            api.post<Job>(`admin/migrations/${id}/${action}`, body ?? {}),
        onSuccess: (job) => qc.setQueryData(["migrations", "job", id], job),
        onError: (error) => showToast.error(error.message),
        onSettled: () => void qc.invalidateQueries({ queryKey: ["migrations"], exact: true }),
    });
}

export function usePurgeJob(id: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: () => api.post<{ purged: PurgeResult }>(`admin/migrations/${id}/purge`),
        onSettled: () => void qc.invalidateQueries({ queryKey: ["migrations"] }),
    });
}

const sleep = (ms: number) => new Promise((resolve) => window.setTimeout(resolve, ms));

/**
 * Drives a running job from this tab, one `batch` call after the other,
 * until the job leaves running and verifying. A `locked` answer means the
 * background driver or another tab holds the lease, so it waits and asks
 * again instead of running a second copy.
 */
export function useJobRunner(id: number) {
    const qc = useQueryClient();
    const [running, setRunning] = useState(false);
    const [locked, setLocked] = useState(false);
    const stopped = useRef(true);

    useEffect(
        () => () => {
            stopped.current = true;
        },
        [],
    );

    const run = useCallback(async () => {
        if (!stopped.current) {
            return;
        }
        stopped.current = false;
        setRunning(true);
        try {
            while (!stopped.current) {
                const result = await api.post<BatchResult>(`admin/migrations/${id}/batch`);
                qc.setQueryData(["migrations", "job", id], result.job);
                setLocked(result.locked);
                if (result.locked) {
                    await sleep(3_000);
                    continue;
                }
                if (result.done || !isActive(result.job)) {
                    break;
                }
            }
        } catch (error) {
            showToast.error((error as Error).message);
        } finally {
            stopped.current = true;
            setRunning(false);
            setLocked(false);
            void qc.invalidateQueries({ queryKey: ["migrations"] });
        }
    }, [id, qc]);

    const stop = useCallback(() => {
        stopped.current = true;
    }, []);

    return { running, locked, run, stop };
}

export function useCutover() {
    return useQuery<CutoverStatus>({
        queryKey: ["cutover"],
        queryFn: () => api.get<CutoverStatus>("admin/migrations/cutover"),
    });
}

export type CutoverAction = "freeze" | "verify" | "bases" | "pages" | "urls" | "redirects" | "reopen" | "rollback";

export function useCutoverStep() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ step, body }: { step: CutoverAction; body?: Record<string, unknown> }) =>
            api.post<CutoverStatus>(`admin/migrations/cutover/${step}`, body ?? {}),
        onSuccess: (status) => qc.setQueryData(["cutover"], status),
        onError: (error) => {
            // A refused step still reports where the wizard stands (the URL check saves its results).
            void qc.invalidateQueries({ queryKey: ["cutover"] });
            showToast.error(error.message);
        },
    });
}
