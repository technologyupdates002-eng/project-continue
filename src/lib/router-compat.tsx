// Minimal react-router-dom-compatible API backed by TanStack Router.
import {
  Link as TLink,
  useLocation as useTLocation,
  useNavigate as useTNavigate,
} from "@tanstack/react-router";
import {
  Children,
  createContext,
  isValidElement,
  useContext,
  useEffect,
  useMemo,
  type ReactElement,
  type ReactNode,
} from "react";

type Params = Record<string, string>;
const ParamsCtx = createContext<Params>({});
const OutletCtx = createContext<ReactNode>(null);

export function BrowserRouter({ children }: { children: ReactNode }) {
  return <>{children}</>;
}

export function useLocation() {
  const l = useTLocation();
  return { pathname: l.pathname, search: l.searchStr ?? "", hash: l.hash ? `#${l.hash}` : "", state: l.state };
}

export function useNavigate() {
  const nav = useTNavigate();
  return (to: string | number, opts?: { replace?: boolean; state?: unknown }) => {
    if (typeof to === "number") return window.history.go(to);
    nav({ href: to, replace: opts?.replace } as never);
  };
}

export function useParams<T extends Params = Params>() {
  return useContext(ParamsCtx) as T;
}

export function useSearchParams(): [URLSearchParams, (p: URLSearchParams | Record<string, string>) => void] {
  const loc = useLocation();
  const navigate = useNavigate();
  const params = useMemo(() => new URLSearchParams(loc.search), [loc.search]);
  const set = (p: URLSearchParams | Record<string, string>) => {
    const s = new URLSearchParams(p as Record<string, string>).toString();
    navigate(loc.pathname + (s ? `?${s}` : ""));
  };
  return [params, set];
}

export function Navigate({ to, replace }: { to: string; replace?: boolean; state?: unknown }) {
  const navigate = useNavigate();
  useEffect(() => {
    navigate(to, { replace });
  }, [to]);
  return null;
}

type LinkProps = Omit<React.AnchorHTMLAttributes<HTMLAnchorElement>, "href"> & { to: string; replace?: boolean; state?: unknown };
export function Link({ to, replace, state: _s, ...rest }: LinkProps) {
  return <TLink {...(rest as object)} to={to as never} replace={replace} />;
}

export function Outlet() {
  return <>{useContext(OutletCtx)}</>;
}

export function Route(_props: { path?: string; element?: ReactNode; children?: ReactNode }) {
  return null;
}

type Flat = { path: string; chain: ReactNode[] };
function flatten(children: ReactNode, chain: ReactNode[], out: Flat[]) {
  Children.forEach(children, (c) => {
    if (!isValidElement(c)) return;
    const p = (c as ReactElement<{ path?: string; element?: ReactNode; children?: ReactNode }>).props;
    if (p.children) flatten(p.children, [...chain, p.element], out);
    else if (p.path) out.push({ path: p.path, chain: [...chain, p.element] });
  });
}

function match(pattern: string, path: string): Params | null {
  if (pattern === "*") return { "*": path };
  const a = pattern.split("/").filter(Boolean);
  const b = path.split("/").filter(Boolean);
  if (a.length !== b.length) return null;
  const params: Params = {};
  for (let i = 0; i < a.length; i++) {
    if (a[i].startsWith(":")) params[a[i].slice(1)] = decodeURIComponent(b[i]);
    else if (a[i] !== b[i]) return null;
  }
  return params;
}

export function Routes({ children }: { children: ReactNode }) {
  const { pathname } = useLocation();
  const flat = useMemo(() => {
    const out: Flat[] = [];
    flatten(children, [], out);
    return out;
  }, [children]);
  for (const r of flat) {
    const params = match(r.path, pathname);
    if (!params) continue;
    const tree = r.chain.reduceRight<ReactNode>(
      (inner, el) => <OutletCtx.Provider value={inner}>{el}</OutletCtx.Provider>,
      null,
    );
    return <ParamsCtx.Provider value={params}>{tree}</ParamsCtx.Provider>;
  }
  return null;
}
