import { RefreshCw } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { __ } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { useUiStore, type StatusTab } from "../../store";
import { LogsPanel } from "./LogsPanel";
import { OverviewPanel } from "./OverviewPanel";
import { useStatus } from "./useStatus";

export function StatusPage() {
    const tab = useUiStore((s) => s.statusTab);
    const setTab = useUiStore((s) => s.setStatusTab);
    const status = useStatus();

    return (
        <div>
            <PageTitle
                title={__("Status")}
                description={__("Environment, database health and the plugin log.")}
                actions={
                    tab === "overview" && (
                        <Button variant="outline" size="sm" onClick={() => void status.refetch()} disabled={status.isFetching}>
                            <RefreshCw aria-hidden className={status.isFetching ? "fxc:animate-spin" : undefined} />
                            {__("Run checks again")}
                        </Button>
                    )
                }
            />
            <Tabs<StatusTab>
                value={tab}
                onChange={setTab}
                items={[
                    { value: "overview", label: __("Overview") },
                    { value: "logs", label: __("Logs") },
                ]}
            />
            <div className="fxc:mt-6">{tab === "overview" ? <OverviewPanel query={status} /> : <LogsPanel />}</div>
        </div>
    );
}
