import { createActionGroup, emptyProps, props } from '@ngrx/store';

import { ImportJob } from '../../core/models/import-job.model';

export const ImportActions = createActionGroup({
  source: 'Import',
  events: {
    'Upload requested': props<{ file: File }>(),
    'Upload success': props<{ job: ImportJob }>(),
    'Upload failure': props<{ error: string }>(),
    'Poll started': props<{ jobId: string }>(),
    'Poll success': props<{ job: ImportJob }>(),
    'Poll failure': props<{ error: string }>(),
    'Polling stopped': emptyProps(),
    Reset: emptyProps(),
  },
});
