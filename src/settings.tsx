import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import AdminSettings from "./components/admin/Settings.tsx";
import "./styles/admin.css";

const SETTINGS_ROOT_SELECTOR = ".js-wp-calendar-settings-root";

function start(): void {
  document.querySelectorAll<HTMLElement>(SETTINGS_ROOT_SELECTOR).forEach((element) => {
    if (element.dataset.mounted === "true") {
      return;
    }

    const root = createRoot(element);

    root.render(
      <StrictMode>
        <AdminSettings runtime={globalThis.WpCalendarSettings ?? {}} />
      </StrictMode>,
    );

    element.dataset.mounted = "true";
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", start, { once: true });
} else {
  start();
}
