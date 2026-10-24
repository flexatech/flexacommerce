import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/shared/lib/cn";

const badgeVariants = cva(
    "fxc:inline-flex fxc:items-center fxc:rounded-full fxc:px-2.5 fxc:py-0.5 fxc:text-xs fxc:font-medium",
    {
        variants: {
            variant: {
                muted: "fxc:bg-muted fxc:text-muted-foreground",
                success: "fxc:bg-success/15 fxc:text-success",
                brand: "fxc:bg-brand-50 fxc:text-brand-700",
                destructive: "fxc:bg-destructive/10 fxc:text-destructive",
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
