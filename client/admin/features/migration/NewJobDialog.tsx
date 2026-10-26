import { useId, useState } from "react";

import { Button } from "@/shared/components/ui/button";
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
import { Select } from "@/shared/components/ui/select";
import { __, sprintf } from "@/shared/lib/i18n";
import { DIRECTION_LABELS, formatBytes, STAGE_LABELS } from "./format";
import type { Direction, Job, JobOptions, MigrationsIndex, Stage } from "./types";
import { useCreateJob, useImportFile } from "./useMigrations";

type Source = "site" | "file";

function Check({ checked, onChange, label, help }: { checked: boolean; onChange: (checked: boolean) => void; label: string; help?: string }) {
    const id = useId();
    return (
        <div className="fxc:flex fxc:items-start fxc:gap-2">
            <input
                id={id}
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="flexacommerce-check fxc:mt-0.5"
            />
            <div>
                <label htmlFor={id} className="fxc:text-foreground fxc:text-sm fxc:font-medium">
                    {label}
                </label>
                {help && <p className="fxc:text-muted-foreground fxc:text-xs">{help}</p>}
            </div>
        </div>
    );
}

function UploadProgress({ phase, sent, total }: { phase: "uploading" | "checking"; sent: number; total: number }) {
    return (
        <div className="fxc:space-y-1">
            <div
                role="progressbar"
                aria-label={__("Upload")}
                aria-valuemin={0}
                aria-valuemax={total}
                aria-valuenow={sent}
                className="fxc:bg-muted fxc:h-2 fxc:overflow-hidden fxc:rounded-full"
            >
                <div className="fxc:bg-primary fxc:h-full fxc:transition-[width]" style={{ width: `${total > 0 ? (sent / total) * 100 : 0}%` }} />
            </div>
            <p className="fxc:text-muted-foreground fxc:text-xs">
                {phase === "uploading"
                    ? sprintf(__("Uploading %1$s of %2$s"), formatBytes(sent), formatBytes(total))
                    : __("Checking every line of the file. A large file can take a few minutes.")}
            </p>
        </div>
    );
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    index: MigrationsIndex;
    onCreated: (id: number) => void;
}

export function NewJobDialog({ open, onOpenChange, index, onCreated }: Props) {
    const create = useCreateJob();
    const importFile = useImportFile();
    const firstJob = index.jobs.length === 0;
    const [source, setSource] = useState<Source>("site");
    const [file, setFile] = useState<File | null>(null);
    const [media, setMedia] = useState(true);
    const [direction, setDirection] = useState<Direction>(index.woocommerce ? "wc_to_fxc" : "fxc_to_wc");
    const [dryRun, setDryRun] = useState(true);
    const [stages, setStages] = useState<Stage[]>(index.defaults.stages);
    const [targetModified, setTargetModified] = useState<JobOptions["target_modified"]>(index.defaults.target_modified);
    const [duplicateSku, setDuplicateSku] = useState<JobOptions["duplicate_sku"]>(index.defaults.duplicate_sku);
    const [numberConflict, setNumberConflict] = useState<JobOptions["order_number_conflict"]>(index.defaults.order_number_conflict);
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");

    const toggleStage = (stage: Stage, on: boolean) =>
        setStages((current) => index.stages.filter((s) => (s === stage ? on : current.includes(s))));

    const busy = create.isPending || importFile.busy;
    const progress = importFile.progress;
    const error = source === "file" ? importFile.error : create.isError ? create.error.message : null;
    const policies = {
        stages,
        target_modified: targetModified,
        duplicate_sku: duplicateSku,
        order_number_conflict: numberConflict,
    };

    const done = (job: Job) => {
        onOpenChange(false);
        onCreated(job.id);
    };

    const submit = () => {
        if (source === "file") {
            if (file) {
                void importFile
                    .run(file, { dry_run: dryRun, options: { ...policies, media: media ? "download" : "skip" } })
                    .then((job) => job && done(job));
            }
            return;
        }
        create.mutate(
            {
                direction,
                dry_run: dryRun,
                options: {
                    ...policies,
                    orders_from: from !== "" ? `${from}T00:00:00Z` : null,
                    orders_to: to !== "" ? `${to}T23:59:59Z` : null,
                },
            },
            { onSuccess: done },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
            <DialogContent className="fxc:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{__("New migration")}</DialogTitle>
                    <DialogDescription>
                        {__("The job is checked as soon as it is created. Nothing is copied until you start it.")}
                    </DialogDescription>
                </DialogHeader>

                <div className="fxc:space-y-5">
                    <div>
                        <Label htmlFor="fxc-migration-source" className="fxc:mb-2 fxc:block">
                            {__("Copy from")}
                        </Label>
                        <Select
                            id="fxc-migration-source"
                            className="fxc:w-full"
                            value={source}
                            disabled={busy}
                            onChange={(e) => setSource(e.target.value as Source)}
                            options={[
                                { value: "site", label: __("The other store on this site") },
                                { value: "file", label: __("An export file from another site") },
                            ]}
                        />
                    </div>

                    {source === "file" ? (
                        <div>
                            <Label htmlFor="fxc-migration-file" className="fxc:mb-2 fxc:block">
                                {__("Export file")}
                            </Label>
                            <input
                                id="fxc-migration-file"
                                type="file"
                                accept=".jsonl,.ndjson,.json,application/x-ndjson"
                                disabled={busy}
                                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                            />
                            <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                                {file
                                    ? sprintf(__("%1$s, %2$s. The direction comes from the file."), file.name, formatBytes(file.size))
                                    : __("Made on the other site with wp flexacommerce migrate export. A WooCommerce export imports into FlexaCommerce, a FlexaCommerce export into WooCommerce.")}
                            </p>
                        </div>
                    ) : (
                        <div>
                            <Label htmlFor="fxc-migration-direction" className="fxc:mb-2 fxc:block">
                                {__("Direction")}
                            </Label>
                            <Select
                                id="fxc-migration-direction"
                                className="fxc:w-full"
                                value={direction}
                                onChange={(e) => setDirection(e.target.value as Direction)}
                                options={index.directions.map((d) => ({ value: d, label: DIRECTION_LABELS[d] }))}
                            />
                            {!index.woocommerce && (
                                <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                                    {__("WooCommerce is not active, so the preflight will block either direction until it is.")}
                                </p>
                            )}
                        </div>
                    )}

                    <Check
                        checked={dryRun}
                        onChange={setDryRun}
                        label={__("Dry run")}
                        help={
                            firstJob
                                ? __("Recommended for the first job: everything is read and checked, nothing is written.")
                                : __("Read and check everything without writing to the target.")
                        }
                    />

                    {source === "file" && (
                        <Check
                            checked={media}
                            onChange={setMedia}
                            label={__("Download images from the source site")}
                            help={__("The source site must be online while the job runs. Off: products import without their images.")}
                        />
                    )}

                    <fieldset className="fxc:space-y-2">
                        <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("What to copy")}</legend>
                        <div className="fxc:grid fxc:gap-2 fxc:sm:grid-cols-2">
                            {index.stages.map((stage) => (
                                <Check
                                    key={stage}
                                    checked={stages.includes(stage)}
                                    onChange={(on) => toggleStage(stage, on)}
                                    label={STAGE_LABELS[stage]}
                                />
                            ))}
                        </div>
                    </fieldset>

                    <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                        <div>
                            <Label htmlFor="fxc-migration-modified" className="fxc:mb-2 fxc:block">
                                {__("Edited in the target since the last run")}
                            </Label>
                            <Select
                                id="fxc-migration-modified"
                                className="fxc:w-full"
                                value={targetModified}
                                onChange={(e) => setTargetModified(e.target.value as JobOptions["target_modified"])}
                                options={[
                                    { value: "skip", label: __("Keep the target's edit") },
                                    { value: "source_wins", label: __("Overwrite from the source") },
                                ]}
                            />
                        </div>
                        <div>
                            <Label htmlFor="fxc-migration-sku" className="fxc:mb-2 fxc:block">
                                {__("Duplicate SKU")}
                            </Label>
                            <Select
                                id="fxc-migration-sku"
                                className="fxc:w-full"
                                value={duplicateSku}
                                onChange={(e) => setDuplicateSku(e.target.value as JobOptions["duplicate_sku"])}
                                options={[
                                    { value: "null", label: __("Import without SKU, with a warning") },
                                    { value: "suffix", label: __("Add a suffix") },
                                    { value: "skip", label: __("Skip the product") },
                                ]}
                            />
                        </div>
                        <div>
                            <Label htmlFor="fxc-migration-number" className="fxc:mb-2 fxc:block">
                                {__("Order number already taken")}
                            </Label>
                            <Select
                                id="fxc-migration-number"
                                className="fxc:w-full"
                                value={numberConflict}
                                onChange={(e) => setNumberConflict(e.target.value as JobOptions["order_number_conflict"])}
                                options={[
                                    { value: "prefix", label: __("Add a prefix") },
                                    { value: "skip", label: __("Skip the order") },
                                ]}
                            />
                        </div>
                    </div>

                    {source === "site" && stages.includes("orders") && (
                        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                            <div>
                                <Label htmlFor="fxc-migration-from" className="fxc:mb-2 fxc:block">
                                    {__("Orders from (UTC)")}
                                </Label>
                                <Input id="fxc-migration-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                            </div>
                            <div>
                                <Label htmlFor="fxc-migration-to" className="fxc:mb-2 fxc:block">
                                    {__("Orders to (UTC)")}
                                </Label>
                                <Input id="fxc-migration-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                            </div>
                        </div>
                    )}

                    {source === "file" && file && progress.phase === "idle" && (
                        <p className="fxc:text-muted-foreground fxc:text-xs">
                            {__("The file holds customer names, emails and addresses. It stays on this server until you delete it from the job page.")}
                        </p>
                    )}

                    {progress.phase !== "idle" && <UploadProgress phase={progress.phase} sent={progress.sent} total={progress.total} />}

                    {error && <p className="fxc:text-destructive fxc:text-sm">{error}</p>}
                </div>

                <DialogFooter>
                    {progress.phase === "uploading" ? (
                        <Button variant="ghost" onClick={importFile.cancel}>
                            {__("Stop upload")}
                        </Button>
                    ) : (
                        <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={busy}>
                            {__("Cancel")}
                        </Button>
                    )}
                    <Button onClick={submit} disabled={busy || stages.length === 0 || (source === "file" && !file)}>
                        {progress.phase === "uploading" ? __("Uploading…") : busy ? __("Checking…") : source === "file" ? __("Upload and check") : __("Create and check")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
