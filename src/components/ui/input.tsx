import * as React from "react";
import { cn } from "../../lib/utils";

export type InputProps = React.InputHTMLAttributes<HTMLInputElement>;

const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, type, ...props }, ref) => {
    const baseStyles =
      "flex h-8 w-full rounded-sm border border-input bg-background px-3 py-1.5 text-sm file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50";
    const adminStyles = "h-9 px-3";

    return (
      <input type={type} className={cn(baseStyles, adminStyles, className)} ref={ref} {...props} />
    );
  },
);
Input.displayName = "Input";

export { Input };
