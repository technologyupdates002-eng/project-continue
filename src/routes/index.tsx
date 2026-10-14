import { createFileRoute } from "@tanstack/react-router";
import { Shell } from "@/components/shell/Shell";

export const Route = createFileRoute("/")({
  ssr: false,
  head: () => ({
    meta: [
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
      { title: "KHWWC — Kirinyaga Healthcare Workers Welfare" },
      { name: "description", content: "Kirinyaga Healthcare Workers Welfare — member portal and public website." },
      { property: "og:title", content: "KHWWC — Kirinyaga Healthcare Workers Welfare" },
      { property: "og:description", content: "Kirinyaga Healthcare Workers Welfare — member portal and public website." },
    ],
  }),
  component: Shell,
});
