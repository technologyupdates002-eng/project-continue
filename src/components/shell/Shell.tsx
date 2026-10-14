import { lazy, Suspense, useEffect, useState } from "react";

const SawaApp = lazy(() => import("@/SawaApp"));

export function Shell() {
  const [ready, setReady] = useState(false);
  useEffect(() => setReady(true), []);
  if (!ready) return null;
  return (
    <Suspense fallback={null}>
      <SawaApp />
    </Suspense>
  );
}
