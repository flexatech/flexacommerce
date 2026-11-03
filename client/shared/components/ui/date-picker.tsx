import * as React from "react";
import { createPortal } from "react-dom";
import { CalendarDays, ChevronLeft, ChevronRight, Clock } from "lucide-react";

import { getPluginGlobal } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";

/**
 * Calendar for date and datetime values, in place of the browser's own
 * input. The value keeps the native shape (YYYY-MM-DD, or YYYY-MM-DDTHH:MM
 * with `withTime`), so callers swap `<Input type="date">` for it as is. The
 * label follows the site's date and time formats; month and weekday names
 * come from Intl in the admin locale.
 */
export interface DatePickerProps {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    withTime?: boolean;
    /** Same shape as the value: a day, or a local datetime with `withTime`. */
    min?: string;
    max?: string;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    "aria-label"?: string;
    "aria-invalid"?: boolean;
}

interface Time {
    h: number;
    m: number;
}

const pad2 = (n: number) => (n < 10 ? "0" : "") + n;

function parseDay(value: string | undefined): Date | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? "");
    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
}

function parseLocal(value: string | undefined): Date | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(value ?? "");
    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5])) : null;
}

const toDay = (d: Date) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
const toLocal = (d: Date) => `${toDay(d)}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
const dayOf = (d: Date | null) => (d ? new Date(d.getFullYear(), d.getMonth(), d.getDate()) : null);
const sameDay = (a: Date | null, b: Date | null) => !!a && !!b && toDay(a) === toDay(b);
const minutesOf = (t: Time) => t.h * 60 + t.m;

function settings() {
    const global = getPluginGlobal();
    return { locale: global.locale.replace("_", "-"), ...global.dates };
}

function dayPeriod(date: Date, locale: string): string {
    const part = new Intl.DateTimeFormat(locale, { hour: "numeric", hour12: true }).formatToParts(date).find((p) => p.type === "dayPeriod");
    return part?.value ?? (date.getHours() < 12 ? "AM" : "PM");
}

function ordinal(n: number): string {
    const s = ["th", "st", "nd", "rd"];
    const v = n % 100;
    return s[(v - 20) % 10] ?? s[v] ?? s[0];
}

/** A Date in a PHP date() format, the way wp_date() prints it on the server. */
export function phpDate(date: Date, format: string, locale: string): string {
    const name = (options: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(locale, options).format(date);
    let out = "";
    for (let i = 0; i < format.length; i++) {
        const ch = format.charAt(i);
        if (ch === "\\") {
            out += format.charAt(++i);
            continue;
        }
        switch (ch) {
            case "d": out += pad2(date.getDate()); break;
            case "j": out += date.getDate(); break;
            case "D": out += name({ weekday: "short" }); break;
            case "l": out += name({ weekday: "long" }); break;
            case "S": out += ordinal(date.getDate()); break;
            case "m": out += pad2(date.getMonth() + 1); break;
            case "n": out += date.getMonth() + 1; break;
            case "M": out += name({ month: "short" }); break;
            case "F": out += name({ month: "long" }); break;
            case "Y": out += date.getFullYear(); break;
            case "y": out += pad2(date.getFullYear() % 100); break;
            case "g": out += date.getHours() % 12 || 12; break;
            case "G": out += date.getHours(); break;
            case "h": out += pad2(date.getHours() % 12 || 12); break;
            case "H": out += pad2(date.getHours()); break;
            case "i": out += pad2(date.getMinutes()); break;
            case "s": out += pad2(date.getSeconds()); break;
            case "A": out += dayPeriod(date, locale); break;
            case "a": out += dayPeriod(date, locale).toLowerCase(); break;
            default: out += ch;
        }
    }
    return out;
}

const NAV = "fxc:text-foreground fxc:hover:bg-muted fxc:inline-flex fxc:size-8 fxc:cursor-pointer fxc:items-center fxc:justify-center fxc:rounded-md fxc:border fxc:bg-transparent fxc:p-0 fxc:outline-none";
const FOOT = "fxc:text-foreground fxc:hover:bg-muted fxc:cursor-pointer fxc:rounded-md fxc:border fxc:bg-transparent fxc:px-3 fxc:py-1 fxc:text-sm fxc:outline-none";

export function DatePicker({ id, value, onChange, withTime = false, min, max, placeholder, disabled, className, ...aria }: DatePickerProps) {
    const { locale, format, timeFormat, weekStart } = settings();
    const minStamp = withTime ? parseLocal(min) : null;
    const maxStamp = withTime ? parseLocal(max) : null;
    const minDate = withTime ? dayOf(minStamp) : parseDay(min);
    const maxDate = withTime ? dayOf(maxStamp) : parseDay(max);
    const selected = withTime ? parseLocal(value) : parseDay(value);

    const [open, setOpen] = React.useState(false);
    const [view, setView] = React.useState(() => new Date());
    const [time, setTime] = React.useState<Time>(() => (selected ? { h: selected.getHours(), m: selected.getMinutes() } : minStamp ? { h: minStamp.getHours(), m: minStamp.getMinutes() } : { h: 12, m: 0 }));
    const [pos, setPos] = React.useState<{ top: number; left: number } | null>(null);
    const [host, setHost] = React.useState<HTMLElement | null>(null);

    const triggerRef = React.useRef<HTMLButtonElement>(null);
    const popupRef = React.useRef<HTMLDivElement>(null);
    const gridRef = React.useRef<HTMLDivElement>(null);
    const focusDay = React.useRef<string | null>(null);

    const clamp = (d: Date) => (minDate && d < minDate ? minDate : maxDate && d > maxDate ? maxDate : d);
    const isOff = (d: Date) => (!!minDate && d < minDate) || (!!maxDate && d > maxDate);
    const floor = (day: Date): Time => (minStamp && sameDay(day, minStamp) ? { h: minStamp.getHours(), m: minStamp.getMinutes() } : { h: 0, m: 0 });
    const ceil = (day: Date): Time => (maxStamp && sameDay(day, maxStamp) ? { h: maxStamp.getHours(), m: maxStamp.getMinutes() } : { h: 23, m: 59 });
    const clampTime = (day: Date, t: Time): Time => {
        const lo = floor(day);
        const hi = ceil(day);
        return minutesOf(t) < minutesOf(lo) ? lo : minutesOf(t) > minutesOf(hi) ? hi : t;
    };
    const at = (day: Date, t: Time) => new Date(day.getFullYear(), day.getMonth(), day.getDate(), t.h, t.m);

    const show = () => {
        const start = clamp(selected ?? new Date());
        setView(new Date(start.getFullYear(), start.getMonth(), 1));
        if (selected && withTime) {
            setTime({ h: selected.getHours(), m: selected.getMinutes() });
        }
        // Inside a dialog the popup joins the dialog, so its focus trap and
        // outside-click check count the calendar as part of it. The product
        // editor keeps its styles under its own root, so it joins that.
        setHost(triggerRef.current?.closest<HTMLElement>('[role="dialog"], .flexacommerce-product-ui') ?? document.body);
        focusDay.current = toDay(selected && !isOff(dayOf(selected)!) ? selected : start);
        setOpen(true);
    };

    const hide = (refocus: boolean) => {
        setOpen(false);
        setPos(null);
        if (refocus) {
            triggerRef.current?.focus();
        }
    };

    // Placed against the trigger, below it, or above when the window has no room.
    const place = React.useCallback(() => {
        const trigger = triggerRef.current;
        const popup = popupRef.current;
        if (!trigger || !popup) {
            return;
        }
        const r = trigger.getBoundingClientRect();
        let top = r.bottom + 4;
        if (top + popup.offsetHeight > window.innerHeight - 8 && r.top - popup.offsetHeight - 4 > 8) {
            top = r.top - popup.offsetHeight - 4;
        }
        let left = Math.max(8, Math.min(r.left, window.innerWidth - popup.offsetWidth - 8));
        const parent = popup.offsetParent as HTMLElement | null;
        if (parent && !(parent === document.body && getComputedStyle(parent).position === "static")) {
            const p = parent.getBoundingClientRect();
            top = top - p.top + parent.scrollTop - parent.clientTop;
            left = left - p.left + parent.scrollLeft - parent.clientLeft;
        } else {
            top += window.scrollY;
            left += window.scrollX;
        }
        setPos({ top, left });
    }, []);

    React.useLayoutEffect(() => {
        if (!open) {
            return;
        }
        place();
        window.addEventListener("resize", place);
        window.addEventListener("scroll", place, true);
        return () => {
            window.removeEventListener("resize", place);
            window.removeEventListener("scroll", place, true);
        };
    }, [open, view, place]);

    React.useEffect(() => {
        if (!open) {
            return;
        }
        const onDown = (e: PointerEvent) => {
            const target = e.target as Node;
            if (!popupRef.current?.contains(target) && !triggerRef.current?.contains(target)) {
                hide(false);
            }
        };
        // Window capture runs before the dialog's own Escape handler, so
        // Escape closes the calendar and leaves the dialog open.
        const onKey = (e: KeyboardEvent) => {
            if (e.key === "Escape") {
                e.stopPropagation();
                e.preventDefault();
                hide(true);
            }
        };
        document.addEventListener("pointerdown", onDown, true);
        window.addEventListener("keydown", onKey, true);
        return () => {
            document.removeEventListener("pointerdown", onDown, true);
            window.removeEventListener("keydown", onKey, true);
        };
    }, [open]);

    React.useEffect(() => {
        // A hidden popup takes no focus, so wait until it has been placed.
        if (open && pos && focusDay.current) {
            gridRef.current?.querySelector<HTMLButtonElement>(`[data-date="${focusDay.current}"]:not(:disabled)`)?.focus();
            focusDay.current = null;
        }
    });

    const pick = (day: Date) => {
        if (withTime) {
            const t = clampTime(day, time);
            setTime(t);
            onChange(toLocal(at(day, t)));
            focusDay.current = toDay(day);
            return;
        }
        onChange(toDay(day));
        hide(true);
    };

    const pickTime = (t: Time) => {
        const day = selected ? dayOf(selected)! : clamp(dayOf(new Date())!);
        const next = clampTime(day, t);
        setTime(next);
        if (selected) {
            onChange(toLocal(at(day, next)));
        }
    };

    const onGridKey = (e: React.KeyboardEvent) => {
        const delta = ({ ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 } as Record<string, number>)[e.key];
        const current = parseDay((document.activeElement as HTMLElement | null)?.dataset.date);
        if (!delta || !current) {
            return;
        }
        e.preventDefault();
        const next = new Date(current.getFullYear(), current.getMonth(), current.getDate() + delta);
        if (isOff(next)) {
            return;
        }
        focusDay.current = toDay(next);
        if (next.getMonth() !== view.getMonth() || next.getFullYear() !== view.getFullYear()) {
            setView(new Date(next.getFullYear(), next.getMonth(), 1));
        } else {
            gridRef.current?.querySelector<HTMLButtonElement>(`[data-date="${focusDay.current}"]`)?.focus();
            focusDay.current = null;
        }
    };

    const month = (step: number) => {
        setView(new Date(view.getFullYear(), view.getMonth() + step, 1));
    };

    const label = selected ? phpDate(selected, withTime ? `${format} ${timeFormat}` : format, locale) : (placeholder ?? (withTime ? __("Choose date and time") : __("Choose date")));

    const year = view.getFullYear();
    const m = view.getMonth();
    const lead = (new Date(year, m, 1).getDay() - weekStart + 7) % 7;
    const days = new Date(year, m + 1, 0).getDate();
    const today = new Date();
    const inView = selected && selected.getMonth() === m && selected.getFullYear() === year && !isOff(dayOf(selected)!) ? selected : null;
    const firstOpen = Array.from({ length: days }, (_, i) => new Date(year, m, i + 1)).find((d) => !isOff(d));
    const anchor = inView ? toDay(inView) : firstOpen ? toDay(firstOpen) : "";
    const long = new Intl.DateTimeFormat(locale, { year: "numeric", month: "long", day: "numeric" });

    const timeDay = selected ? dayOf(selected)! : clamp(dayOf(today)!);
    const lo = floor(timeDay);
    const hi = ceil(timeDay);
    const shown = clampTime(timeDay, time);
    const hour12 = /[gh]/.test(timeFormat.replace(/\\./g, ""));
    const hours: number[] = [];
    for (let h = lo.h; h <= hi.h; h++) {
        hours.push(h);
    }
    const minutes: number[] = [];
    for (let i = 0; i < 60; i++) {
        const t = shown.h * 60 + i;
        if (t >= minutesOf(lo) && t <= minutesOf(hi)) {
            minutes.push(i);
        }
    }

    const popup = open && host
        ? createPortal(
              <div
                  ref={popupRef}
                  role="dialog"
                  aria-label={withTime ? __("Choose date and time") : __("Choose date")}
                  className="fxc:bg-popover fxc:text-popover-foreground fxc:absolute fxc:z-[160010] fxc:w-72 fxc:max-w-[92vw] fxc:rounded-lg fxc:border fxc:p-3 fxc:shadow-lg"
                  style={pos ? { top: pos.top, left: pos.left } : { top: 0, left: 0, visibility: "hidden" }}
              >
                  <div className="fxc:mb-2 fxc:flex fxc:items-center fxc:justify-between fxc:gap-2">
                      <button type="button" className={NAV} aria-label={__("Previous month")} onClick={() => month(-1)}>
                          <ChevronLeft aria-hidden className="fxc:size-4" />
                      </button>
                      <div className="fxc:text-sm fxc:font-semibold fxc:capitalize" aria-live="polite">
                          {new Intl.DateTimeFormat(locale, { month: "long", year: "numeric" }).format(view)}
                      </div>
                      <button type="button" className={NAV} aria-label={__("Next month")} onClick={() => month(1)}>
                          <ChevronRight aria-hidden className="fxc:size-4" />
                      </button>
                  </div>
                  <div className="fxc:grid fxc:grid-cols-7 fxc:gap-0.5">
                      {Array.from({ length: 7 }, (_, i) => (
                          <div key={i} className="fxc:text-muted-foreground fxc:py-1 fxc:text-center fxc:text-xs fxc:font-semibold fxc:capitalize">
                              {new Intl.DateTimeFormat(locale, { weekday: "short" }).format(new Date(2023, 0, 1 + ((weekStart + i) % 7)))}
                          </div>
                      ))}
                  </div>
                  <div ref={gridRef} role="grid" className="fxc:grid fxc:grid-cols-7 fxc:gap-0.5" onKeyDown={onGridKey}>
                      {Array.from({ length: lead }, (_, i) => (
                          <span key={`b${i}`} />
                      ))}
                      {Array.from({ length: days }, (_, i) => {
                          const date = new Date(year, m, i + 1);
                          const off = isOff(date);
                          const isSelected = sameDay(selected, date);
                          return (
                              <button
                                  key={i}
                                  type="button"
                                  role="gridcell"
                                  data-date={toDay(date)}
                                  aria-label={long.format(date)}
                                  aria-selected={isSelected || undefined}
                                  disabled={off}
                                  tabIndex={toDay(date) === anchor ? 0 : -1}
                                  onClick={() => pick(date)}
                                  className={cn(
                                      "fxc:flex fxc:aspect-square fxc:cursor-pointer fxc:items-center fxc:justify-center fxc:rounded-md fxc:border-0 fxc:bg-transparent fxc:p-0 fxc:text-sm fxc:outline-none",
                                      "fxc:text-foreground fxc:hover:bg-muted fxc:focus-visible:bg-muted",
                                      sameDay(today, date) && "fxc:ring-primary fxc:ring-1 fxc:ring-inset",
                                      isSelected && "fxc:bg-primary fxc:text-primary-foreground fxc:hover:bg-primary fxc:focus-visible:bg-primary",
                                      off && "fxc:text-muted-foreground/50 fxc:cursor-not-allowed fxc:line-through fxc:hover:bg-transparent",
                                  )}
                              >
                                  {i + 1}
                              </button>
                          );
                      })}
                  </div>
                  {withTime && (
                      <div className="fxc:mt-3 fxc:flex fxc:items-center fxc:gap-2 fxc:border-t fxc:pt-3">
                          <Clock aria-hidden className="fxc:text-muted-foreground fxc:size-4" />
                          <select
                              aria-label={__("Hour")}
                              className="flexacommerce-control fxc:h-8 fxc:flex-1 fxc:text-sm"
                              value={shown.h}
                              onChange={(e) => pickTime({ h: Number(e.target.value), m: shown.m })}
                          >
                              {hours.map((h) => (
                                  <option key={h} value={h}>
                                      {hour12 ? phpDate(new Date(2023, 0, 1, h), "g A", locale) : pad2(h)}
                                  </option>
                              ))}
                          </select>
                          <span aria-hidden>:</span>
                          <select
                              aria-label={__("Minute")}
                              className="flexacommerce-control fxc:h-8 fxc:flex-1 fxc:text-sm"
                              value={shown.m}
                              onChange={(e) => pickTime({ h: shown.h, m: Number(e.target.value) })}
                          >
                              {minutes.map((n) => (
                                  <option key={n} value={n}>
                                      {pad2(n)}
                                  </option>
                              ))}
                          </select>
                      </div>
                  )}
                  <div className="fxc:mt-3 fxc:flex fxc:justify-between fxc:gap-2">
                      <button
                          type="button"
                          className={FOOT}
                          onClick={() => {
                              onChange("");
                              hide(true);
                          }}
                      >
                          {__("Clear")}
                      </button>
                      {withTime && (
                          <button type="button" className={FOOT} onClick={() => hide(true)}>
                              {__("Done")}
                          </button>
                      )}
                  </div>
              </div>,
              host,
          )
        : null;

    return (
        <>
            <button
                ref={triggerRef}
                id={id}
                type="button"
                disabled={disabled}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={aria["aria-label"] ? `${aria["aria-label"]}: ${label}` : undefined}
                aria-invalid={aria["aria-invalid"]}
                onClick={() => (open ? hide(false) : show())}
                className={cn(
                    "flexacommerce-control fxc:border-input fxc:bg-background fxc:text-foreground fxc:flex fxc:h-9 fxc:w-full fxc:cursor-pointer fxc:items-center fxc:gap-2 fxc:rounded-md fxc:border fxc:px-3 fxc:py-1 fxc:text-left fxc:text-sm fxc:shadow-sm fxc:outline-none",
                    "fxc:disabled:cursor-not-allowed fxc:disabled:opacity-50",
                    className,
                )}
            >
                <span className={cn("fxc:min-w-0 fxc:flex-1 fxc:truncate", !selected && "fxc:text-muted-foreground")}>{label}</span>
                <CalendarDays aria-hidden className="fxc:text-muted-foreground fxc:size-4 fxc:shrink-0" />
            </button>
            {popup}
        </>
    );
}
