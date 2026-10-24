import type { UseQueryResult } from "@tanstack/react-query";
import { CheckCircle2, CircleAlert, CircleX } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import type { DoctorStatus, StatusPayload } from "./types";

const ICONS: Record<DoctorStatus, typeof CheckCircle2> = {
    ok: CheckCircle2,
    warning: CircleAlert,
    error: CircleX,
};

const TONES: Record<DoctorStatus, string> = {
    ok: "fxc:text-success",
    warning: "fxc:text-warning",
    error: "fxc:text-destructive",
};

function CheckRow({ status, label, detail }: { status: DoctorStatus; label: string; detail: string }) {
    const Icon = ICONS[status];
    return (
        <li className="fxc:flex fxc:items-start fxc:gap-3 fxc:border-b fxc:px-5 fxc:py-3 fxc:last:border-b-0">
            <Icon aria-hidden className={cn("fxc:mt-0.5 fxc:size-4 fxc:shrink-0", TONES[status])} />
            <div className="fxc:min-w-0">
                <p className="fxc:font-medium">{label}</p>
                {detail && <p className="fxc:text-muted-foreground fxc:text-xs">{detail}</p>}
            </div>
        </li>
    );
}

function Facts({ rows }: { rows: [string, string][] }) {
    return (
        <dl className="fxc:grid fxc:grid-cols-[max-content_1fr] fxc:gap-x-6 fxc:gap-y-2 fxc:px-5 fxc:py-4">
            {rows.map(([term, value]) => (
                <div key={term} className="fxc:contents">
                    <dt className="fxc:text-muted-foreground">{term}</dt>
                    <dd className="fxc:m-0 fxc:font-mono fxc:text-xs fxc:leading-5">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function OverviewPanel({ query }: { query: UseQueryResult<StatusPayload> }) {
    if (query.isPending) {
        return <p className="fxc:text-muted-foreground">{__("Running checks…")}</p>;
    }
    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }

    const { environment, database, requirements, doctor } = query.data;
    const yes = __("Yes");
    const no = __("No");

    return (
        <div className="fxc:grid fxc:gap-6 fxc:md:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{__("Health checks")}</CardTitle>
                    <CardDescription>{__("The same checks as the wp flexacommerce doctor command.")}</CardDescription>
                </CardHeader>
                <ul>
                    {doctor.map((check) => (
                        <CheckRow key={check.id} status={check.status} label={check.label} detail={check.detail} />
                    ))}
                </ul>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Requirements")}</CardTitle>
                </CardHeader>
                <ul>
                    {requirements.map((check) => (
                        <CheckRow key={check.id} status={check.ok ? "ok" : "error"} label={check.label} detail={check.detail} />
                    ))}
                </ul>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Environment")}</CardTitle>
                </CardHeader>
                <Facts
                    rows={[
                        [__("Plugin version"), environment.plugin_version],
                        [__("Extension API"), environment.api_version],
                        [__("WordPress"), environment.wp_version],
                        [__("PHP"), environment.php_version],
                        [__("Database server"), environment.db_server],
                        [__("Multisite"), environment.multisite ? yes : no],
                        [__("WooCommerce active"), environment.woocommerce ? yes : no],
                    ]}
                />
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Database")}</CardTitle>
                    <CardDescription>
                        {sprintf(
                            __("Schema version %1$d installed, %2$d expected."),
                            database.installed_version,
                            database.code_version,
                        )}
                    </CardDescription>
                </CardHeader>
                <CardContent className="fxc:p-0">
                    <table className="fxc:w-full fxc:text-left fxc:text-xs">
                        <thead className="fxc:text-muted-foreground">
                            <tr className="fxc:border-b">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Table")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Engine")}</th>
                                <th className="fxc:px-5 fxc:py-2 fxc:text-right fxc:font-medium">{__("Rows (approx.)")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {database.tables.map((table) => (
                                <tr key={table.name} className="fxc:border-b fxc:last:border-b-0">
                                    <td className="fxc:px-5 fxc:py-2 fxc:font-mono">{table.name}</td>
                                    <td className="fxc:px-3 fxc:py-2">
                                        {table.exists ? (
                                            table.engine
                                        ) : (
                                            <Badge variant="destructive">{__("Missing")}</Badge>
                                        )}
                                    </td>
                                    <td className="fxc:px-5 fxc:py-2 fxc:text-right fxc:tabular-nums">{table.exists ? table.rows : ""}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    );
}
