import { Select } from "@/shared/components/ui/select";
import { __ } from "@/shared/lib/i18n";
import { Field, IntInput, MoneyInput, Toggle } from "./fields";
import type { FieldErrors, RowChange } from "./RowFields";
import type { SubscriptionPlan, VariantRow } from "./types";

const NEW_PLAN: SubscriptionPlan = { period: "month", interval: 1, length: 0, trial_days: 0, signup_fee: 0 };

/**
 * Sells the row as a subscription (ADR-073). The price is charged on every
 * payment day; a variation without its own plan uses the product's.
 */
export function SubscriptionFields({
    row,
    onChange,
    errors,
    path,
    inherited = null,
}: {
    row: VariantRow;
    onChange: RowChange;
    errors: FieldErrors;
    path: string;
    /** The product's plan, for a variation. */
    inherited?: SubscriptionPlan | null;
}) {
    const plan = row.subscription;
    const set = (patch: Partial<SubscriptionPlan>) => onChange({ subscription: { ...(plan ?? NEW_PLAN), ...patch } });
    const at = `${path}.subscription`;
    const variation = !row.is_base;

    return (
        <div className="fxc:space-y-4">
            <Toggle
                checked={plan !== null}
                onChange={(on) => onChange({ subscription: on ? (inherited ?? NEW_PLAN) : null })}
                label={variation ? __("Own subscription plan") : __("Sell as a subscription")}
                help={
                    variation
                        ? inherited !== null
                            ? __("Off, this variation uses the product's plan.")
                            : __("Bill this variation again on a schedule.")
                        : __("The price is charged again on every payment day until the customer cancels or the payments run out.")
                }
            />
            {plan !== null && (
                <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2 fxc:lg:grid-cols-4">
                    <Field label={__("Bill every")} error={errors[`${at}.interval`] ?? errors[`${at}.period`] ?? errors[at]}>
                        {(id) => (
                            <div className="fxc:flex fxc:gap-2">
                                <div className="fxc:w-20 fxc:shrink-0">
                                    <IntInput id={id} value={plan.interval} onChange={(interval) => set({ interval: interval ?? 1 })} />
                                </div>
                                <Select
                                    aria-label={__("Billing period")}
                                    value={plan.period}
                                    options={[
                                        { value: "day", label: plan.interval === 1 ? __("day") : __("days") },
                                        { value: "week", label: plan.interval === 1 ? __("week") : __("weeks") },
                                        { value: "month", label: plan.interval === 1 ? __("month") : __("months") },
                                        { value: "year", label: plan.interval === 1 ? __("year") : __("years") },
                                    ]}
                                    onChange={(event) => set({ period: event.target.value as SubscriptionPlan["period"] })}
                                />
                            </div>
                        )}
                    </Field>
                    <Field label={__("Number of payments")} help={__("0 renews until cancelled.")} error={errors[`${at}.length`]}>
                        {(id) => <IntInput id={id} value={plan.length} onChange={(length) => set({ length: length ?? 0 })} />}
                    </Field>
                    <Field label={__("Free trial (days)")} help={__("The first payment waits until the trial ends.")} error={errors[`${at}.trial_days`]}>
                        {(id) => <IntInput id={id} value={plan.trial_days} onChange={(trial_days) => set({ trial_days: trial_days ?? 0 })} />}
                    </Field>
                    <Field label={__("Sign-up fee")} help={__("Paid once, with the first order.")} error={errors[`${at}.signup_fee`]}>
                        {(id) => <MoneyInput id={id} value={plan.signup_fee} onChange={(signup_fee) => set({ signup_fee: signup_fee ?? 0 })} />}
                    </Field>
                </div>
            )}
        </div>
    );
}
