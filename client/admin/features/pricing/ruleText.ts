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

/** The unit price after a change; never above the price. */
export function changed(price: number, mode: RuleMode, amount: number): number {
    if (mode === "percent") {
        return Math.max(0, price - Math.round((price * Math.min(amount, 10000)) / 10000));
    }
    return Math.max(0, mode === "fixed" ? price - amount : Math.min(price, amount));
}

/** One line on what the rule does, to which products and for whom. */
export function summary(rule: PriceRule, roles: Role[]): string {
    const what =
        rule.type === "bulk"
            ? rule.tiers.map((t) => sprintf(__("%1$d+: %2$s"), t.min, changeText(t.mode, t.amount))).join(", ")
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
