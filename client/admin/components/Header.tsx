import { Activity, Settings, Store, Tags } from "lucide-react";

import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import { getPluginGlobal } from "@/shared/lib/api";
import type { AdminPage } from "@/shared/types/global";

const NAV: { page: AdminPage; label: string; icon: typeof Activity }[] = [
    { page: "flexacommerce-attributes", label: __("Attributes"), icon: Tags },
    { page: "flexacommerce", label: __("Status"), icon: Activity },
    { page: "flexacommerce-settings", label: __("Settings"), icon: Settings },
];

export function Header({ page }: { page: AdminPage }) {
    const { adminUrl, version } = getPluginGlobal();

    return (
        <header className="fxc:bg-background fxc:sticky fxc:top-8 fxc:z-40 fxc:flex fxc:h-14 fxc:w-full fxc:items-center fxc:gap-6 fxc:border-b fxc:px-4">
            <div className="fxc:flex fxc:items-center fxc:gap-3 fxc:pr-4">
                <div className="fxc:bg-sidebar-primary fxc:text-sidebar-primary-foreground fxc:flex fxc:h-8 fxc:w-8 fxc:items-center fxc:justify-center fxc:rounded-lg">
                    <Store aria-hidden className="fxc:h-4 fxc:w-4" />
                </div>
                <div className="fxc:flex fxc:flex-col">
                    <span className="fxc:text-foreground fxc:text-sm fxc:font-semibold">FlexaCommerce</span>
                    <span className="fxc:text-muted-foreground fxc:text-[10px]">{version}</span>
                </div>
            </div>
            <nav className="fxc:flex fxc:h-full fxc:items-stretch fxc:gap-6">
                {NAV.map(({ page: target, label, icon: Icon }) => (
                    <a
                        key={target}
                        href={`${adminUrl}?page=${target}`}
                        aria-current={target === page ? "page" : undefined}
                        className={cn(
                            "fxc:text-foreground fxc:flex fxc:items-center fxc:gap-2 fxc:border-b-2 fxc:border-transparent fxc:text-sm fxc:font-semibold fxc:no-underline fxc:hover:text-primary fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent fxc:outline-none",
                            target === page && "fxc:text-primary fxc:border-primary",
                        )}
                    >
                        <Icon aria-hidden className="fxc:size-4" />
                        <span>{label}</span>
                    </a>
                ))}
            </nav>
        </header>
    );
}
