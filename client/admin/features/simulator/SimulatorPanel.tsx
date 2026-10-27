import { useMemo, useState } from "react";
import { Loader2, Minus, Plus, Trash2, UserRound, X } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { conditionText } from "../conditions/ConditionsEditor";
import { useConditionChoices } from "../conditions/useConditions";
import { useTargetSearch } from "../coupons/useCoupons";
import { SearchBox, type Row } from "./SearchBox";
import { MAX_LINES, useCustomerSearch, useSimulation, type RuleStatus, type RuleTrace, type SimCoupon, type SimInput } from "./useSimulator";

interface Line {
    type: "product" | "variant";
    id: number;
    label: string;
    quantity: number;
}

type Tone = "success" | "muted" | "brand" | "destructive";

const STATUS: Record<RuleStatus, { label: string; tone: Tone }> = {
    applied: { label: __("Applied"), tone: "success" },
    beaten: { label: __("Lower price elsewhere"), tone: "muted" },
    no_change: { label: __("No change"), tone: "muted" },
    no_tier: { label: __("Quantity too low"), tone: "muted" },
    off: { label: __("Off"), tone: "muted" },
    scheduled: { label: __("Not started"), tone: "muted" },
    ended: { label: __("Ended"), tone: "muted" },
    customer: { label: __("Other customers"), tone: "muted" },
    not_targeted: { label: __("Other products"), tone: "muted" },
    on_sale: { label: __("Skips sale items"), tone: "muted" },
    unmet: { label: __("Condition not met"), tone: "destructive" },
    waiting: { label: __("Waits for checkout"), tone: "brand" },
};

const COUPON_STATUS: Record<SimCoupon["status"], { label: string; tone: Tone }> = {
    applied: { label: __("Applied"), tone: "success" },
    rejected: { label: __("Not valid"), tone: "destructive" },
    waiting: { label: __("Waits for checkout"), tone: "brand" },
    skipped: { label: __("Skipped"), tone: "muted" },
};

/** Rules a merchant rarely needs to see on a line: they never had a chance. */
const QUIET: RuleStatus[] = ["off", "not_targeted"];

/**
 * The Simulator tab of Promotions (ADR-046): a test cart the merchant
 * makes up, priced by the same code as a shopper's cart and checkout, with
 * what every pricing rule, fee and coupon did and why. Nothing is saved.
 */
export function SimulatorPanel() {
    const choices = useConditionChoices();
    const [lines, setLines] = useState<Line[]>([]);
    const [customer, setCustomer] = useState<{ id: number; label: string } | null>(null);
    const [country, setCountry] = useState("");
    const [region, setRegion] = useState("");
    const [postcode, setPostcode] = useState("");
    const [rate, setRate] = useState("");
    const [method, setMethod] = useState("");
    const [codes, setCodes] = useState("");

    const input: SimInput = useMemo(
        () => ({
            lines: lines.map(({ type, id, quantity }) => ({ type, id, quantity })),
            customer_id: customer?.id ?? 0,
            country,
            state: region.trim(),
            postcode: postcode.trim(),
            shipping_rate: rate,
            payment_method: method,
            coupons: codes
                .split(",")
                .map((c) => c.trim())
                .filter((c) => c !== ""),
        }),
        [lines, customer, country, region, postcode, rate, method, codes],
    );
    const run = useSimulation(useDebounced(input, 300));
    const result = lines.length > 0 ? run.data : undefined;

    const add = (row: Row) => {
        const [type, id] = row.key.split(":");
        setLines((current) => {
            const found = current.find((l) => `${l.type}:${l.id}` === row.key);
            if (found) {
                return current.map((l) => (l === found ? { ...l, quantity: l.quantity + 1 } : l));
            }
            return current.length >= MAX_LINES ? current : [...current, { type: type as Line["type"], id: Number(id), label: row.label, quantity: 1 }];
        });
    };
    const setQuantity = (index: number, quantity: number) =>
        setLines((current) => current.map((l, i) => (i === index ? { ...l, quantity: Math.max(1, Math.min(9999, quantity || 1)) } : l)));

    return (
        <div className="fxc:space-y-4">
            <p className="fxc:text-muted-foreground fxc:max-w-2xl fxc:text-sm">
                {__("Build a test cart to see what your pricing rules, fees and coupons do to it, and why one did not apply. Prices come from the same code as the shop's cart and checkout. Nothing is saved and no customer sees it.")}
            </p>

            <div className="fxc:grid fxc:items-start fxc:gap-4 fxc:lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <Card>
                    <CardHeader>
                        <CardTitle>{__("Test cart")}</CardTitle>
                    </CardHeader>
                    <CardContent className="fxc:space-y-5">
                        <div className="fxc:space-y-2">
                            <ProductSearch onPick={add} disabled={lines.length >= MAX_LINES} />
                            {lines.length === 0 ? (
                                <p className="fxc:text-muted-foreground fxc:text-sm">{__("Search for products to add them.")}</p>
                            ) : (
                                <ul className="fxc:divide-border fxc:divide-y">
                                    {lines.map((line, i) => (
                                        <li key={`${line.type}:${line.id}`} className="fxc:flex fxc:items-center fxc:gap-2 fxc:py-2">
                                            <span className={cn("fxc:min-w-0 fxc:flex-1 fxc:truncate fxc:text-sm", line.type === "variant" && "fxc:ps-2")}>{line.label}</span>
                                            <Button variant="ghost" size="icon" aria-label={sprintf(__("One less %s"), line.label)} onClick={() => setQuantity(i, line.quantity - 1)} disabled={line.quantity <= 1}>
                                                <Minus aria-hidden className="fxc:size-4" />
                                            </Button>
                                            <Input
                                                type="number"
                                                min={1}
                                                className="fxc:w-16 fxc:text-center"
                                                aria-label={sprintf(__("Quantity of %s"), line.label)}
                                                value={line.quantity}
                                                onChange={(e) => setQuantity(i, Number(e.target.value))}
                                            />
                                            <Button variant="ghost" size="icon" aria-label={sprintf(__("One more %s"), line.label)} onClick={() => setQuantity(i, line.quantity + 1)}>
                                                <Plus aria-hidden className="fxc:size-4" />
                                            </Button>
                                            <Button variant="ghost" size="icon" aria-label={sprintf(__("Remove %s"), line.label)} onClick={() => setLines((current) => current.filter((_, j) => j !== i))}>
                                                <Trash2 aria-hidden className="fxc:size-4" />
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="fxc-sim-customer" className="fxc:mb-2 fxc:block">
                                {__("Customer")}
                            </Label>
                            {customer ? (
                                <div className="fxc:bg-muted fxc:flex fxc:items-center fxc:gap-2 fxc:rounded-md fxc:px-3 fxc:py-2 fxc:text-sm">
                                    <UserRound aria-hidden className="fxc:text-muted-foreground fxc:size-4" />
                                    <span className="fxc:min-w-0 fxc:flex-1 fxc:truncate">{customer.label}</span>
                                    <Button variant="ghost" size="icon" aria-label={__("Back to a guest")} onClick={() => setCustomer(null)}>
                                        <X aria-hidden className="fxc:size-4" />
                                    </Button>
                                </div>
                            ) : (
                                <CustomerSearch onPick={setCustomer} />
                            )}
                            <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                                {customer ? __("Their roles and past orders count, as when they shop.") : __("A guest: no roles and no past orders.")}
                            </p>
                        </div>

                        <div className="fxc:grid fxc:gap-3 fxc:sm:grid-cols-3">
                            <div className="fxc:sm:col-span-3">
                                <Label htmlFor="fxc-sim-country" className="fxc:mb-2 fxc:block">
                                    {__("Country")}
                                </Label>
                                <Select
                                    id="fxc-sim-country"
                                    className="fxc:w-full"
                                    value={country}
                                    onChange={(e) => {
                                        setCountry(e.target.value);
                                        setRate("");
                                    }}
                                    options={[{ value: "", label: __("Not chosen yet") }, ...(choices.data?.countries ?? []).map((c) => ({ value: c.id, label: c.title }))]}
                                />
                            </div>
                            <div className="fxc:sm:col-span-2">
                                <Label htmlFor="fxc-sim-state" className="fxc:mb-2 fxc:block">
                                    {__("State code")}
                                </Label>
                                <Input id="fxc-sim-state" value={region} onChange={(e) => setRegion(e.target.value)} placeholder={__("For example CA")} />
                            </div>
                            <div>
                                <Label htmlFor="fxc-sim-postcode" className="fxc:mb-2 fxc:block">
                                    {__("Postcode")}
                                </Label>
                                <Input id="fxc-sim-postcode" value={postcode} onChange={(e) => setPostcode(e.target.value)} />
                            </div>
                        </div>

                        <div className="fxc:grid fxc:gap-3 fxc:sm:grid-cols-2">
                            <div>
                                <Label htmlFor="fxc-sim-rate" className="fxc:mb-2 fxc:block">
                                    {__("Shipping")}
                                </Label>
                                <Select
                                    id="fxc-sim-rate"
                                    className="fxc:w-full"
                                    value={rate}
                                    onChange={(e) => setRate(e.target.value)}
                                    options={[
                                        { value: "", label: __("As checkout picks") },
                                        ...(result?.shipping_rates ?? []).map((r) => ({ value: r.id, label: `${r.label} (${r.cost.formatted})` })),
                                    ]}
                                />
                            </div>
                            <div>
                                <Label htmlFor="fxc-sim-method" className="fxc:mb-2 fxc:block">
                                    {__("Payment method")}
                                </Label>
                                <Select
                                    id="fxc-sim-method"
                                    className="fxc:w-full"
                                    value={method}
                                    onChange={(e) => setMethod(e.target.value)}
                                    options={[{ value: "", label: __("As checkout picks") }, ...(choices.data?.payment_methods ?? []).map((c) => ({ value: c.id, label: c.title }))]}
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="fxc-sim-coupons" className="fxc:mb-2 fxc:block">
                                {__("Coupon codes")}
                            </Label>
                            <Input id="fxc-sim-coupons" value={codes} onChange={(e) => setCodes(e.target.value)} placeholder={__("Separated by commas")} />
                            <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{__("Automatic discounts are tried on their own.")}</p>
                        </div>
                    </CardContent>
                </Card>

                <div className="fxc:space-y-4">
                    {lines.length === 0 ? (
                        <Card>
                            <CardContent className="fxc:text-muted-foreground fxc:py-8 fxc:text-center fxc:text-sm">{__("Add a product to the test cart to see the result.")}</CardContent>
                        </Card>
                    ) : run.isError ? (
                        <Card>
                            <CardContent className="fxc:text-destructive fxc:text-sm">{run.error.message}</CardContent>
                        </Card>
                    ) : !result ? (
                        <Card>
                            <CardContent className="fxc:text-muted-foreground fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                                <Loader2 aria-hidden className="fxc:size-4 fxc:animate-spin" />
                                {__("Working it out…")}
                            </CardContent>
                        </Card>
                    ) : (
                        <Results result={result} fetching={run.isFetching} choices={choices.data} />
                    )}
                </div>
            </div>
        </div>
    );
}

function ProductSearch({ onPick, disabled }: { onPick: (row: Row) => void; disabled: boolean }) {
    const [text, setText] = useState("");
    const [open, setOpen] = useState(false);
    const search = useDebounced(text.trim(), 250);
    const results = useTargetSearch("product", search, open && !disabled);
    const rows: Row[] = (results.data ?? []).map((t) => ({ key: `${t.type}:${t.id}`, label: t.label, hint: t.sku, indent: t.type === "variant" }));
    return (
        <SearchBox
            id="fxc-sim-products"
            label={__("Add a product")}
            placeholder={disabled ? sprintf(__("At most %d lines"), MAX_LINES) : __("Add a product by name or SKU")}
            text={text}
            onText={setText}
            rows={rows}
            fetching={results.isFetching}
            onPick={onPick}
            open={open && !disabled}
            onOpen={setOpen}
        />
    );
}

function CustomerSearch({ onPick }: { onPick: (customer: { id: number; label: string }) => void }) {
    const [text, setText] = useState("");
    const [open, setOpen] = useState(false);
    const search = useDebounced(text.trim(), 250);
    const results = useCustomerSearch(search, open);
    const rows: Row[] = (results.data ?? []).map((c) => ({ key: String(c.id), label: `${c.name} (${c.email})`, hint: c.roles.join(", ") }));
    return (
        <SearchBox
            id="fxc-sim-customer"
            label={__("Customer")}
            placeholder={__("Guest. Search for an account to use instead")}
            text={text}
            onText={setText}
            rows={rows}
            fetching={results.isFetching}
            onPick={(row) => onPick({ id: Number(row.key), label: row.hint !== "" ? `${row.label}: ${row.hint}` : row.label })}
            open={open}
            onOpen={setOpen}
        />
    );
}

type Choices = ReturnType<typeof useConditionChoices>["data"];

/** Why a rule did what it did, in a sentence. */
function ruleDetail(rule: RuleTrace, choices: Choices, fee: boolean): string {
    switch (rule.status) {
        case "applied":
            return fee ? sprintf(__("Adds %s."), rule.amount?.formatted ?? "") : sprintf(__("Line total %s."), rule.amount?.formatted ?? "");
        case "beaten":
            return sprintf(__("Would make the line %s; another rule gives a lower price."), rule.amount?.formatted ?? "");
        case "no_change":
            return __("Would not lower the price.");
        case "no_tier":
            return __("The quantity reaches no tier.");
        case "off":
            return __("The rule is turned off.");
        case "scheduled":
            return __("Its start date has not come yet.");
        case "ended":
            return __("Its end date has passed.");
        case "customer":
            return __("It is for other customers.");
        case "not_targeted":
            return fee ? __("No product in the cart is one it charges for.") : __("It does not include this product.");
        case "on_sale":
            return __("The product is on sale and the rule skips sale items.");
        case "unmet":
            return rule.condition ? sprintf(__("Needs: %s."), conditionText(rule.condition, choices)) : __("A condition is not met.");
        case "waiting":
            return rule.condition ? sprintf(__("Decided at checkout: %s."), conditionText(rule.condition, choices)) : __("Decided at checkout.");
    }
}

function RuleList({ rules, choices, fee = false }: { rules: RuleTrace[]; choices: Choices; fee?: boolean }) {
    const [all, setAll] = useState(false);
    const shown = all ? rules : rules.filter((r) => !QUIET.includes(r.status));
    const hidden = rules.length - shown.length;
    if (rules.length === 0) {
        return <p className="fxc:text-muted-foreground fxc:text-sm">{fee ? __("No fee rules.") : __("No pricing rules.")}</p>;
    }
    return (
        <div className="fxc:space-y-1">
            <ul className="fxc:space-y-1.5">
                {shown.map((rule) => (
                    <li key={rule.id} className="fxc:flex fxc:flex-wrap fxc:items-baseline fxc:gap-x-2 fxc:gap-y-0.5 fxc:text-sm">
                        <Badge variant={STATUS[rule.status].tone}>{STATUS[rule.status].label}</Badge>
                        <span className="fxc:font-medium">{rule.name}</span>
                        <span className="fxc:text-muted-foreground">{ruleDetail(rule, choices, fee)}</span>
                    </li>
                ))}
            </ul>
            {hidden > 0 && (
                <button type="button" className="fxc:text-primary fxc:text-xs fxc:hover:underline" onClick={() => setAll(true)}>
                    {sprintf(__("Show %d more (off, or for other products)"), hidden)}
                </button>
            )}
        </div>
    );
}

function Results({ result, fetching, choices }: { result: NonNullable<ReturnType<typeof useSimulation>["data"]>; fetching: boolean; choices: Choices }) {
    const rate = result.shipping_rates.find((r) => r.id === result.shipping_rate);
    const method = choices?.payment_methods.find((m) => m.id === result.payment_method);
    const rows: { label: string; value: string; strong?: boolean }[] = [
        { label: __("Subtotal"), value: result.totals.subtotal.formatted },
        ...(result.totals.discount.amount > 0 ? [{ label: __("Discount"), value: `−${result.totals.discount.formatted}` }] : []),
        ...(result.totals.fees.amount > 0 ? [{ label: __("Fees"), value: result.totals.fees.formatted }] : []),
        ...(result.needs_shipping ? [{ label: rate ? sprintf(__("Shipping: %s"), rate.label) : __("Shipping"), value: rate ? result.totals.shipping.formatted : __("No rate for this address") }] : []),
        { label: result.prices_include_tax ? __("Tax included") : __("Tax"), value: result.totals.tax.formatted },
        { label: __("Total"), value: result.totals.total.formatted, strong: true },
    ];

    return (
        <div className={cn("fxc:space-y-4 fxc:transition-opacity", fetching && "fxc:opacity-60")} aria-busy={fetching}>
            {(result.problems.length > 0 || result.notices.length > 0) && (
                <Card>
                    <CardContent className="fxc:space-y-1 fxc:text-sm">
                        {result.problems.map((p) => (
                            <p key={p} className="fxc:text-destructive">
                                {p}
                            </p>
                        ))}
                        {result.notices.map((n) => (
                            <p key={n} className="fxc:text-muted-foreground">
                                {n}
                            </p>
                        ))}
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>{__("Products")}</CardTitle>
                    <CardDescription>{__("What each pricing rule did to each line. The customer gets the lowest price; rules do not add up.")}</CardDescription>
                </CardHeader>
                <ul className="fxc:divide-border fxc:divide-y">
                    {result.lines.map((line) => {
                        const changed = line.before !== null && line.unit !== null && line.unit.amount !== line.before.amount;
                        return (
                            <li key={line.key} className="fxc:space-y-2 fxc:px-5 fxc:py-4">
                                <div className="fxc:flex fxc:flex-wrap fxc:items-baseline fxc:gap-x-3">
                                    <span className="fxc:font-medium">
                                        {line.name} × {line.quantity}
                                    </span>
                                    <span className="fxc:text-muted-foreground fxc:text-sm">
                                        {changed ? (
                                            <>
                                                <s>{line.before?.formatted}</s> {line.unit?.formatted}
                                            </>
                                        ) : (
                                            line.unit?.formatted
                                        )}
                                    </span>
                                    <span className="fxc:ms-auto fxc:font-medium">{line.total?.formatted}</span>
                                </div>
                                {line.discount.amount > 0 && <p className="fxc:text-muted-foreground fxc:text-sm">{sprintf(__("Coupons take off %s."), line.discount.formatted)}</p>}
                                <RuleList rules={line.rules} choices={choices} />
                            </li>
                        );
                    })}
                </ul>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Coupons and automatic discounts")}</CardTitle>
                </CardHeader>
                <CardContent className="fxc:space-y-1.5">
                    {result.coupons.length === 0 ? (
                        <p className="fxc:text-muted-foreground fxc:text-sm">{__("No codes entered and no automatic discounts.")}</p>
                    ) : (
                        <ul className="fxc:space-y-1.5">
                            {result.coupons.map((c) => (
                                <li key={`${c.automatic ? "auto" : "code"}:${c.code}`} className="fxc:flex fxc:flex-wrap fxc:items-baseline fxc:gap-x-2 fxc:gap-y-0.5 fxc:text-sm">
                                    <Badge variant={COUPON_STATUS[c.status].tone}>{COUPON_STATUS[c.status].label}</Badge>
                                    <span className="fxc:font-medium">{c.code}</span>
                                    {c.automatic && <span className="fxc:text-muted-foreground fxc:text-xs">{__("automatic")}</span>}
                                    <span className="fxc:text-muted-foreground">{c.amount ? sprintf(__("Takes off %s."), c.amount.formatted) : c.message}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {result.next_discount && <p className="fxc:text-muted-foreground fxc:pt-1 fxc:text-sm">{sprintf(__("The cart shows: “%s”"), result.next_discount.message)}</p>}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Checkout fees")}</CardTitle>
                </CardHeader>
                <CardContent>
                    <RuleList rules={result.fees} choices={choices} fee />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Totals")}</CardTitle>
                    {method && <CardDescription>{sprintf(__("Paid with %s."), method.title)}</CardDescription>}
                </CardHeader>
                <CardContent>
                    <dl className="fxc:space-y-1 fxc:text-sm">
                        {rows.map((row) => (
                            <div key={row.label} className={cn("fxc:flex fxc:justify-between fxc:gap-4", row.strong && "fxc:border-border fxc:border-t fxc:pt-2 fxc:text-base fxc:font-semibold")}>
                                <dt>{row.label}</dt>
                                <dd>{row.value}</dd>
                            </div>
                        ))}
                    </dl>
                </CardContent>
            </Card>
        </div>
    );
}
