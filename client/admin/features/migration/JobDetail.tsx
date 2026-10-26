import { useId, useState } from "react";
import { ArrowLeft, CheckCircle2, CircleAlert, CircleX, Download, Info, Pause, Play, RefreshCw, Server, Square, Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import {
    COUNTER_LABELS,
    COUNTERS,
    DIRECTION_LABELS,
    download,
    DRY_RUN_COUNTER_LABELS,
    formatValue,
    STAGE_LABELS,
    STATUS_LABELS,
    STATUS_TONES,
} from "./format";
import { JobLog } from "./JobLog";
import type { Job, Problem, Stage } from "./types";
import { isActive, useJob, useJobAction, useJobRunner, usePurgeJob } from "./useMigrations";

const PROBLEM_ICONS = { error: CircleX, warning: CircleAlert, info: Info };
const PROBLEM_TONES = { error: "fxc:text-destructive", warning: "fxc:text-warning", info: "fxc:text-muted-foreground" };

// Typed by the admin before a purge.
const PURGE_WORD = "DELETE";

function ProblemList({ problems }: { problems: Problem[] }) {
    return (
        <ul>
            {problems.map((problem, i) => {
                const Icon = PROBLEM_ICONS[problem.level];
                const counts = problem.code === "inventory" ? (problem.context.counts as Record<string, number> | undefined) : undefined;
                return (
                    <li key={i} className="fxc:flex fxc:items-start fxc:gap-3 fxc:border-b fxc:px-5 fxc:py-3 fxc:last:border-b-0">
                        <Icon aria-hidden className={cn("fxc:mt-0.5 fxc:size-4 fxc:shrink-0", PROBLEM_TONES[problem.level])} />
                        <div className="fxc:min-w-0">
                            <p>{problem.message}</p>
                            {counts && (
                                <p className="fxc:text-muted-foreground fxc:text-xs">
                                    {Object.entries(counts)
                                        .map(([stage, n]) => `${STAGE_LABELS[stage as Stage] ?? stage}: ${n}`)
                                        .join(" · ")}
                                </p>
                            )}
                            <p className="fxc:text-muted-foreground fxc:font-mono fxc:text-xs">{problem.code}</p>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

function StageTable({ job }: { job: Job }) {
    const labels = job.dry_run ? DRY_RUN_COUNTER_LABELS : COUNTER_LABELS;
    const verified = job.report?.stages;
    const stages = job.options.stages;
    return (
        <table className="fxc:w-full fxc:text-left fxc:text-xs">
            <thead className="fxc:text-muted-foreground">
                <tr className="fxc:border-b">
                    <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Stage")}</th>
                    {verified && <th className="fxc:px-2 fxc:py-2 fxc:text-right fxc:font-medium">{__("In source")}</th>}
                    {COUNTERS.map((name) => (
                        <th key={name} className="fxc:px-2 fxc:py-2 fxc:text-right fxc:font-medium fxc:last:pr-5">
                            {labels[name]}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {stages.map((stage) => {
                    const counters = job.counters[stage] ?? {};
                    const current = isActive(job) && job.stage === stage;
                    return (
                        <tr key={stage} className="fxc:border-b fxc:last:border-b-0">
                            <td className="fxc:px-5 fxc:py-2">
                                <span className={cn(current && "fxc:font-semibold fxc:text-primary")}>{STAGE_LABELS[stage]}</span>
                                {job.cursor?.[stage]?.done && <CheckCircle2 aria-label={__("Done")} className="fxc:text-success fxc:ml-1 fxc:inline fxc:size-3" />}
                            </td>
                            {verified && (
                                <td className="fxc:px-2 fxc:py-2 fxc:text-right fxc:tabular-nums">{verified[stage]?.source ?? ""}</td>
                            )}
                            {COUNTERS.map((name) => (
                                <td
                                    key={name}
                                    className={cn(
                                        "fxc:px-2 fxc:py-2 fxc:text-right fxc:tabular-nums fxc:last:pr-5",
                                        name === "rejected" && (counters[name] ?? 0) > 0 && "fxc:text-destructive fxc:font-semibold",
                                        name === "warnings" && (counters[name] ?? 0) > 0 && "fxc:text-warning",
                                    )}
                                >
                                    {counters[name] ?? 0}
                                </td>
                            ))}
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}

function Verification({ job }: { job: Job }) {
    const report = job.report;
    if (!report?.verified_at) {
        return null;
    }
    const mismatches = report.mismatches ?? [];
    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Verification")}</CardTitle>
                <CardDescription>
                    {job.dry_run
                        ? sprintf(__("Counted at %s UTC. A dry run compares counts only."), report.verified_at)
                        : sprintf(
                              __("%1$d orders compared field by field at %2$s UTC; %3$d differences."),
                              report.checked ?? 0,
                              report.verified_at,
                              mismatches.length,
                          )}
                </CardDescription>
            </CardHeader>
            {mismatches.length > 0 && (
                <CardContent className="fxc:p-0">
                    <table className="fxc:w-full fxc:text-left fxc:text-xs">
                        <thead className="fxc:text-muted-foreground">
                            <tr className="fxc:border-b">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Source id")}</th>
                                <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Target id")}</th>
                                <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Field")}</th>
                                <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Expected")}</th>
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Found")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {mismatches.map((m, i) => (
                                <tr key={i} className="fxc:border-b fxc:last:border-b-0">
                                    <td className="fxc:px-5 fxc:py-2 fxc:font-mono">{m.source_id}</td>
                                    <td className="fxc:px-2 fxc:py-2 fxc:font-mono">{m.target_id}</td>
                                    <td className="fxc:px-2 fxc:py-2">{m.field}</td>
                                    <td className="fxc:px-2 fxc:py-2 fxc:font-mono">{formatValue(m.expected)}</td>
                                    <td className="fxc:px-5 fxc:py-2 fxc:font-mono">{formatValue(m.actual)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            )}
        </Card>
    );
}

function PurgeDialog({ job, open, onOpenChange }: { job: Job; open: boolean; onOpenChange: (open: boolean) => void }) {
    const purge = usePurgeJob(job.id);
    const [confirm, setConfirm] = useState("");
    const close = () => {
        if (!purge.isPending) {
            onOpenChange(false);
            setConfirm("");
            purge.reset();
        }
    };
    const run = () =>
        purge.mutate(undefined, {
            onSuccess: ({ purged }) => {
                const totals = Object.values(purged).reduce(
                    (sum, row) => ({ deleted: sum.deleted + row.deleted, kept: sum.kept + row.kept }),
                    { deleted: 0, kept: 0 },
                );
                close();
                showToast.success(sprintf(__("%1$d entities deleted, %2$d kept because they were edited after the import."), totals.deleted, totals.kept));
            },
        });
    return (
        <Dialog open={open} onOpenChange={(o) => (o ? onOpenChange(true) : close())}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__("Delete what this job created?")}</DialogTitle>
                    <DialogDescription>
                        {__("Entities this job created in the target are deleted, unless they were edited after the import. Entities that existed before are never touched.")}
                    </DialogDescription>
                </DialogHeader>
                <div>
                    <Label htmlFor="fxc-purge-confirm" className="fxc:mb-2 fxc:block">
                        {__("Type")} <code className="fxc:text-destructive fxc:font-mono">{PURGE_WORD}</code> {__("to confirm")}
                    </Label>
                    <Input id="fxc-purge-confirm" value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="off" autoFocus />
                    {purge.isError && <p className="fxc:text-destructive fxc:mt-2 fxc:text-sm">{purge.error.message}</p>}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={close} disabled={purge.isPending}>
                        {__("Cancel")}
                    </Button>
                    <Button variant="destructive" onClick={run} disabled={purge.isPending || confirm.trim() !== PURGE_WORD}>
                        {purge.isPending ? __("Deleting…") : __("Delete imported data")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Actions({ job }: { job: Job }) {
    const action = useJobAction(job.id);
    const runner = useJobRunner(job.id);
    const backupId = useId();
    const [backup, setBackup] = useState(job.options.backup_confirmed);
    const [purgeOpen, setPurgeOpen] = useState(false);
    const busy = action.isPending || runner.running;
    const needsBackup = !job.dry_run && !job.options.backup_confirmed;
    const finished = job.status === "completed" || job.status === "completed_with_warnings";
    const startable = job.status === "ready" || job.status === "paused" || job.status === "failed" || finished;

    const start = () =>
        action.mutate(
            { action: "start", body: { backup_confirmed: backup } },
            { onSuccess: () => void runner.run() },
        );

    return (
        <div className="fxc:space-y-3">
            {startable && needsBackup && (
                <div className="fxc:flex fxc:items-start fxc:gap-2">
                    <input
                        id={backupId}
                        type="checkbox"
                        checked={backup}
                        onChange={(e) => setBackup(e.target.checked)}
                        className="flexacommerce-check fxc:mt-0.5"
                    />
                    <label htmlFor={backupId} className="fxc:text-sm fxc:font-medium">
                        {__("I have a current database backup")}
                    </label>
                </div>
            )}
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                {(job.status === "draft" || job.status === "ready") && (
                    <Button variant="outline" size="sm" disabled={busy} onClick={() => action.mutate({ action: "preflight" })}>
                        <RefreshCw aria-hidden />
                        {__("Check again")}
                    </Button>
                )}
                {startable && (
                    <Button size="sm" disabled={busy || (needsBackup && !backup)} onClick={start}>
                        <Play aria-hidden />
                        {job.status === "ready" ? __("Start") : finished ? __("Run again") : __("Resume")}
                    </Button>
                )}
                {isActive(job) && !runner.running && (
                    <Button size="sm" onClick={() => void runner.run()}>
                        <Play aria-hidden />
                        {__("Run from this tab")}
                    </Button>
                )}
                {runner.running && (
                    <Button variant="outline" size="sm" onClick={runner.stop}>
                        <Square aria-hidden />
                        {__("Stop this tab")}
                    </Button>
                )}
                {job.status === "running" && (
                    <>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={action.isPending}
                            onClick={() => {
                                runner.stop();
                                action.mutate({ action: "background" }, { onSuccess: () => showToast.success(__("The job continues in the background.")) });
                            }}
                        >
                            <Server aria-hidden />
                            {__("Continue in the background")}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={action.isPending}
                            onClick={() => {
                                runner.stop();
                                action.mutate({ action: "pause" });
                            }}
                        >
                            <Pause aria-hidden />
                            {__("Pause")}
                        </Button>
                    </>
                )}
                {finished && (
                    <Button variant="outline" size="sm" disabled={busy} onClick={() => action.mutate({ action: "verify" })}>
                        <CheckCircle2 aria-hidden />
                        {__("Verify again")}
                    </Button>
                )}
                {job.report && (
                    <Button variant="ghost" size="sm" onClick={() => download(`flexacommerce-migration-${job.id}.json`, job.report)}>
                        <Download aria-hidden />
                        {__("Download report")}
                    </Button>
                )}
                <span className="fxc:ml-auto" />
                {["draft", "ready", "running", "paused", "failed"].includes(job.status) && (
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={action.isPending}
                        onClick={() => {
                            runner.stop();
                            action.mutate({ action: "cancel" });
                        }}
                    >
                        {__("Cancel job")}
                    </Button>
                )}
                {!job.dry_run && !isActive(job) && job.started_at !== null && (
                    <Button variant="ghost" size="sm" className="fxc:text-destructive" onClick={() => setPurgeOpen(true)}>
                        <Trash2 aria-hidden />
                        {__("Delete imported data…")}
                    </Button>
                )}
            </div>
            {runner.locked && (
                <p className="fxc:text-muted-foreground fxc:text-xs">
                    {__("Another runner holds this job (the background driver, WP-CLI or another tab). This tab waits for it.")}
                </p>
            )}
            <PurgeDialog job={job} open={purgeOpen} onOpenChange={setPurgeOpen} />
        </div>
    );
}

export function JobDetail({ id, onBack }: { id: number; onBack: () => void }) {
    const query = useJob(id);

    if (query.isPending) {
        return <p className="fxc:text-muted-foreground">{__("Loading…")}</p>;
    }
    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }
    const job = query.data;
    const preflight = job.report?.preflight ?? [];

    return (
        <div className="fxc:space-y-6">
            <div className="fxc:flex fxc:items-start fxc:gap-3">
                <Button variant="ghost" size="icon" onClick={onBack} aria-label={__("Back to jobs")}>
                    <ArrowLeft aria-hidden />
                </Button>
                <div>
                    <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                        <h2 className="fxc:text-base fxc:font-semibold">{sprintf(__("Job #%d"), job.id)}</h2>
                        <Badge variant={STATUS_TONES[job.status]}>{STATUS_LABELS[job.status]}</Badge>
                        {job.dry_run && <Badge>{__("Dry run")}</Badge>}
                    </div>
                    <p className="fxc:text-muted-foreground fxc:text-sm">
                        {DIRECTION_LABELS[job.direction]}
                        {job.started_at && ` · ${sprintf(__("started %s UTC"), job.started_at)}`}
                        {job.finished_at && ` · ${sprintf(__("finished %s UTC"), job.finished_at)}`}
                    </p>
                </div>
            </div>

            <Actions job={job} />

            {preflight.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>{__("Preflight")}</CardTitle>
                        <CardDescription>
                            {job.status === "draft"
                                ? __("Fix the errors below, then check again.")
                                : sprintf(__("Checked at %s UTC."), job.report?.checked_at ?? "")}
                        </CardDescription>
                    </CardHeader>
                    <ProblemList problems={preflight} />
                </Card>
            )}

            {job.started_at !== null && (
                <Card>
                    <CardHeader>
                        <CardTitle>{__("Progress")}</CardTitle>
                    </CardHeader>
                    <CardContent className="fxc:overflow-x-auto fxc:p-0">
                        <StageTable job={job} />
                    </CardContent>
                </Card>
            )}

            <Verification job={job} />

            {job.started_at !== null && <JobLog id={job.id} />}
        </div>
    );
}
