import React, { useEffect, useRef } from "react";

/**
 * PrimusMarker — zero-cost anchor rendered by Blueprint into each configured
 * placement area. It registers its spot in the DOM so the Primus vanilla-JS
 * engine has a stable, collision-free mount point if a widget ever migrates
 * from global mutation to a React-native implementation.
 */
export default () => {
  const ref = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const w = (window as unknown as { __primus?: { emit?: (n: string, d: object) => void } }).__primus;
    if (w && typeof w.emit === "function") w.emit("marker:mount", {});
  }, []);

  return <span ref={ref} data-primus-marker aria-hidden style={{ display: "contents" }} />;
};
