import * as React from "react";
import * as SwitchPrimitive from "@radix-ui/react-switch";

import { cn } from "@/shared/lib/cn";

const Switch = React.forwardRef<
    React.ElementRef<typeof SwitchPrimitive.Root>,
    React.ComponentPropsWithoutRef<typeof SwitchPrimitive.Root>
>(({ className, ...props }, ref) => (
    <SwitchPrimitive.Root
        ref={ref}
        className={cn(
            "fxc:peer fxc:inline-flex fxc:h-5 fxc:w-9 fxc:shrink-0 fxc:cursor-pointer fxc:items-center fxc:rounded-full fxc:border-2 fxc:border-transparent fxc:transition-colors fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent fxc:disabled:cursor-not-allowed fxc:disabled:opacity-50",
            "fxc:data-[state=checked]:bg-primary fxc:data-[state=unchecked]:bg-input",
            className,
        )}
        {...props}
    >
        <SwitchPrimitive.Thumb
            className={cn(
                "fxc:pointer-events-none fxc:block fxc:h-4 fxc:w-4 fxc:rounded-full fxc:bg-white fxc:shadow-lg fxc:ring-0 fxc:transition-transform",
                "fxc:data-[state=checked]:translate-x-4 fxc:data-[state=unchecked]:translate-x-0",
            )}
        />
    </SwitchPrimitive.Root>
));
Switch.displayName = "Switch";

export { Switch };
