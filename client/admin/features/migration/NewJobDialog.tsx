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
import { __ } from "@/shared/lib/i18n";
import { DIRECTION_LABELS, STAGE_LABELS } from "./format";
import type { Direction, JobOptions, MigrationsIndex, Stage } from "./types";
import { useCreateJob } from "./useMigrations";

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

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    index: MigrationsIndex;
    onCreated: (id: number) => void;
}

export function NewJobDialog({ open, onOpenChange, index, onCreated }: Props) {
    const create = useCreateJob();
    const firstJob = index.jobs.length === 0;
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

    const submit = () =>
        create.mutate(
            {
                direction,
                dry_run: dryRun,
                options: {
                    stages,
                    target_modified: targetModified,
                    duplicate_sku: duplicateSku,
                    order_number_conflict: numberConflict,
                    orders_from: from !== "" ? `${from}T00:00:00Z` : null,
                    orders_to: to !== "" ? `${to}T23:59:59Z` : null,
                },
            },
            {
                onSuccess: (job) => {
                    onOpenChange(false);
                    onCreated(job.id);
                },
            },
        );

    return (
        <Dialog open={open} onOpenChange={(o) => !create.isPending && onOpenChange(o)}>
            <DialogContent className="fxc:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{__("New migration")}</DialogTitle>
                    <DialogDescription>
                        {__("The job is checked as soon as it is created. Nothing is copied until you start it.")}
                    </DialogDescription>
                </DialogHeader>

                <div className="fxc:space-y-5">
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

                    {stages.includes("orders") && (
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

                    {create.isError && <p className="fxc:text-destructive fxc:text-sm">{create.error.message}</p>}
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={create.isPending}>
                        {__("Cancel")}
                    </Button>
                    <Button onClick={submit} disabled={create.isPending || stages.length === 0}>
                        {create.isPending ? __("Checking…") : __("Create and check")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
