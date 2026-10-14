export const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL || 'https://api.khwwc.co.ke/api';

const TOKEN_KEY = 'khwwc_jwt_token';
const USER_KEY = 'khwwc_user';
const DEMO_MODE_KEY = 'khwwc_demo_mode';

export function getDemoMode(): boolean {
  try {
    const stored = localStorage.getItem(DEMO_MODE_KEY);
    return stored === null ? true : stored === 'true';
  } catch {
    return true;
  }
}

export function setDemoMode(enabled: boolean): void {
  try {
    localStorage.setItem(DEMO_MODE_KEY, String(enabled));
  } catch {
    /* ignore */
  }
}

export function getToken(): string | null {
  try {
    return sessionStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

export function setToken(token: string): void {
  try {
    sessionStorage.setItem(TOKEN_KEY, token);
  } catch {
    /* ignore */
  }
}

export function clearToken(): void {
  try {
    sessionStorage.removeItem(TOKEN_KEY);
    sessionStorage.removeItem(USER_KEY);
  } catch {
    /* ignore */
  }
}

export function getStoredUser<T>(): T | null {
  try {
    const raw = sessionStorage.getItem(USER_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

export function setStoredUser(user: unknown): void {
  try {
    sessionStorage.setItem(USER_KEY, JSON.stringify(user));
  } catch {
    /* ignore */
  }
}

export class ApiError extends Error {
  status: number;
  data: unknown;
  constructor(message: string, status: number, data?: unknown) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
  }
}

type QueryClientLike = {
  invalidateQueries: (opts: { queryKey: unknown[] }) => void;
  clear: () => void;
};

let queryClientRef: QueryClientLike | null = null;

export function setQueryClientRef(qc: QueryClientLike) {
  queryClientRef = qc;
}

async function request<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const token = getToken();
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
  const headers: Record<string, string> = {
    ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
    Accept: 'application/json',
    ...((options.headers as Record<string, string>) || {}),
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  let response: Response;
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      ...options,
      headers,
    });
  } catch {
    throw new ApiError('Network error — unable to reach the server.', 0);
  }

  if (response.status === 401) {
    clearToken();
    if (queryClientRef) queryClientRef.clear();
    throw new ApiError('Your session has expired. Please sign in again.', 401);
  }

  const contentType = response.headers.get('content-type') || '';

  if (contentType.includes('application/json')) {
    const data = await response.json();
    if (!response.ok) {
      const message =
        (data as { message?: string; error?: string }).message ||
        (data as { error?: string }).error ||
        `Request failed (${response.status})`;
      throw new ApiError(message, response.status, data);
    }
    return data as T;
  }

  if (!response.ok) {
    throw new ApiError(`Request failed (${response.status})`, response.status);
  }

  return (await response.text()) as unknown as T;
}

export const api = {
  get<T>(path: string, params?: Record<string, string | number | boolean>) {
    const search = params
      ? '?' +
        Object.entries(params)
          .filter(([, v]) => v !== undefined && v !== null && v !== '')
          .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
          .join('&')
      : '';
    return request<T>(path + search, { method: 'GET' });
  },

  post<T>(path: string, body?: unknown) {
    const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;
    return request<T>(path, {
      method: 'POST',
      body: isFormData ? body : body ? JSON.stringify(body) : undefined,
    });
  },

  put<T>(path: string, body?: unknown) {
    return request<T>(path, {
      method: 'PUT',
      body: body ? JSON.stringify(body) : undefined,
    });
  },

  patch<T>(path: string, body?: unknown) {
    return request<T>(path, {
      method: 'PATCH',
      body: body ? JSON.stringify(body) : undefined,
    });
  },

  delete<T>(path: string) {
    return request<T>(path, { method: 'DELETE' });
  },

  async download(path: string, filename: string) {
    const token = getToken();
    const headers: Record<string, string> = {};
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const response = await fetch(`${API_BASE_URL}${path}`, { headers });
    if (!response.ok) throw new ApiError('Download failed', response.status);

    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
      const data = await response.json();
      throw new ApiError(
        (data as { message?: string }).message || 'Download failed',
        response.status,
        data,
      );
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  },

  async upload<T>(path: string, formData: FormData): Promise<T> {
    const token = getToken();
    const headers: Record<string, string> = {};
    if (token) headers['Authorization'] = `Bearer ${token}`;

    let response: Response;
    try {
      response = await fetch(`${API_BASE_URL}${path}`, { method: 'POST', headers, body: formData });
    } catch {
      throw new ApiError('Network error — unable to reach the server.', 0);
    }
    if (response.status === 401) {
      clearToken();
      if (queryClientRef) queryClientRef.clear();
      throw new ApiError('Your session has expired. Please sign in again.', 401);
    }
    const ct = response.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      const data = await response.json();
      if (!response.ok) throw new ApiError((data as any).message || (data as any).error || `Request failed (${response.status})`, response.status, data);
      return data as T;
    }
    if (!response.ok) throw new ApiError(`Request failed (${response.status})`, response.status);
    return (await response.text()) as unknown as T;
  },
};
