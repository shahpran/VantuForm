import type * as React from 'react';
import type { Root } from 'react-dom/client';
import type { ApiFetchFunction } from '@wordpress/api-fetch';

export interface VxfTableHealth {
  exists: boolean;
  is_innodb: boolean;
  missing_columns: string[];
  missing_indexes: string[];
}

export interface VxfDbStatus {
  db_version: string;
  expected_version: string;
  options_is_innodb?: boolean;
  is_healthy: boolean;
  tables: Record<string, VxfTableHealth>;
  last_error: string;
}

export interface VxfFieldDefinitionMeta {
  type: string;
  label: string;
  category: string;
  isPro: boolean;
  defaultConfig: Record<string, unknown>;
}

export interface VxfAdminBootstrapData {
  pluginName: string;
  version: string;
  dbVersion: string;
  restUrl: string;
  restNonce: string;
  adminNonce: string;
  siteUrl: string;
  buildExists: boolean;
  dbStatus: VxfDbStatus;
  fieldDefinitions: VxfFieldDefinitionMeta[];
  user: {
    id: number;
    displayName: string;
    canManage: boolean;
  };
}

declare global {
  interface Window {
    vxfAdminData?: VxfAdminBootstrapData;
    wp?: {
      element?: typeof React & {
        createRoot?: (container: Element | DocumentFragment) => Root;
        hydrateRoot?: (container: Element | Document, initialChildren: React.ReactNode) => Root;
        render?: (element: React.ReactElement, container: Element | null) => void;
      };
      apiFetch?: ApiFetchFunction;
    };
  }
}

export {};
