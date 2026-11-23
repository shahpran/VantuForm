export type FieldWidth = '25' | '33' | '50' | '66' | '75' | '100';

export interface FormFieldConditionRule {
  field: string;
  operator:
    | 'equals'
    | 'not_equals'
    | 'contains'
    | 'not_contains'
    | 'greater_than'
    | 'less_than'
    | 'is_empty'
    | 'is_not_empty';
  value: string;
}

export interface FormFieldConditions {
  action: 'show' | 'hide';
  match: 'all' | 'any';
  rules: FormFieldConditionRule[];
}

export interface FormFieldOption {
  label: string;
  value: string;
  [key: string]: unknown;
}

export interface FormFieldSchema {
  id: string;
  key: string;
  type: string;
  label: string;
  description?: string;
  placeholder?: string;
  default?: string;
  required: boolean;
  width: FieldWidth;
  order?: number;
  rows?: number;
  options?: FormFieldOption[];
  validation?: Record<string, unknown>;
  conditions?: FormFieldConditions | null;
  [key: string]: unknown;
}

export interface FormStepSchema {
  id: string;
  title: string;
  next_button_text: string;
  submit_button_text: string;
  fields: FormFieldSchema[];
  [key: string]: unknown;
}

export interface FormMultiStepConfig {
  enabled: boolean;
  progress_style: 'bar' | 'steps' | 'none';
  show_step_titles: boolean;
  allow_back_navigation: boolean;
  [key: string]: unknown;
}

export interface FormThemeConfig {
  primary_color: string;
  border_radius: string;
  input_padding: string;
  label_position: 'top' | 'left' | 'floating';
  [key: string]: unknown;
}

export interface FormSchemaData {
  schema_version: string;
  multi_step: FormMultiStepConfig;
  steps: FormStepSchema[];
  theme: FormThemeConfig;
  [key: string]: unknown;
}
