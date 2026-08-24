import plugin from 'tailwindcss/plugin'

export default {
  darkMode: "class",
  content: ["./src/**/*.{tsx,ts}"],
  theme: {
    extend: {
      colors: {
        background: "var(--background, #fff)",
        foreground: "var(--foreground, #1e1e1e)",
        card: "var(--card, #fff)",
        "card-foreground": "var(--card-foreground, #1e1e1e)",
        popover: "var(--popover, #fff)",
        "popover-foreground": "var(--popover-foreground, #1e1e1e)",
        primary: "var(--primary, #1e1e1e)",
        "primary-foreground": "var(--primary-foreground, #fff)",
        secondary: "var(--secondary, #f3f4f6)",
        "secondary-foreground": "var(--secondary-foreground, #1e1e1e)",
        muted: "var(--muted, #f3f4f6)",
        "muted-foreground": "var(--muted-foreground, #6b7280)",
        accent: "var(--accent, #f3f4f6)",
        "accent-foreground": "var(--accent-foreground, #1e1e1e)",
        destructive: "var(--destructive, #9f1239)",
        "destructive-foreground": "var(--destructive-foreground, #fff)",
        border: "var(--border, #d9d9d9)",
        input: "var(--input, #d9d9d9)",
        ring: "var(--ring, #1e1e1e)",
      },
      borderRadius: {
        DEFAULT: "var(--radius)",
        lg: "var(--radius-lg)",
        md: "var(--radius-md)",
        sm: "var(--radius-sm)",
      },
    },
  },
  plugins: [
    plugin(function ({ addUtilities }) {
      addUtilities({
        ".no-underline": { textDecoration: "none" },
        ".visually-hidden": {
          position: "absolute",
          width: "1px",
          height: "1px",
          padding: "0",
          margin: "-1px",
          overflow: "hidden",
          clip: "rect(0, 0, 0, 0)",
          whiteSpace: "nowrap",
          borderWidth: "0",
        },
      });
    }),
  ],
};