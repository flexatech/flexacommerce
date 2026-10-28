import { useState } from "react";

import { __ } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { useUrlTab } from "../../components/useUrlTab";
import { BadgesPanel } from "../badges/BadgesPanel";
import { CouponsPanel } from "../coupons/CouponsPanel";
import { PriceRulesPanel } from "../pricing/PriceRulesPanel";
import { SimulatorPanel } from "../simulator/SimulatorPanel";
import { TemplateGallery } from "./TemplateGallery";
import type { Template } from "./templates";

const TABS = ["coupons", "pricing", "badges", "simulator"] as const;
type Tab = (typeof TABS)[number];

/** Coupons, automatic pricing rules, product badges and a test cart to check them: what the store does to sell more. */
export function PromotionsPage() {
    const [tab, setTab] = useUrlTab<Tab>(TABS);
    const [gallery, setGallery] = useState(false);
    const [start, setStart] = useState<Template | null>(null);

    const pick = (template: Template) => {
        setGallery(false);
        setStart(template);
        setTab(template.kind === "rule" ? "pricing" : "coupons");
    };
    const templateProps = { template: start, onTemplates: () => setGallery(true), onTemplateUsed: () => setStart(null) };

    return (
        <div>
            <PageTitle title={__("Promotions")} description={__("Discount codes, automatic price rules and the badges shoppers see on products.")} />
            <div className="fxc:mb-6">
                <Tabs<Tab>
                    value={tab}
                    onChange={setTab}
                    items={[
                        { value: "coupons", label: __("Coupons") },
                        { value: "pricing", label: __("Pricing rules") },
                        { value: "badges", label: __("Badges") },
                        { value: "simulator", label: __("Simulator") },
                    ]}
                />
            </div>
            <div className="fxc:space-y-4">
                {tab === "coupons" && <CouponsPanel {...templateProps} />}
                {tab === "pricing" && <PriceRulesPanel {...templateProps} />}
                {tab === "badges" && <BadgesPanel />}
                {tab === "simulator" && <SimulatorPanel />}
            </div>
            <TemplateGallery open={gallery} onOpenChange={setGallery} onPick={pick} />
        </div>
    );
}
