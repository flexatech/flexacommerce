import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/shared/lib/cn";

const buttonVariants = cva(
    "fxc:inline-flex fxc:items-center fxc:justify-center fxc:gap-2 fxc:whitespace-nowrap fxc:rounded-md fxc:text-sm fxc:font-medium fxc:transition-all fxc:disabled:pointer-events-none fxc:disabled:opacity-50 fxc:[&_svg]:pointer-events-none fxc:[&_svg:not([class*='fxc:size-'])]:size-4 fxc:shrink-0 fxc:[&_svg]:shrink-0 fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent fxc:cursor-pointer",
    {
        variants: {
            variant: {
                primary:
                    "fxc:bg-primary fxc:text-primary-foreground fxc:shadow-xs fxc:hover:bg-primary-accent",
                outline:
                    "fxc:border fxc:border-primary fxc:text-primary fxc:bg-background fxc:shadow-xs fxc:hover:bg-primary fxc:hover:text-primary-foreground",
                ghost: "fxc:hover:bg-muted fxc:text-foreground fxc:bg-transparent fxc:focus-visible:text-primary",
                link: "fxc:text-foreground fxc:hover:text-primary fxc:underline-offset-4 fxc:hover:underline",
                secondary:
                    "fxc:bg-secondary fxc:text-secondary-foreground fxc:hover:bg-secondary/80",
                destructive:
                    "fxc:bg-destructive fxc:text-destructive-foreground fxc:shadow-xs fxc:hover:bg-destructive-accent",
            },
            size: {
                default: "fxc:h-9 fxc:px-4 fxc:py-2 fxc:has-[>svg]:px-3",
                sm: "fxc:h-8 fxc:rounded-md fxc:gap-1.5 fxc:px-3 fxc:has-[>svg]:px-2.5",
                lg: "fxc:h-10 fxc:rounded-md fxc:px-6 fxc:has-[>svg]:px-4",
                icon: "fxc:size-9",
            },
        },
        defaultVariants: {
            variant: "primary",
            size: "default",
        },
    },
);

interface ButtonProps
    extends React.ButtonHTMLAttributes<HTMLButtonElement>,
        VariantProps<typeof buttonVariants> {
    asChild?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, asChild = false, type = "button", ...props }, ref) => {
        const Comp = asChild ? Slot : "button";
        return (
            <Comp
                data-slot="button"
                className={cn(buttonVariants({ variant, size, className }))}
                type={type}
                {...props}
                ref={ref}
            />
        );
    },
);
Button.displayName = "Button";

export { Button, buttonVariants, type ButtonProps };
