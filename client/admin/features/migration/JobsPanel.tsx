import { useState } from "react";
import { Plus } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { __, sprintf } from "@/shared/lib/i18n";
import { DIRECTION_LABELS, STATUS_LABELS, STATUS_TONES } from "./format";
import { JobDetail } from "./JobDetail";
import { NewJobDialog } from "./NewJobDialog";
import { useMigrations } from "./useMigrations";

export function JobsPanel() {
    const index = useMigrations();
    const [selected, setSelected] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);

    if (selected !== null) {
        return <JobDetail key={selected} id={selected} onBack={() => setSelected(null)} />;
    }
    if (index.isPending) {
        return <p className="fxc:text-muted-foreground">{__("Loading…")}</p>;
    }
    if (index.isError) {
        return <p className="fxc:text-destructive">{index.error.message}</p>;
    }
    const { jobs } = index.data;

    return (
        <div className="fxc:space-y-4">
            <div className="fxc:flex fxc:items-center fxc:justify-between fxc:gap-4">
                <p className="fxc:text-muted-foreground fxc:text-sm">
                    {__("Copy the catalog, customers and orders between WooCommerce and FlexaCommerce. Re-run a job as often as you like: records already copied are updated, not duplicated.")}
                </p>
                <Button onClick={() => setCreating(true)}>
                    <Plus aria-hidden />
                    {__("New migration")}
                </Button>
            </div>

            <Card>
                {jobs.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-8 fxc:text-center">
                        {__("No migration yet. Start with a dry run to see what would be copied.")}
                    </p>
                ) : (
                    <table className="fxc:w-full fxc:text-left fxc:text-sm">
                        <thead className="fxc:text-muted-foreground fxc:text-xs">
                            <tr className="fxc:border-b">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Job")}</th>
                                <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Direction")}</th>
                                <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Status")}</th>
                                <th className="fxc:px-2 fxc:py-2 fxc:text-right fxc:font-medium">{__("Created / updated")}</th>
                                <th className="fxc:px-2 fxc:py-2 fxc:text-right fxc:font-medium">{__("Rejected")}</th>
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Created")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {jobs.map((job) => (
                                <tr key={job.id} className="fxc:hover:bg-muted/50 fxc:border-b fxc:last:border-b-0">
                                    <td className="fxc:px-5 fxc:py-2">
                                        <button
                                            type="button"
                                            onClick={() => setSelected(job.id)}
                                            className="fxc:text-primary fxc:cursor-pointer fxc:bg-transparent fxc:p-0 fxc:font-medium fxc:hover:underline fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent fxc:outline-none"
                                        >
                                            {sprintf(__("#%d"), job.id)}
                                        </button>
                                        {job.dry_run && <Badge className="fxc:ml-2">{__("Dry run")}</Badge>}
                                    </td>
                                    <td className="fxc:px-2 fxc:py-2">{DIRECTION_LABELS[job.direction]}</td>
                                    <td className="fxc:px-2 fxc:py-2">
                                        <Badge variant={STATUS_TONES[job.status]}>{STATUS_LABELS[job.status]}</Badge>
                                    </td>
                                    <td className="fxc:px-2 fxc:py-2 fxc:text-right fxc:tabular-nums">
                                        {(job.totals.created ?? 0) + (job.totals.updated ?? 0)}
                                    </td>
                                    <td className="fxc:px-2 fxc:py-2 fxc:text-right fxc:tabular-nums">{job.totals.rejected ?? 0}</td>
                                    <td className="fxc:text-muted-foreground fxc:px-5 fxc:py-2 fxc:text-xs fxc:tabular-nums">{job.created_at} UTC</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            {creating && <NewJobDialog open={creating} onOpenChange={setCreating} index={index.data} onCreated={setSelected} />}
        </div>
    );
}
