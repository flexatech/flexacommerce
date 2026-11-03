import { lazy, Suspense } from "react";

import { Toaster } from "@/shared/components/ui/sonner";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import type { AdminPage } from "@/shared/types/global";
import { AttributesPage } from "./features/attributes/AttributesPage";
import { CustomersPage } from "./features/customers/CustomersPage";
import { MigrationPage } from "./features/migration/MigrationPage";
import { OptionSetsPage } from "./features/options/OptionSetsPage";
import { OrdersPage } from "./features/orders/OrdersPage";
import { ProductCsvPage } from "./features/productcsv/ProductCsvPage";
import { PromotionsPage } from "./features/promotions/PromotionsPage";
import { RecoveryPage } from "./features/recovery/RecoveryPage";
import { GiftCardsPage } from "./features/giftcards/GiftCardsPage";
import { SubscriptionsPage } from "./features/subscriptions/SubscriptionsPage";
import { StockAlertsPage } from "./features/stockalerts/StockAlertsPage";
import { SettingsPage } from "./features/settings/SettingsPage";
import { StatusPage } from "./features/status/StatusPage";

// dnd-kit loads only on this screen.
const CheckoutBuilderPage = lazy(() => import("./features/checkout/CheckoutBuilderPage").then((m) => ({ default: m.CheckoutBuilderPage })));
const EmailDesignPage = lazy(() => import("./features/emails/EmailDesignPage").then((m) => ({ default: m.EmailDesignPage })));

const WIDE: AdminPage[] = ["flexacommerce-checkout", "flexacommerce-email-design"];

export function App({ page }: { page: AdminPage }) {
    return (
        <div className="fxc:min-h-screen">
            <main className={cn("fxc:mx-auto fxc:px-6 fxc:py-6", WIDE.includes(page) ? "fxc:max-w-7xl" : "fxc:max-w-5xl")}>
                <Toaster />
                {page === "flexacommerce-orders" ? (
                    <OrdersPage />
                ) : page === "flexacommerce-subscriptions" ? (
                    <SubscriptionsPage />
                ) : page === "flexacommerce-customers" ? (
                    <CustomersPage />
                ) : page === "flexacommerce-recovery" ? (
                    <RecoveryPage />
                ) : page === "flexacommerce-stock-alerts" ? (
                    <StockAlertsPage />
                ) : page === "flexacommerce-gift-cards" ? (
                    <GiftCardsPage />
                ) : page === "flexacommerce-promotions" ? (
                    <PromotionsPage />
                ) : page === "flexacommerce-checkout" ? (
                    <Suspense fallback={<p className="fxc:text-muted-foreground">{__("Loading…")}</p>}>
                        <CheckoutBuilderPage />
                    </Suspense>
                ) : page === "flexacommerce-email-design" ? (
                    <Suspense fallback={<p className="fxc:text-muted-foreground">{__("Loading…")}</p>}>
                        <EmailDesignPage />
                    </Suspense>
                ) : page === "flexacommerce-settings" ? (
                    <SettingsPage />
                ) : page === "flexacommerce-migration" ? (
                    <MigrationPage />
                ) : page === "flexacommerce-attributes" ? (
                    <AttributesPage />
                ) : page === "flexacommerce-product-options" ? (
                    <OptionSetsPage />
                ) : page === "flexacommerce-product-csv" ? (
                    <ProductCsvPage />
                ) : (
                    <StatusPage />
                )}
            </main>
        </div>
    );
}
