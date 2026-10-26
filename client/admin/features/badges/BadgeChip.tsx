import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import type { BadgeShape, ProductBadge } from "./useBadges";

const SHAPE: Record<BadgeShape, string> = {
    rounded: "fxc:rounded",
    pill: "fxc:rounded-full",
    square: "fxc:rounded-none",
    circle: "fxc:rounded-full fxc:inline-flex fxc:items-center fxc:justify-center fxc:min-w-12 fxc:min-h-12 fxc:p-1 fxc:text-center fxc:whitespace-normal",
};

/** `{percent}` shown with a sample discount, as a shopper would see it. */
export const sampleLabel = (label: string): string => label.split("{percent}").join("20");

/** The badge as the storefront draws it, roughly; the theme sets the font. */
export function BadgeChip({ badge, className }: { badge: Pick<ProductBadge, "label" | "bg_color" | "text_color" | "shape">; className?: string }) {
    return (
        <span
            className={cn(
                "fxc:inline-block fxc:px-2 fxc:py-0.5 fxc:text-xs fxc:leading-snug fxc:font-semibold fxc:whitespace-nowrap",
                SHAPE[badge.shape],
                className,
            )}
            style={{ backgroundColor: badge.bg_color, color: badge.text_color }}
        >
            {badge.label.trim() === "" ? __("Badge") : sampleLabel(badge.label)}
        </span>
    );
}
