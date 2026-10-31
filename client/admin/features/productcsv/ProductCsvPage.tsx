import { useState } from "react";

import { __ } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { ExportPanel } from "./ExportPanel";
import { ImportPanel } from "./ImportPanel";
import { productsUrl } from "./useProductCsv";

type Tab = "import" | "export";

/** The product list links here with ?tab=import or ?tab=export. */
function initialTab(): Tab {
    return new URLSearchParams(window.location.search).get("tab") === "export" ? "export" : "import";
}

export function ProductCsvPage() {
    const [tab, setTab] = useState<Tab>(initialTab);

    const change = (next: Tab) => {
        setTab(next);
        const url = new URL(window.location.href);
        url.searchParams.set("tab", next);
        window.history.replaceState(null, "", url);
    };

    return (
        <div>
            <PageTitle
                title={__("Import and export products")}
                description={__("CSV files in the WooCommerce product format. A file exported from WooCommerce imports as it is.")}
                actions={
                    <a href={productsUrl()} className="fxc:text-primary fxc:text-sm fxc:font-medium">
                        {__("Back to products")}
                    </a>
                }
            />
            <Tabs<Tab>
                value={tab}
                onChange={change}
                items={[
                    { value: "import", label: __("Import") },
                    { value: "export", label: __("Export") },
                ]}
            />
            <div className="fxc:mt-6">{tab === "import" ? <ImportPanel /> : <ExportPanel />}</div>
        </div>
    );
}
