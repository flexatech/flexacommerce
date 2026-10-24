import { useState } from "react";
import { Search, Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import type { LogLevel } from "./types";
import { LOGS_PER_PAGE, useClearLogs, useLogs } from "./useStatus";

const LEVEL_TONE: Record<LogLevel, "muted" | "brand" | "destructive"> = {
    debug: "muted",
    info: "brand",
    notice: "brand",
    warning: "destructive",
    error: "destructive",
    critical: "destructive",
};

const LEVELS: { value: LogLevel | ""; label: string }[] = [
    { value: "", label: __("All levels") },
    { value: "debug", label: __("Debug") },
    { value: "info", label: __("Info") },
    { value: "notice", label: __("Notice") },
    { value: "warning", label: __("Warning") },
    { value: "error", label: __("Error") },
    { value: "critical", label: __("Critical") },
];

export function LogsPanel() {
    const [level, setLevel] = useState<LogLevel | "">("");
    const [channel, setChannel] = useState("");
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const debouncedSearch = useDebounced(search);

    const logs = useLogs({ level, channel, search: debouncedSearch, page });
    const clear = useClearLogs();

    const total = logs.data?.total ?? 0;
    const pages = Math.max(1, Math.ceil(total / LOGS_PER_PAGE));
    const channels = logs.data?.channels ?? [];

    const resetPage = <T,>(set: (v: T) => void) => (value: T) => {
        set(value);
        setPage(1);
    };

    const clearAll = () =>
        clear.mutate(undefined, {
            onSuccess: () => {
                setConfirmOpen(false);
                setPage(1);
                showToast.success(__("Logs cleared."));
            },
            onError: (error) => showToast.error(error.message),
        });

    return (
        <div className="fxc:space-y-4">
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3">
                <Select
                    aria-label={__("Level")}
                    value={level}
                    onChange={(e) => resetPage(setLevel)(e.target.value as LogLevel | "")}
                    options={LEVELS}
                />
                <Select
                    aria-label={__("Channel")}
                    value={channel}
                    onChange={(e) => resetPage(setChannel)(e.target.value)}
                    options={[{ value: "", label: __("All channels") }, ...channels.map((c) => ({ value: c, label: c }))]}
                />
                <div className="fxc:relative fxc:w-64">
                    <Search aria-hidden className="fxc:text-muted-foreground fxc:pointer-events-none fxc:absolute fxc:top-2.5 fxc:left-3 fxc:size-4" />
                    <Input
                        aria-label={__("Search messages")}
                        placeholder={__("Search messages")}
                        value={search}
                        onChange={(e) => resetPage(setSearch)(e.target.value)}
                        className="fxc:pl-9!"
                    />
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    className="fxc:ml-auto"
                    onClick={() => setConfirmOpen(true)}
                    disabled={total === 0}
                >
                    <Trash2 aria-hidden />
                    {__("Clear logs")}
                </Button>
            </div>

            <Card>
                {logs.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : logs.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{logs.error.message}</p>
                ) : logs.data.items.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-8 fxc:text-center">{__("No log entries match.")}</p>
                ) : (
                    <ul>
                        {logs.data.items.map((entry) => (
                            <li key={entry.id} className="fxc:border-b fxc:px-5 fxc:py-3 fxc:last:border-b-0">
                                <div className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-xs">
                                    <Badge variant={LEVEL_TONE[entry.level]}>{entry.level}</Badge>
                                    <span className="fxc:text-muted-foreground fxc:font-mono">{entry.channel}</span>
                                    <time className="fxc:text-muted-foreground fxc:ml-auto fxc:tabular-nums" dateTime={entry.created_at}>
                                        {entry.created_at} UTC
                                    </time>
                                </div>
                                <p className="fxc:mt-1 fxc:break-words">{entry.message}</p>
                                {entry.context !== null && Object.keys(entry.context).length > 0 && (
                                    <details className="fxc:mt-1">
                                        <summary className="fxc:text-muted-foreground fxc:cursor-pointer fxc:text-xs">{__("Context")}</summary>
                                        <pre className="fxc:bg-muted fxc:mt-1 fxc:overflow-x-auto fxc:rounded-md fxc:p-2 fxc:text-xs">
                                            {JSON.stringify(entry.context, null, 2)}
                                        </pre>
                                    </details>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {pages > 1 && (
                <div className="fxc:flex fxc:items-center fxc:justify-end fxc:gap-3 fxc:text-sm">
                    <span className="fxc:text-muted-foreground">{sprintf(__("Page %1$d of %2$d"), page, pages)}</span>
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        {__("Previous")}
                    </Button>
                    <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
                        {__("Next")}
                    </Button>
                </div>
            )}

            <Dialog open={confirmOpen} onOpenChange={(open) => !clear.isPending && setConfirmOpen(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{__("Clear all logs?")}</DialogTitle>
                        <DialogDescription>{__("Every log entry on this site will be deleted. This cannot be undone.")}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setConfirmOpen(false)} disabled={clear.isPending}>
                            {__("Cancel")}
                        </Button>
                        <Button variant="destructive" onClick={clearAll} disabled={clear.isPending}>
                            {clear.isPending ? __("Clearing…") : __("Clear logs")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
