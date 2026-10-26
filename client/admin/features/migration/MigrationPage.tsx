import { __ } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { useUiStore, type MigrationTab } from "../../store";
import { CutoverPanel } from "./CutoverPanel";
import { JobsPanel } from "./JobsPanel";

export function MigrationPage() {
    const tab = useUiStore((s) => s.migrationTab);
    const setTab = useUiStore((s) => s.setMigrationTab);

    return (
        <div>
            <PageTitle title={__("Migration")} description={__("Move a store between WooCommerce and FlexaCommerce, then switch the live site.")} />
            <Tabs<MigrationTab>
                value={tab}
                onChange={setTab}
                items={[
                    { value: "jobs", label: __("Jobs") },
                    { value: "cutover", label: __("Cutover") },
                ]}
            />
            <div className="fxc:mt-6">{tab === "jobs" ? <JobsPanel /> : <CutoverPanel />}</div>
        </div>
    );
}
