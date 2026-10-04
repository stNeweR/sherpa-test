import { inject } from '@angular/core';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { catchError, exhaustMap, map, of, switchMap, takeWhile, timer } from 'rxjs';

import { TERMINAL_STATES } from '../../core/models/import-job.model';
import { ImportApiService } from '../../core/services/import-api.service';
import { describeError } from '../../core/http/error-message';
import { ImportActions } from './import.actions';

const POLL_INTERVAL_MS = 1500;

export class ImportEffects {
  private readonly actions$ = inject(Actions);
  private readonly api = inject(ImportApiService);

  readonly upload$ = createEffect(() =>
    this.actions$.pipe(
      ofType(ImportActions.uploadRequested),
      exhaustMap(({ file }) =>
        this.api.upload(file).pipe(
          map((job) => ImportActions.uploadSuccess({ job })),
          catchError((error: unknown) =>
            of(ImportActions.uploadFailure({ error: describeError(error) })),
          ),
        ),
      ),
    ),
  );

  readonly startPolling$ = createEffect(() =>
    this.actions$.pipe(
      ofType(ImportActions.uploadSuccess),
      map(({ job }) => ImportActions.pollStarted({ jobId: job.job_id })),
    ),
  );

  readonly poll$ = createEffect(() =>
    this.actions$.pipe(
      ofType(ImportActions.pollStarted),
      switchMap(({ jobId }) =>
        timer(0, POLL_INTERVAL_MS).pipe(
          exhaustMap(() => this.api.status(jobId)),
          map((job) => ImportActions.pollSuccess({ job })),
          takeWhile(({ job }) => !TERMINAL_STATES.has(job.state), true),
          catchError((error: unknown) =>
            of(ImportActions.pollFailure({ error: describeError(error) })),
          ),
        ),
      ),
    ),
  );
}
