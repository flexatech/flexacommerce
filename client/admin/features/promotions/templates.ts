import { getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { formatMoney } from "@/shared/lib/units";
import type { CouponPreset } from "../coupons/CouponDialog";
import { RULE_PRESETS, type RulePreset } from "../pricing/PriceRuleDialog";

/** What a template helps with: the gallery groups templates by it. */
export type TemplateGoal = "sale" | "cart" | "quantity" | "customers" | "fees";

interface Base {
    key: string;
    goal: TemplateGoal;
    name: string;
    description: string;
}

/**
 * A ready-made promotion (ADR-047): a pricing rule or a coupon with its values filled in. Picking one opens the
 * editor with them; nothing is saved until the merchant saves, and targets (products, categories, roles) are
 * left for the merchant to pick.
 */
export type Template = (Base & { kind: "rule"; preset: RulePreset }) | (Base & { kind: "coupon"; automatic: boolean; preset: CouponPreset });

export const GOALS: { goal: TemplateGoal; label: string }[] = [
    { goal: "sale", label: __("Run a sale") },
    { goal: "cart", label: __("Grow the cart") },
    { goal: "quantity", label: __("Sell more units") },
    { goal: "customers", label: __("Reward customers") },
    { goal: "fees", label: __("Charge a fee") },
];

const preset = (key: string): RulePreset => {
    const found = RULE_PRESETS.find((p) => p.key === key);
    if (found === undefined) {
        throw new Error(`No rule preset ${key}`);
    }
    return found;
};

const rule = (goal: TemplateGoal, p: RulePreset): Template => ({ kind: "rule", key: `rule-${p.key}`, goal, name: p.name, description: p.description, preset: p });

const extraRule = (goal: TemplateGoal, key: string, name: string, description: string, values: RulePreset["values"]): Template =>
    rule(goal, { key, name, description, values });

const coupon = (goal: TemplateGoal, key: string, name: string, description: string, values: CouponPreset): Template => ({
    kind: "coupon",
    key: `coupon-${key}`,
    goal,
    name,
    description,
    automatic: values.automatic ?? false,
    preset: values,
});

/** An amount written for 2 decimals, shown in the store currency. */
function money(minor: number): string {
    const { currency } = getPluginGlobal();
    return formatMoney(Math.round((minor * 10 ** currency.exponent) / 100), currency);
}

/** Built on call: descriptions show amounts in the store currency. */
export const templates = (): Template[] => [
    extraRule("sale", "storewide", __("Store-wide sale"), __("A percent off every product, with no code to type."), {
        type: "adjust",
        mode: "percent",
        amount: 1000,
        tiers: [],
        applies_to: "all",
        customers: "all",
    }),
    rule("sale", preset("category")),
    extraRule("sale", "clearance", __("Clearance"), __("A deep percent off the products you pick."), {
        type: "adjust",
        mode: "percent",
        amount: 3000,
        tiers: [],
        applies_to: "products",
        customers: "all",
    }),
    coupon("sale", "code", __("Discount code"), __("A code for a percent off the cart, to share in a newsletter or a post."), {
        code: "SAVE10",
        type: "percent",
        amount: 1000,
    }),

    coupon("cart", "spend_save", __("Spend and save"), sprintf(__("A percent off once the cart reaches %s. The cart tells shoppers how much more to add."), money(10000)), {
        automatic: true,
        code: __("Spend and save"),
        type: "percent",
        amount: 1000,
        min_subtotal: 10000,
    }),
    coupon("cart", "amount_off", __("Amount off a big order"), sprintf(__("A fixed amount off carts of %s or more."), money(15000)), {
        automatic: true,
        code: __("Big order discount"),
        type: "fixed_cart",
        amount: 1500,
        min_subtotal: 15000,
    }),
    coupon("cart", "free_shipping", __("Free shipping over an amount"), sprintf(__("Shipping is free once the cart reaches %s."), money(7500)), {
        automatic: true,
        code: __("Free shipping"),
        type: "percent",
        amount: 0,
        min_subtotal: 7500,
        free_shipping: true,
    }),

    rule("quantity", preset("bulk")),
    rule("quantity", preset("graduated")),
    coupon("quantity", "bogo", __("Buy one, get one free"), __("For each product bought, a second one is free."), {
        automatic: true,
        code: __("Buy one, get one free"),
        type: "buy_x_get_y",
        amount: 10000,
        buy_qty: 1,
        get_qty: 1,
    }),
    coupon("quantity", "three_for_two", __("Buy 2, get 1 half price"), __("For every two products bought, a third is half price."), {
        automatic: true,
        code: __("Buy 2, get 1 half price"),
        type: "buy_x_get_y",
        amount: 5000,
        buy_qty: 2,
        get_qty: 1,
    }),
    coupon("quantity", "bundle", __("Bundle price"), __("A set of the products you pick sells for one price."), {
        automatic: true,
        code: __("Bundle deal"),
        type: "bundle",
        bundle_mode: "price",
        amount: 5000,
    }),

    rule("customers", preset("wholesale")),
    coupon("customers", "welcome", __("First order discount"), __("A code for a percent off, for customers with no earlier orders, once each."), {
        code: "WELCOME10",
        type: "percent",
        amount: 1000,
        per_customer_limit: 1,
        conditions: { match: "all", items: [{ type: "order_count", op: "lte", value: 0 }] },
    }),
    coupon("customers", "loyalty", __("Loyal customer discount"), sprintf(__("A percent off for customers who have spent %s or more with you."), money(50000)), {
        automatic: true,
        code: __("Thank you discount"),
        type: "percent",
        amount: 500,
        conditions: { match: "all", items: [{ type: "lifetime_spend", op: "gte", value: 50000 }] },
    }),

    rule("fees", preset("small_order")),
    rule("fees", preset("payment_fee")),
    extraRule("fees", "handling", __("Handling fee"), __("A fixed fee on every order, shown as its own line."), {
        type: "fee",
        mode: "fixed",
        amount: 200,
        tiers: [],
        applies_to: "all",
        customers: "all",
    }),
];

/** What a Promotions tab gets to open the gallery and to start the template picked there. */
export interface TemplateProps {
    template?: Template | null;
    onTemplates?: () => void;
    onTemplateUsed?: () => void;
}
