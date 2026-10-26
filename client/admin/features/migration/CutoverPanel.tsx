import { useId, useState, type ReactNode } from "react";
import { CheckCircle2, Circle, CircleX, ExternalLink, Undo2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/shared/components/ui/dialog";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { getPluginGlobal } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import type { BaseKey, CutoverStatus, CutoverStepKey } from "./types";
import { useCutover, useCutoverStep, useMigrations, type CutoverAction } from "./useMigrations";

const STEP_TITLES: Record<CutoverStepKey, string> = {
    freeze: __("Pause checkout"),
    verify: __("Final run and verification"),
    bases: __("Adopt the URL bases"),
    pages: __("Assign the store pages"),
    deactivate: __("Deactivate WooCommerce"),
    urls: __("Check sample URLs"),
    redirects: __("Turn on redirects"),
    reopen: __("Reopen checkout"),
};

const BASE_LABELS: Record<BaseKey, string> = {
    product: __("Product"),
    shop: __("Shop page"),
    category: __("Category"),
    tag: __("Tag"),
};

function Check({ checked, onChange, children }: { checked: boolean; onChange: (checked: boolean) => void; children: ReactNode }) {
    const id = useId();
    return (
        <div className="fxc:flex fxc:items-start fxc:gap-2">
            <input
                id={id}
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="flexacommerce-check fxc:mt-0.5"
            />
            <label htmlFor={id} className="fxc:text-sm">
                {children}
            </label>
        </div>
    );
}

function VerifyStep({ run, pending }: { run: (body: Record<string, unknown>) => void; pending: boolean }) {
    const migrations = useMigrations();
    const candidates = (migrations.data?.jobs ?? []).filter(
        (job) => job.direction === "wc_to_fxc" && !job.dry_run && (job.status === "completed" || job.status === "completed_with_warnings"),
    );
    const [jobId, setJobId] = useState("");
    const [ack, setAck] = useState(false);
    const chosen = jobId !== "" ? jobId : String(candidates[0]?.id ?? "");
    const job = candidates.find((j) => String(j.id) === chosen);

    return (
        <div className="fxc:space-y-3">
            <p className="fxc:text-muted-foreground fxc:text-sm">
                {__("Run the WooCommerce to FlexaCommerce job again now that checkout is paused, then pick it here. It must have no rejected records and no verification differences.")}
            </p>
            {candidates.length === 0 ? (
                <p className="fxc:text-sm">{__("No finished WooCommerce to FlexaCommerce job yet. Run one on the Jobs tab first.")}</p>
            ) : (
                <>
                    <div>
                        <Label htmlFor="fxc-cutover-job" className="fxc:mb-2 fxc:block">
                            {__("Job")}
                        </Label>
                        <Select
                            id="fxc-cutover-job"
                            value={chosen}
                            onChange={(e) => setJobId(e.target.value)}
                            options={candidates.map((j) => ({
                                value: String(j.id),
                                label: sprintf(__("#%1$d, finished %2$s UTC"), j.id, j.finished_at ?? ""),
                            }))}
                        />
                    </div>
                    {(job?.totals.warnings ?? 0) > 0 && (
                        <Check checked={ack} onChange={setAck}>
                            {sprintf(__("I have read the %d warnings of this job and accept them"), job?.totals.warnings ?? 0)}
                        </Check>
                    )}
                    <Button size="sm" disabled={pending} onClick={() => run({ job_id: Number(chosen), acknowledge_warnings: ack })}>
                        {__("Use this job")}
                    </Button>
                </>
            )}
        </div>
    );
}

function BasesTable({ bases }: { bases: CutoverStatus["bases"] }) {
    return (
        <table className="fxc:w-full fxc:text-left fxc:text-xs">
            <thead className="fxc:text-muted-foreground">
                <tr className="fxc:border-b">
                    <th className="fxc:py-2 fxc:pr-2 fxc:font-medium">{__("Base")}</th>
                    <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("WooCommerce")}</th>
                    <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("FlexaCommerce")}</th>
                    <th className="fxc:py-2 fxc:pl-2 fxc:font-medium">{__("URLs")}</th>
                </tr>
            </thead>
            <tbody>
                {(Object.keys(BASE_LABELS) as BaseKey[]).map((key) => (
                    <tr key={key} className="fxc:border-b fxc:last:border-b-0">
                        <td className="fxc:py-2 fxc:pr-2">{BASE_LABELS[key]}</td>
                        <td className="fxc:px-2 fxc:py-2 fxc:font-mono">/{bases[key].wc}</td>
                        <td className="fxc:px-2 fxc:py-2 fxc:font-mono">/{bases[key].fxc}</td>
                        <td className="fxc:py-2 fxc:pl-2">{bases[key].identical ? __("Unchanged") : __("Redirected (step 7)")}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function UrlResults({ urls }: { urls: NonNullable<CutoverStatus["urls"]> }) {
    return (
        <ul className="fxc:max-h-64 fxc:overflow-y-auto fxc:rounded-md fxc:border fxc:text-xs">
            {urls.results.map((row) => (
                <li key={`${row.type}-${row.id}`} className="fxc:flex fxc:items-center fxc:gap-2 fxc:border-b fxc:px-3 fxc:py-1.5 fxc:last:border-b-0">
                    {row.ok ? (
                        <CheckCircle2 aria-label={__("OK")} className="fxc:text-success fxc:size-3.5 fxc:shrink-0" />
                    ) : (
                        <CircleX aria-label={__("Failed")} className="fxc:text-destructive fxc:size-3.5 fxc:shrink-0" />
                    )}
                    <span className="fxc:text-muted-foreground fxc:w-10 fxc:shrink-0 fxc:tabular-nums">{row.status || "-"}</span>
                    <a href={row.url} target="_blank" rel="noreferrer" className="fxc:truncate fxc:font-mono">
                        {row.url}
                    </a>
                </li>
            ))}
        </ul>
    );
}

function StepBody({ step, status, run, pending }: { step: CutoverStepKey; status: CutoverStatus; run: (action: CutoverAction, body?: Record<string, unknown>) => void; pending: boolean }) {
    const [mode, setMode] = useState("flexacommerce");
    const [accept, setAccept] = useState(false);
    const plugins = getPluginGlobal().adminUrl.replace(/admin\.php$/, "plugins.php");

    switch (step) {
        case "freeze":
            return (
                <div className="fxc:space-y-3">
                    <p className="fxc:text-muted-foreground fxc:text-sm">
                        {__("FlexaCommerce cannot stop WooCommerce checkout. Put the shop in maintenance mode or catalog mode yourself first, so no order is placed between the final run and the switch. This step also keeps FlexaCommerce checkout closed until the last step.")}
                    </p>
                    <Button size="sm" disabled={pending} onClick={() => run("freeze")}>
                        {__("Checkout is paused, continue")}
                    </Button>
                </div>
            );
        case "verify":
            return <VerifyStep pending={pending} run={(body) => run("verify", body)} />;
        case "bases":
            return (
                <div className="fxc:space-y-3">
                    <p className="fxc:text-muted-foreground fxc:text-sm">
                        {__("FlexaCommerce takes the WooCommerce bases, so product and category URLs stay the same. A base with a placeholder such as %product_cat% cannot be copied; its old URLs get a redirect.")}
                    </p>
                    <BasesTable bases={status.bases} />
                    <Button size="sm" disabled={pending} onClick={() => run("bases")}>
                        {__("Adopt these bases")}
                    </Button>
                </div>
            );
        case "pages":
            return (
                <div className="fxc:space-y-3">
                    <div>
                        <Label htmlFor="fxc-cutover-pages" className="fxc:mb-2 fxc:block">
                            {__("Cart, checkout and account pages")}
                        </Label>
                        <Select
                            id="fxc-cutover-pages"
                            value={mode}
                            onChange={(e) => setMode(e.target.value)}
                            options={[
                                { value: "flexacommerce", label: __("Use the FlexaCommerce pages") },
                                { value: "woocommerce", label: __("Reuse the WooCommerce pages") },
                            ]}
                        />
                        <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                            {mode === "woocommerce"
                                ? __("Keeps the page URLs. Their content is replaced with the FlexaCommerce blocks; a rollback puts the old content back.")
                                : __("Creates the FlexaCommerce pages where they are missing.")}
                        </p>
                    </div>
                    <Button size="sm" disabled={pending} onClick={() => run("pages", { mode })}>
                        {__("Assign pages")}
                    </Button>
                </div>
            );
        case "deactivate":
            return (
                <p className="fxc:text-muted-foreground fxc:text-sm">
                    {__("Deactivate WooCommerce on the Plugins screen (FlexaCommerce never deactivates another plugin), then come back here.")}{" "}
                    <a href={plugins} className="fxc:inline-flex fxc:items-center fxc:gap-1">
                        {__("Open Plugins")}
                        <ExternalLink aria-hidden className="fxc:size-3" />
                    </a>
                </p>
            );
        case "urls":
            return (
                <div className="fxc:space-y-3">
                    <p className="fxc:text-muted-foreground fxc:text-sm">
                        {__("Flushes the rewrite rules, then opens up to 20 imported products and categories and checks that each shows the right page.")}
                    </p>
                    {status.urls && (
                        <>
                            <p className={cn("fxc:text-sm", status.urls.failed > 0 && "fxc:text-destructive")}>
                                {sprintf(__("%1$d of %2$d URLs failed at %3$s UTC."), status.urls.failed, status.urls.checked, status.urls.checked_at)}
                            </p>
                            <UrlResults urls={status.urls} />
                        </>
                    )}
                    {status.urls !== null && status.urls.failed > 0 && (
                        <Check checked={accept} onChange={setAccept}>
                            {__("Continue anyway, I will fix these URLs myself")}
                        </Check>
                    )}
                    <Button size="sm" disabled={pending} onClick={() => run("urls", { accept_failures: accept })}>
                        {status.urls ? __("Check again") : __("Check URLs")}
                    </Button>
                </div>
            );
        case "redirects":
            return (
                <div className="fxc:space-y-3">
                    <p className="fxc:text-muted-foreground fxc:text-sm">
                        {__("Old WooCommerce links (?post_type=product&p=ID, ?product=slug and paths under a base that changed) answer with a permanent redirect to the FlexaCommerce page. They stop if WooCommerce is activated again.")}
                    </p>
                    <Button size="sm" disabled={pending} onClick={() => run("redirects")}>
                        {__("Turn on redirects")}
                    </Button>
                </div>
            );
        case "reopen":
            return (
                <div className="fxc:space-y-3">
                    <p className="fxc:text-muted-foreground fxc:text-sm">{__("Opens FlexaCommerce checkout. Turn off the maintenance or catalog mode you used in step 1.")}</p>
                    <Button size="sm" disabled={pending} onClick={() => run("reopen")}>
                        {__("Reopen checkout")}
                    </Button>
                </div>
            );
    }
}

function RollbackDialog({ open, onOpenChange, run, pending }: { open: boolean; onOpenChange: (open: boolean) => void; run: () => void; pending: boolean }) {
    return (
        <Dialog open={open} onOpenChange={(o) => !pending && onOpenChange(o)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__("Roll back the cutover?")}</DialogTitle>
                    <DialogDescription>
                        {__("Puts back the URL bases, page settings and page content from before the wizard, turns the redirects off and reopens FlexaCommerce checkout. Imported records stay. If WooCommerce was deactivated, activate it again yourself.")}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={pending}>
                        {__("Cancel")}
                    </Button>
                    <Button variant="destructive" onClick={run} disabled={pending}>
                        {pending ? __("Rolling back…") : __("Roll back")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function CutoverPanel() {
    const cutover = useCutover();
    const step = useCutoverStep();
    const [rollbackOpen, setRollbackOpen] = useState(false);

    if (cutover.isPending) {
        return <p className="fxc:text-muted-foreground">{__("Loading…")}</p>;
    }
    if (cutover.isError) {
        return <p className="fxc:text-destructive">{cutover.error.message}</p>;
    }
    const status = cutover.data;
    const next = status.steps.find((s) => !s.done)?.key ?? null;
    const started = status.steps.some((s) => s.done && s.key !== "deactivate");
    const run = (action: CutoverAction, body?: Record<string, unknown>) => step.mutate({ step: action, body });

    return (
        <div className="fxc:space-y-4">
            <div className="fxc:flex fxc:items-start fxc:justify-between fxc:gap-4">
                <p className="fxc:text-muted-foreground fxc:text-sm">
                    {status.finished
                        ? __("The store runs on FlexaCommerce. Keep WooCommerce inactive: the redirects only work while it is.")
                        : __("Switch the live store from WooCommerce to FlexaCommerce, one step at a time. Every step can be rolled back until you reopen checkout, and after that too.")}
                </p>
                <div className="fxc:flex fxc:shrink-0 fxc:gap-2">
                    {next === "deactivate" && (
                        <Button variant="outline" size="sm" onClick={() => void cutover.refetch()} disabled={cutover.isFetching}>
                            {__("Check again")}
                        </Button>
                    )}
                    {started && (
                        <Button variant="outline" size="sm" onClick={() => setRollbackOpen(true)}>
                            <Undo2 aria-hidden />
                            {__("Roll back…")}
                        </Button>
                    )}
                </div>
            </div>

            <ol className="fxc:space-y-3">
                {status.steps.map((s, i) => {
                    const current = s.key === next;
                    return (
                        <li key={s.key}>
                            <Card className={cn(!current && "fxc:shadow-none", current && "fxc:border-primary")}>
                                <CardHeader className={cn(!current && "fxc:border-b-0")}>
                                    <div className="fxc:flex fxc:items-center fxc:gap-3">
                                        {s.done ? (
                                            <CheckCircle2 aria-label={__("Done")} className="fxc:text-success fxc:size-5 fxc:shrink-0" />
                                        ) : (
                                            <Circle aria-hidden className="fxc:text-muted-foreground fxc:size-5 fxc:shrink-0" />
                                        )}
                                        <CardTitle className="fxc:text-sm">{sprintf(__("%1$d. %2$s"), i + 1, STEP_TITLES[s.key])}</CardTitle>
                                        {s.at && <CardDescription className="fxc:ml-auto fxc:text-xs">{s.at} UTC</CardDescription>}
                                        {s.key === "verify" && status.job_id !== null && (
                                            <CardDescription className="fxc:text-xs">{sprintf(__("job #%d"), status.job_id)}</CardDescription>
                                        )}
                                    </div>
                                </CardHeader>
                                {current && (
                                    <div className="fxc:px-5 fxc:py-4">
                                        <StepBody step={s.key} status={status} pending={step.isPending} run={run} />
                                    </div>
                                )}
                            </Card>
                        </li>
                    );
                })}
            </ol>

            <RollbackDialog
                open={rollbackOpen}
                onOpenChange={setRollbackOpen}
                pending={step.isPending}
                run={() =>
                    step.mutate(
                        { step: "rollback" },
                        {
                            onSuccess: () => {
                                setRollbackOpen(false);
                                showToast.success(__("The cutover was rolled back."));
                            },
                        },
                    )
                }
            />
        </div>
    );
}
