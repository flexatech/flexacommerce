import { Toaster } from "@/shared/components/ui/sonner";
import type { AdminPage } from "@/shared/types/global";
import { Header } from "./components/Header";
import { AttributesPage } from "./features/attributes/AttributesPage";
import { SettingsPage } from "./features/settings/SettingsPage";
import { StatusPage } from "./features/status/StatusPage";

export function App({ page }: { page: AdminPage }) {
    return (
        <div className="fxc:bg-background fxc:min-h-screen">
            <Header page={page} />
            <main className="fxc:mx-auto fxc:max-w-5xl fxc:px-6 fxc:py-6">
                <Toaster />
                {page === "flexacommerce-settings" ? (
                    <SettingsPage />
                ) : page === "flexacommerce-attributes" ? (
                    <AttributesPage />
                ) : (
                    <StatusPage />
                )}
            </main>
        </div>
    );
}
