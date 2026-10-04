export type ImportJobState = 'queued' | 'processing' | 'completed' | 'failed';

export interface ImportError {
  row_number: number;
  code: string;
  message: string;
  context: Record<string, string> | null;
}

export interface ImportReport {
  total: number;
  imported: number;
  updated: number;
  failed: number;
  duration_seconds: number;
  errors: ImportError[];
}

export interface ImportJob {
  job_id: string;
  state: ImportJobState;
  processed: number;
  imported: number;
  updated: number;
  failed: number;
  created_at: string;
  updated_at: string;
  error: string | null;
  report: ImportReport | null;
}

export const TERMINAL_STATES: ReadonlySet<ImportJobState> = new Set<ImportJobState>([
  'completed',
  'failed',
]);

export const JOB_STATE_LABELS: Record<ImportJobState, string> = {
  queued: 'В очереди',
  processing: 'Обрабатывается',
  completed: 'Завершён',
  failed: 'Ошибка',
};
