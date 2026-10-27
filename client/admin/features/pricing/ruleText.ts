import { getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { formatMoney } from "@/shared/lib/units";
import type { PriceRule, Role, RuleMode } from "./usePriceRules";

/** What a change does to a unit price, as a shopper would read it. */
export function changeText(mode: RuleMode, amount: number): string {
    const { currency } = getPluginGlobal();
    if (mode === "percent") {
        return sprintf(__("%s%% off"), String(amount / 100));
    }
    return mode === "fixed" ? sprintf(__("%s off each"), formatMoney(amount, currency)) : sprintf(__("%s each"), formatMoney(amount, currency));
}

/** What a fee adds to the cart: a fixed amount, or a percent of the products it targets. */
export function feeText(mode: RuleMode, amount: number, cap: number): string {
    const { currency } = getPluginGlobal();
    if (mode !== "percent") {
        return formatMoney(amount, currency);
    }
    const percent = sprintf(__("%s%% of the products"), String(amount / 100));
    return cap > 0 ? sprintf(__("%1$s, up to %2$s"), percent, formatMoney(cap, currency)) : percent;
}

/** A fee on a base, as the cart takes it: rounded half up, never above the cap. */
export function feeOn(base: number, mode: RuleMode, amount: number, cap: number): number {
    if (mode !== "percent") {
        return amount;
    }
    const fee = Math.round((base * Math.min(amount, 10000)) / 10000);
    return cap > 0 ? Math.min(fee, cap) : fee;
}

/** The unit price after a change; never above the price. */
export function changed(price: number, mode: RuleMode, amount: number): number {
    if (mode === "percent") {
        return Math.max(0, price - Math.round((price * Math.min(amount, 10000)) / 10000));
    }
    return Math.max(0, mode === "fixed" ? price - amount : Math.min(price, amount));
}

/** One line on what the rule does, to which products and for whom. */
export function summary(rule: PriceRule, roles: Role[]): string {
    const tiers = rule.tiers.map((t) => sprintf(__("%1$d+: %2$s"), t.min, changeText(t.mode, t.amount))).join(", ");
    const what =
        rule.type === "fee"
            ? sprintf(__("Fee %s"), feeText(rule.mode, rule.amount, rule.cap))
            : rule.type === "bulk"
              ? tiers
              : rule.type === "graduated"
                ? sprintf(__("Graduated %s"), tiers)
                : changeText(rule.mode, rule.amount);
    const which =
        rule.applies_to === "products"
            ? sprintf(__("%d products"), rule.product_ids.length)
            : rule.applies_to === "categories"
              ? sprintf(__("%d categories"), rule.category_ids.length)
              : __("All products");
    const names = rule.roles.map((r) => roles.find((role) => role.value === r)?.label ?? r);
    const who = rule.customers === "logged_in" ? __("Logged-in customers") : rule.customers === "roles" ? names.join(", ") : __("Everyone");
    return [what, which, who].join(" · ");
}
