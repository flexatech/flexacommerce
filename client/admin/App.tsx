import { lazy, Suspense } from "react";

import { Toaster } from "@/shared/components/ui/sonner";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import type { AdminPage } from "@/shared/types/global";
import { Header } from "./components/Header";
import { AttributesPage } from "./features/attributes/AttributesPage";
import { CustomersPage } from "./features/customers/CustomersPage";
import { MigrationPage } from "./features/migration/MigrationPage";
import { OptionSetsPage } from "./features/options/OptionSetsPage";
import { OrdersPage } from "./features/orders/OrdersPage";
import { PromotionsPage } from "./features/promotions/PromotionsPage";
import { SettingsPage } from "./features/settings/SettingsPage";
import { StatusPage } from "./features/status/StatusPage";

// dnd-kit loads only on this screen.
const CheckoutBuilderPage = lazy(() => import("./features/checkout/CheckoutBuilderPage").then((m) => ({ default: m.CheckoutBuilderPage })));

export function App({ page }: { page: AdminPage }) {
    return (
        <div className="fxc:bg-background fxc:min-h-screen">
            <Header page={page} />
            <main className={cn("fxc:mx-auto fxc:px-6 fxc:py-6", page === "flexacommerce-checkout" ? "fxc:max-w-7xl" : "fxc:max-w-5xl")}>
                <Toaster />
                {page === "flexacommerce-orders" ? (
                    <OrdersPage />
                ) : page === "flexacommerce-customers" ? (
                    <CustomersPage />
                ) : page === "flexacommerce-promotions" ? (
                    <PromotionsPage />
                ) : page === "flexacommerce-checkout" ? (
                    <Suspense fallback={<p className="fxc:text-muted-foreground">{__("Loading…")}</p>}>
                        <CheckoutBuilderPage />
                    </Suspense>
                ) : page === "flexacommerce-settings" ? (
                    <SettingsPage />
                ) : page === "flexacommerce-migration" ? (
                    <MigrationPage />
                ) : page === "flexacommerce-attributes" ? (
                    <AttributesPage />
                ) : page === "flexacommerce-product-options" ? (
                    <OptionSetsPage />
                ) : (
                    <StatusPage />
                )}
            </main>
        </div>
    );
}
