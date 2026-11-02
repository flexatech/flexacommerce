import { useEffect, useRef } from "react";
import { ExternalLink, Megaphone, Package, Receipt, Search, Settings, Store, Users } from "lucide-react";

import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import { getPluginGlobal } from "@/shared/lib/api";
import type { NavSection } from "@/shared/types/global";

function isMac(): boolean {
    return /mac|iphone|ipad/i.test(navigator.platform || navigator.userAgent);
}

/** Core's command palette (WP 6.9+). Admin\Menu::commands adds every FlexaCommerce page and setting tab to it. */
function openPalette(): void {
    const wp = (window as unknown as { wp?: { data?: { dispatch: (store: string) => { open?: () => void } } } }).wp;
    wp?.data?.dispatch("core/commands").open?.();
}

const ICONS: Record<NavSection["id"], typeof Store> = {
    orders: Receipt,
    products: Package,
    customers: Users,
    marketing: Megaphone,
    settings: Settings,
};

/**
 * The app header (ADR-082): the five menu sections, the open section's
 * pages as tabs, and a button for the command palette (Ctrl or Cmd + K).
 */
export function Header() {
    const { version, nav } = getPluginGlobal();
    const sections = nav?.sections ?? [];
    const [section, slug] = nav?.current ?? ["", ""];
    const open = sections.find((s) => s.id === section);
    const tabs = useRef<HTMLElement>(null);

    // On a narrow screen the tabs scroll; the open one starts in view.
    useEffect(() => {
        tabs.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: "nearest", inline: "nearest" });
    }, []);

    return (
        <header className="fxc:bg-background/95 fxc:sticky fxc:top-8 fxc:z-40 fxc:w-full fxc:border-b fxc:backdrop-blur">
            <div className="fxc:flex fxc:h-14 fxc:items-center fxc:gap-4 fxc:px-4">
                <div className="fxc:flex fxc:shrink-0 fxc:items-center fxc:gap-3 fxc:pr-2">
                    <div className="fxc:bg-sidebar-primary fxc:text-sidebar-primary-foreground fxc:flex fxc:size-8 fxc:items-center fxc:justify-center fxc:rounded-lg fxc:shadow-sm">
                        <Store aria-hidden className="fxc:size-4" />
                    </div>
                    <div className="fxc:hidden fxc:flex-col fxc:lg:flex">
                        <span className="fxc:text-foreground fxc:text-sm fxc:font-semibold fxc:leading-tight">FlexaCommerce</span>
                        <span className="fxc:text-muted-foreground fxc:text-[10px] fxc:leading-tight">{version}</span>
                    </div>
                </div>
                <nav aria-label={__("Store sections")} className="fxc:flex fxc:min-w-0 fxc:flex-1 fxc:items-center fxc:gap-1 fxc:overflow-x-auto fxc:[scrollbar-width:none]">
                    {sections.map((item) => {
                        const Icon = ICONS[item.id];
                        const active = item.id === section;
                        return (
                            <a
                                key={item.id}
                                href={item.url}
                                aria-current={active && item.pages.length === 1 ? "page" : active ? "true" : undefined}
                                className={cn(
                                    "fxc:text-muted-foreground fxc:flex fxc:h-9 fxc:shrink-0 fxc:items-center fxc:gap-2 fxc:rounded-md fxc:px-3 fxc:text-sm fxc:font-medium fxc:whitespace-nowrap fxc:no-underline fxc:transition-colors fxc:outline-none",
                                    "fxc:hover:bg-muted fxc:hover:text-foreground fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                                    active && "fxc:bg-primary/10 fxc:text-primary fxc:hover:bg-primary/10 fxc:hover:text-primary",
                                )}
                            >
                                <Icon aria-hidden className="fxc:size-4" />
                                <span>{item.label}</span>
                            </a>
                        );
                    })}
                </nav>
                <button
                    type="button"
                    onClick={openPalette}
                    className="fxc:border-input fxc:bg-muted/40 fxc:text-muted-foreground fxc:flex fxc:h-9 fxc:shrink-0 fxc:cursor-pointer fxc:items-center fxc:gap-2 fxc:rounded-md fxc:border fxc:px-2.5 fxc:text-sm fxc:outline-none fxc:transition-colors fxc:hover:bg-muted fxc:hover:text-foreground fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent fxc:md:w-56"
                >
                    <Search aria-hidden className="fxc:size-4" />
                    <span className="fxc:hidden fxc:flex-1 fxc:text-left fxc:md:inline">{__("Search pages…")}</span>
                    <kbd className="fxc:bg-background fxc:hidden fxc:rounded fxc:border fxc:px-1.5 fxc:font-sans fxc:text-[11px] fxc:font-medium fxc:md:inline">{isMac() ? "⌘K" : "Ctrl K"}</kbd>
                    <span className="fxc:sr-only fxc:md:hidden">{__("Search pages")}</span>
                </button>
                {nav?.storeUrl && (
                    <a
                        href={nav.storeUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="fxc:text-muted-foreground fxc:hidden fxc:h-9 fxc:shrink-0 fxc:items-center fxc:gap-1.5 fxc:rounded-md fxc:px-2.5 fxc:text-sm fxc:font-medium fxc:no-underline fxc:outline-none fxc:hover:bg-muted fxc:hover:text-foreground fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent fxc:xl:flex"
                    >
                        <ExternalLink aria-hidden className="fxc:size-4" />
                        {__("View store")}
                    </a>
                )}
            </div>
            {open && open.pages.length > 1 && (
                <nav ref={tabs} aria-label={open.label} className="fxc:flex fxc:h-10 fxc:items-stretch fxc:gap-5 fxc:overflow-x-auto fxc:px-5 fxc:[scrollbar-width:thin]">
                    {open.pages.map((page) => (
                        <a
                            key={page.slug}
                            href={page.url}
                            aria-current={page.slug === slug ? "page" : undefined}
                            className={cn(
                                "fxc:text-muted-foreground fxc:-mb-px fxc:flex fxc:shrink-0 fxc:items-center fxc:border-b-2 fxc:border-transparent fxc:text-sm fxc:font-medium fxc:whitespace-nowrap fxc:no-underline fxc:outline-none fxc:hover:text-foreground fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                                page.slug === slug && "fxc:border-primary fxc:text-foreground",
                            )}
                        >
                            {page.label}
                        </a>
                    ))}
                </nav>
            )}
        </header>
    );
}
