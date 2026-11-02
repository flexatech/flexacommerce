import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/shared/lib/cn";

const badgeVariants = cva(
    "fxc:inline-flex fxc:items-center fxc:rounded-full fxc:px-2.5 fxc:py-0.5 fxc:text-xs fxc:font-medium fxc:ring-1 fxc:ring-inset",
    {
        variants: {
            variant: {
                muted: "fxc:bg-muted fxc:text-muted-foreground fxc:ring-border",
                success: "fxc:bg-success/15 fxc:text-success fxc:ring-success/25",
                brand: "fxc:bg-brand-50 fxc:text-brand-700 fxc:ring-brand-700/20",
                destructive: "fxc:bg-destructive/10 fxc:text-destructive fxc:ring-destructive/20",
            },
        },
        defaultVariants: {
            variant: "muted",
        },
    },
);

interface BadgeProps
    extends React.HTMLAttributes<HTMLSpanElement>,
        VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
    return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
