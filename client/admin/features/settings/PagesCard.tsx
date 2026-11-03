import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CreditCard, ExternalLink, FileText, Heart, PackageSearch, Pencil, ShoppingCart, Store, UserRound, type LucideIcon } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { api } from "@/shared/lib/api";

type PageKey = "cart" | "checkout" | "account" | "terms" | "wishlist" | "tracking";
type Problem = "" | "missing" | "trash" | "unpublished" | "content";

interface StorePage {
    key: PageKey;
    label: string;
    required: boolean;
    /** Kept in a setting, so another page can be picked; the optional pages are found by their shortcode or block. */
    assignable: boolean;
    problem: Problem;
    message: string;
    page: { id: number; title: string; status: string; view_url: string; edit_url: string } | null;
}

type ShopFilters = "off" | "left" | "right";

/** The shop list is the product archive, not a page (ADR-084). */
interface ShopInfo {
    view_url: string;
    filters: ShopFilters;
    /** Why a set sidebar does not show: an edited Site Editor copy, the theme's own template, or an old classic theme copy. */
    hidden_by: "" | "custom" | "theme" | "classic";
    template_url: string;
    /** A published page at the shop's address, which shoppers cannot reach. */
    page: { id: number; title: string; edit_url: string } | null;
}

interface PagesPayload {
    pages: StorePage[];
    choices: { id: number; title: string; status: string }[];
    shop: ShopInfo;
    fixed?: PageKey[];
}

const PAGES_KEY = ["pages"] as const;

const ICONS: Record<PageKey, LucideIcon> = {
    cart: ShoppingCart,
    checkout: CreditCard,
    account: UserRound,
    terms: FileText,
    wishlist: Heart,
    tracking: PackageSearch,
};

const HELP: Record<PageKey, string> = {
    cart: __("Shows the cart. Holds the Cart block."),
    checkout: __("Takes the order and shows the order received page. Holds the Checkout block."),
    account: __("Orders, addresses, downloads and subscriptions. Holds the My account block."),
    terms: __("When set, checkout asks shoppers to accept it."),
    wishlist: __("Optional: the list also shows under My account. Holds the [flexacommerce_wishlist] shortcode."),
    tracking: __("Optional: guests look up an order by number and email. Holds the Order lookup block."),
};

const FIX_LABEL: Record<Exclude<Problem, "">, string> = {
    missing: __("Create page"),
    trash: __("Restore"),
    unpublished: __("Publish"),
    content: __("Add the block"),
};

const isGone = (page: StorePage) => page.required && (page.problem === "missing" || page.problem === "trash");

/**
 * Settings > Storefront: the store's pages (ADR-083). Each one can be
 * created, restored or repaired; cart, checkout, account and terms can point
 * at any page. Changes save at once, apart from the panel's Save button.
 */
export function PagesCard() {
    const qc = useQueryClient();
    const query = useQuery({ queryKey: PAGES_KEY, queryFn: () => api.get<PagesPayload>("admin/pages") });
    const done = (next: PagesPayload) => {
        qc.setQueryData(PAGES_KEY, next);
        void qc.invalidateQueries({ queryKey: ["settings"] });
    };
    const fix = useMutation({
        mutationFn: (keys?: PageKey[]) => api.post<PagesPayload>("admin/pages/fix", keys ? { keys } : {}),
        onSuccess: (next) => {
            done(next);
            const count = next.fixed?.length ?? 0;
            if (count === 0) {
                showToast.error(__("Nothing was changed."));
            } else {
                showToast.success(count === 1 ? __("Page updated.") : sprintf(__("%d pages updated."), count));
            }
        },
        onError: (error) => showToast.error(error.message),
    });
    const assign = useMutation({
        mutationFn: (input: { key: PageKey; page_id: number }) => api.post<PagesPayload>("admin/pages/assign", input),
        onSuccess: (next) => {
            done(next);
            showToast.success(__("Page saved."));
        },
        onError: (error) => showToast.error(error.message),
    });
    const shop = useMutation({
        mutationFn: (filters: ShopFilters) => api.post<PagesPayload>("admin/pages/shop", { filters }),
        onSuccess: (next) => {
            done(next);
            showToast.success(next.shop.filters === "off" ? __("Shop filters removed.") : __("Shop filters added."));
        },
        onError: (error) => showToast.error(error.message),
    });
    const busy = fix.isPending || assign.isPending || shop.isPending;

    const pages = query.data?.pages ?? [];
    const gone = pages.filter(isGone);

    return (
        <Card>
            <CardHeader className="fxc:flex fxc:flex-row fxc:flex-wrap fxc:items-start fxc:justify-between fxc:gap-3">
                <div className="fxc:min-w-0 fxc:flex-1 fxc:space-y-1.5">
                    <CardTitle>{__("Pages")}</CardTitle>
                    <CardDescription>
                        {__("The shop and the pages shoppers go through. Pick one of your own pages or let FlexaCommerce create it. A page you picked is never replaced.")}
                    </CardDescription>
                </div>
                {gone.length > 0 && (
                    <Button onClick={() => fix.mutate(undefined)} disabled={busy}>
                        {fix.isPending ? __("Creating…") : __("Create missing pages")}
                    </Button>
                )}
            </CardHeader>
            {query.isError && <p className="fxc:text-destructive fxc:px-5 fxc:pb-4 fxc:text-sm">{query.error.message}</p>}
            {query.isPending && <p className="fxc:text-muted-foreground fxc:px-5 fxc:pb-4 fxc:text-sm">{__("Loading pages…")}</p>}
            {query.data && <ShopRow shop={query.data.shop} busy={busy} onChange={(filters) => shop.mutate(filters)} />}
            {pages.map((page) => (
                <PageRow
                    key={page.key}
                    page={page}
                    choices={query.data?.choices ?? []}
                    busy={busy}
                    onFix={() => fix.mutate([page.key])}
                    onAssign={(id) => assign.mutate({ key: page.key, page_id: id })}
                />
            ))}
        </Card>
    );
}

function PageRow({
    page,
    choices,
    busy,
    onFix,
    onAssign,
}: {
    page: StorePage;
    choices: PagesPayload["choices"];
    busy: boolean;
    onFix: () => void;
    onAssign: (id: number) => void;
}) {
    const Icon = ICONS[page.key];
    const id = `fxc-page-${page.key}`;
    const current = page.page;
    const warn = page.problem !== "" && (page.required || current !== null);

    // The picked page stays in the list even when it is in the trash or past the first 100.
    const options = choices.map((choice) => ({
        value: String(choice.id),
        label: choice.status === "publish" ? choice.title : sprintf(__("%1$s (%2$s)"), choice.title, statusLabel(choice.status)),
    }));
    if (current && !choices.some((choice) => choice.id === current.id)) {
        options.unshift({ value: String(current.id), label: sprintf(__("%1$s (%2$s)"), current.title, statusLabel(current.status)) });
    }
    if (!current) {
        options.unshift({ value: "0", label: page.key === "terms" ? __("None (no terms checkbox)") : __("Choose a page") });
    } else if (page.key === "terms") {
        options.unshift({ value: "0", label: __("None (no terms checkbox)") });
    }

    return (
        <div role="group" aria-label={page.label} className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3 fxc:border-t fxc:px-5 fxc:py-4">
            <div className="fxc:bg-muted fxc:text-primary fxc:flex fxc:h-9 fxc:w-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-lg">
                <Icon aria-hidden className="fxc:h-4 fxc:w-4" />
            </div>
            <div className="fxc:min-w-48 fxc:flex-1">
                <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                    <Label htmlFor={page.assignable ? id : undefined} className="fxc:block">
                        {page.label}
                    </Label>
                    {page.problem === "" ? (
                        <Badge variant="success">{__("Working")}</Badge>
                    ) : warn ? (
                        <Badge variant="destructive">{__("Needs attention")}</Badge>
                    ) : (
                        <Badge>{page.required ? __("Required") : __("Not set up")}</Badge>
                    )}
                </div>
                <p className={cn("fxc:text-xs", warn ? "fxc:text-destructive" : "fxc:text-muted-foreground")}>
                    {warn ? page.message : HELP[page.key]}
                </p>
            </div>
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                {page.assignable ? (
                    <Select
                        id={id}
                        className="fxc:w-56"
                        value={String(current?.id ?? 0)}
                        disabled={busy}
                        onChange={(e) => onAssign(Number(e.target.value))}
                        options={options}
                    />
                ) : (
                    current && <span className="fxc:max-w-56 fxc:truncate fxc:text-sm">{current.title}</span>
                )}
                {page.problem !== "" && (
                    <Button variant="outline" size="sm" onClick={onFix} disabled={busy}>
                        {FIX_LABEL[page.problem]}
                    </Button>
                )}
                {current && current.status !== "trash" && (
                    <>
                        <Button variant="ghost" size="icon" asChild>
                            <a href={current.view_url} target="_blank" rel="noreferrer" aria-label={sprintf(__("View %s page"), page.label)} title={__("View")}>
                                <ExternalLink aria-hidden />
                            </a>
                        </Button>
                        {current.edit_url && (
                            <Button variant="ghost" size="icon" asChild>
                                <a href={current.edit_url} aria-label={sprintf(__("Edit %s page"), page.label)} title={__("Edit")}>
                                    <Pencil aria-hidden />
                                </a>
                            </Button>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

const HIDDEN: Record<Exclude<ShopInfo["hidden_by"], "">, string> = {
    custom: __("The shop template was edited in the Site Editor, so that copy shows instead. Reset it there, or add the Product filters block to it."),
    theme: __("Your theme has its own shop template, so the sidebar does not show. Add the Product filters block to it."),
    classic: __("Your theme has an older copy of flexacommerce/archive-product.php without the sidebar. Update it from the plugin's templates folder."),
};

/** The shop list: its address and the filter sidebar, with one click to add it. */
function ShopRow({ shop, busy, onChange }: { shop: ShopInfo; busy: boolean; onChange: (filters: ShopFilters) => void }) {
    const hidden = shop.filters !== "off" && shop.hidden_by !== "" ? HIDDEN[shop.hidden_by] : "";
    const warn = hidden !== "" || shop.page !== null;
    return (
        <div role="group" aria-label={__("Shop")} className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3 fxc:border-t fxc:px-5 fxc:py-4">
            <div className="fxc:bg-muted fxc:text-primary fxc:flex fxc:h-9 fxc:w-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-lg">
                <Store aria-hidden className="fxc:h-4 fxc:w-4" />
            </div>
            <div className="fxc:min-w-48 fxc:flex-1">
                <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                    <Label htmlFor="fxc-page-shop" className="fxc:block">
                        {__("Shop")}
                    </Label>
                    {warn ? (
                        <Badge variant="destructive">{__("Needs attention")}</Badge>
                    ) : shop.filters === "off" ? (
                        <Badge>{__("No filters")}</Badge>
                    ) : (
                        <Badge variant="success">{__("Filters on")}</Badge>
                    )}
                </div>
                <p className={cn("fxc:text-xs", warn ? "fxc:text-destructive" : "fxc:text-muted-foreground")}>
                    {shop.page !== null
                        ? sprintf(__("The page “%s” has the same address as the shop, so shoppers see the product list instead. Change its address or remove it."), shop.page.title)
                        : hidden !== ""
                          ? hidden
                          : __("Lists every product, and each category and tag. Filters by category, price, attributes and availability can sit beside the list.")}
                </p>
                {shop.filters !== "off" && shop.hidden_by === "custom" && shop.template_url && (
                    <a className="fxc:text-primary fxc:text-xs fxc:underline" href={shop.template_url}>
                        {__("Open the template in the Site Editor")}
                    </a>
                )}
            </div>
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                <Select
                    id="fxc-page-shop"
                    className="fxc:w-56"
                    value={shop.filters}
                    disabled={busy}
                    onChange={(e) => onChange(e.target.value as ShopFilters)}
                    options={[
                        { value: "off", label: __("No filters") },
                        { value: "left", label: __("Filters on the left") },
                        { value: "right", label: __("Filters on the right") },
                    ]}
                />
                {shop.filters === "off" && (
                    <Button variant="outline" size="sm" onClick={() => onChange("left")} disabled={busy}>
                        {__("Add filters")}
                    </Button>
                )}
                <Button variant="ghost" size="icon" asChild>
                    <a href={shop.view_url} target="_blank" rel="noreferrer" aria-label={__("View the shop")} title={__("View")}>
                        <ExternalLink aria-hidden />
                    </a>
                </Button>
                {shop.page !== null && shop.page.edit_url && (
                    <Button variant="ghost" size="icon" asChild>
                        <a href={shop.page.edit_url} aria-label={sprintf(__("Edit %s page"), shop.page.title)} title={__("Edit")}>
                            <Pencil aria-hidden />
                        </a>
                    </Button>
                )}
            </div>
        </div>
    );
}

function statusLabel(status: string): string {
    switch (status) {
        case "draft":
            return __("Draft");
        case "private":
            return __("Private");
        case "pending":
            return __("Pending");
        case "future":
            return __("Scheduled");
        case "trash":
            return __("In the trash");
        default:
            return status;
    }
}
