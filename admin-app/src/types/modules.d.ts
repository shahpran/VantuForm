declare module '*.css' {
  const content: Record<string, string>;
  export default content;
}

declare module '@wordpress/api-fetch' {
  export interface ApiFetchOptions {
    path?: string;
    url?: string;
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    data?: unknown;
    body?: BodyInit | null;
    headers?: Record<string, string>;
    parse?: boolean;
    signal?: AbortSignal;
  }

  export type ApiFetchMiddleware = (
    options: ApiFetchOptions,
    next: (nextOptions: ApiFetchOptions) => Promise<unknown>
  ) => Promise<unknown>;

  export interface ApiFetchFunction {
    <T = unknown>(options: ApiFetchOptions): Promise<T>;
    use: (middleware: ApiFetchMiddleware) => void;
    createNonceMiddleware: (nonce: string) => ApiFetchMiddleware;
    createRootURLMiddleware: (url: string) => ApiFetchMiddleware;
  }

  const apiFetch: ApiFetchFunction | null;
  export default apiFetch;
}
