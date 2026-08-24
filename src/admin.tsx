import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import AdminEventEditor from "./components/admin/EventEditor.tsx";
import "./styles/admin.css";

const ADMIN_ROOT_SELECTOR = ".js-post-calendar-admin-root";

function start(): void {
  document.querySelectorAll<HTMLElement>(ADMIN_ROOT_SELECTOR).forEach((element) => {
    if (element.dataset.mounted === "true") {
      return;
    }

    const root = createRoot(element);

    root.render(
      <StrictMode>
        <AdminEventEditor runtime={globalThis.PostCalendarAdmin ?? {}} />
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
