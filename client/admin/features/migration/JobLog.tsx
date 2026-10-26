import { useState } from "react";

import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Select } from "@/shared/components/ui/select";
import { __, sprintf } from "@/shared/lib/i18n";
import type { LogRow } from "./types";
import { LOG_PER_PAGE, useJobLog } from "./useMigrations";

const LEVEL_TONE = { info: "muted", warning: "brand", error: "destructive" } as const satisfies Record<LogRow["level"], string>;

export function JobLog({ id }: { id: number }) {
    const [level, setLevel] = useState("");
    const [page, setPage] = useState(1);
    const log = useJobLog(id, level, page);
    const pages = Math.max(1, Math.ceil((log.data?.total ?? 0) / LOG_PER_PAGE));

    return (
        <Card>
            <CardHeader className="fxc:flex-row fxc:items-center fxc:justify-between">
                <CardTitle>{__("Log")}</CardTitle>
                <Select
                    aria-label={__("Level")}
                    value={level}
                    onChange={(e) => {
                        setLevel(e.target.value);
                        setPage(1);
                    }}
                    options={[
                        { value: "", label: __("All levels") },
                        { value: "error", label: __("Errors") },
                        { value: "warning", label: __("Warnings") },
                        { value: "info", label: __("Info") },
                    ]}
                />
            </CardHeader>
            {log.isPending ? (
                <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
            ) : log.isError ? (
                <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{log.error.message}</p>
            ) : log.data.items.length === 0 ? (
                <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-8 fxc:text-center">{__("No log entries.")}</p>
            ) : (
                <ul>
                    {log.data.items.map((row) => (
                        <li key={row.id} className="fxc:border-b fxc:px-5 fxc:py-3 fxc:last:border-b-0">
                            <div className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-xs">
                                <Badge variant={LEVEL_TONE[row.level]}>{row.level}</Badge>
                                <span className="fxc:text-muted-foreground fxc:font-mono">
                                    {row.entity}
                                    {row.source_id !== "" && ` #${row.source_id}`} · {row.code}
                                </span>
                                <time className="fxc:text-muted-foreground fxc:ml-auto fxc:tabular-nums" dateTime={row.created_at}>
                                    {row.created_at} UTC
                                </time>
                            </div>
                            <p className="fxc:mt-1 fxc:break-words">{row.message}</p>
                            {Object.keys(row.context).length > 0 && (
                                <details className="fxc:mt-1">
                                    <summary className="fxc:text-muted-foreground fxc:cursor-pointer fxc:text-xs">{__("Context")}</summary>
                                    <pre className="fxc:bg-muted fxc:mt-1 fxc:overflow-x-auto fxc:rounded-md fxc:p-2 fxc:text-xs">
                                        {JSON.stringify(row.context, null, 2)}
                                    </pre>
                                </details>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {pages > 1 && (
                <div className="fxc:flex fxc:items-center fxc:justify-end fxc:gap-3 fxc:border-t fxc:px-5 fxc:py-3 fxc:text-sm">
                    <span className="fxc:text-muted-foreground">{sprintf(__("Page %1$d of %2$d"), page, pages)}</span>
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        {__("Previous")}
                    </Button>
                    <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
                        {__("Next")}
                    </Button>
                </div>
            )}
        </Card>
    );
}
