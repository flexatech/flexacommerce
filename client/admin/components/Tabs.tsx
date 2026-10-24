import { cn } from "@/shared/lib/cn";

interface TabsProps<T extends string> {
    value: T;
    onChange: (value: T) => void;
    items: { value: T; label: string }[];
}

export function Tabs<T extends string>({ value, onChange, items }: TabsProps<T>) {
    return (
        <div role="tablist" className="fxc:flex fxc:gap-1 fxc:border-b">
            {items.map((item) => (
                <button
                    key={item.value}
                    role="tab"
                    type="button"
                    aria-selected={item.value === value}
                    onClick={() => onChange(item.value)}
                    className={cn(
                        "fxc:-mb-px fxc:cursor-pointer fxc:border-b-2 fxc:border-transparent fxc:bg-transparent fxc:px-3 fxc:py-2 fxc:text-sm fxc:font-medium fxc:text-muted-foreground fxc:outline-none fxc:hover:text-foreground fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                        item.value === value && "fxc:border-primary fxc:text-primary",
                    )}
                >
                    {item.label}
                </button>
            ))}
        </div>
    );
}
