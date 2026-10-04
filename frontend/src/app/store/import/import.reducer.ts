import { createFeature, createReducer, on } from '@ngrx/store';

import { ImportJob } from '../../core/models/import-job.model';
import { ImportActions } from './import.actions';

export interface ImportState {
  job: ImportJob | null;
  uploading: boolean;
  polling: boolean;
  error: string | null;
}

export const initialImportState: ImportState = {
  job: null,
  uploading: false,
  polling: false,
  error: null,
};

export const importFeature = createFeature({
  name: 'import',
  reducer: createReducer(
    initialImportState,
    on(ImportActions.uploadRequested, (state) => ({ ...state, uploading: true, error: null })),
    on(ImportActions.uploadSuccess, (state, { job }) => ({
      ...state,
      job,
      uploading: false,
      error: null,
    })),
    on(ImportActions.uploadFailure, (state, { error }) => ({ ...state, uploading: false, error })),
    on(ImportActions.pollStarted, (state, { jobId }) => ({
      ...state,
      polling: true,
      error: null,
      job: state.job?.job_id === jobId ? state.job : { ...emptyJob(jobId) },
    })),
    on(ImportActions.pollSuccess, (state, { job }) => ({
      ...state,
      job,
      polling: false,
      error: null,
    })),
    on(ImportActions.pollFailure, (state, { error }) => ({ ...state, polling: false, error })),
    on(ImportActions.pollingStopped, (state) => ({ ...state, polling: false })),
    on(ImportActions.reset, () => initialImportState),
  ),
});

function emptyJob(jobId: string): ImportJob {
  return {
    job_id: jobId,
    state: 'queued',
    processed: 0,
    imported: 0,
    updated: 0,
    failed: 0,
    created_at: '',
    updated_at: '',
    error: null,
    report: null,
  };
}

export const {
  name: importFeatureKey,
  reducer: importReducer,
  selectJob,
  selectUploading,
  selectPolling,
  selectError,
} = importFeature;
