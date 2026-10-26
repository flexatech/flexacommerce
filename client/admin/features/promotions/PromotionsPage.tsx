import { __ } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { useUrlTab } from "../../components/useUrlTab";
import { BadgesPanel } from "../badges/BadgesPanel";
import { CouponsPanel } from "../coupons/CouponsPanel";
import { PriceRulesPanel } from "../pricing/PriceRulesPanel";

const TABS = ["coupons", "pricing", "badges"] as const;
type Tab = (typeof TABS)[number];

/** Coupons, automatic pricing rules and product badges: what the store does to sell more. */
export function PromotionsPage() {
    const [tab, setTab] = useUrlTab<Tab>(TABS);

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
                    ]}
                />
            </div>
            <div className="fxc:space-y-4">
                {tab === "coupons" && <CouponsPanel />}
                {tab === "pricing" && <PriceRulesPanel />}
                {tab === "badges" && <BadgesPanel />}
            </div>
        </div>
    );
}
