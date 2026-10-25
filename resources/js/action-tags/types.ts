export type PathInput = {
  key: string;
  type: string;
  required: boolean;
};

export type Site = {
  id: number;
  key: string;
  name: string;
  base_url: string;
  description?: string | null;
  paths_count?: number;
};

export type PathItem = {
  id: number;
  site_id: number;
  site_key: string;
  key: string;
  name: string;
  url_pattern: string;
  path_inputs: PathInput[];
  description?: string | null;
};

export type ActionItem = {
  id: number;
  key: string;
  name: string;
  description?: string | null;
};

export type ActionTagItem = {
  id: number;
  tag_key: string;
  site_id?: number | null;
  site_key: string;
  site_name: string;
  path_id?: number | null;
  path_key: string;
  path_name: string;
  action_id?: number | null;
  action_key: string;
  action_name: string;
  name: string;
  description?: string | null;
  scripts_count: number;
  ready: boolean;
  latest_request_status?: string | null;
  latest_request_id?: string | null;
};

export type ScriptRequestItem = {
  id: number;
  request_id: string;
  action_tag_key: string;
  request_type: 'create' | 'fix';
  status: 'pending' | 'developing' | 'published' | 'needs_fix';
  script_id?: string | null;
  script_version?: string | null;
  error_reason?: string | null;
  created_at?: string;
};

export type ActionRegistryInitialData = {
  sites: Site[];
  paths: PathItem[];
  actions: ActionItem[];
  tags: ActionTagItem[];
  requests: ScriptRequestItem[];
};
